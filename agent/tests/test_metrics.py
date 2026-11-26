"""A3/A4：设备端指标计数器与启动自愈。"""

import json
import os
import shutil
import tempfile
import time
import unittest
from typing import Any, Dict, Optional, Tuple

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.constants import ERR_SIG_INVALID, OP_INSTALL, PHASE_FAILED, PROTOCOL_V2
from zhjj_agent.crypto import canonical_v2, sign_data
from zhjj_agent.metrics import (
    AGENT_GAUGE_KEYS,
    AGENT_METRIC_KEYS,
    ALL_METRIC_KEYS,
    PLATFORM_METRIC_KEYS,
    AgentMetrics,
    MetricKeyError,
)
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


class _StubClient(AgentClient):
    """最小 HTTP 替身：不联网，可切换"发送失败"。"""

    def __init__(self, did: str = "did_a3_bot"):
        super().__init__("http://127.0.0.1:9", did=did)
        self.send_ok = True
        self.sent = []

    def send_event(self, task_no, phase, error_code=None, error_msg=None, event_id=None, **kwargs):
        if not self.send_ok:
            return False, {"code": "NETWORK_ERROR", "message": "模拟断网"}
        self.sent.append({"task_no": task_no, "phase": phase, "event_id": event_id})
        return True, {"success": True, "data": {}}

    def fetch_commands(self):
        return True, {"data": []}


class MetricsBase(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_a3_")
        self.storage = Storage(self.temp_dir)
        self.metrics = AgentMetrics(self.storage.db_path)

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)


class TestMetricRegistry(MetricsBase):
    def test_key_set_is_closed_at_twelve(self):
        self.assertEqual(len(ALL_METRIC_KEYS), 12)
        self.assertEqual(len(set(ALL_METRIC_KEYS)), 12)
        self.assertEqual(len(AGENT_METRIC_KEYS), 8)
        self.assertEqual(len(PLATFORM_METRIC_KEYS), 4)
        self.assertEqual(list(AGENT_METRIC_KEYS) + list(PLATFORM_METRIC_KEYS), list(ALL_METRIC_KEYS))
        # WBS A3.2 的字面项必须在闭合键集里（execution_unknown_total 是平台快照派生，不是计数器）
        for key in (
            "command_received_total", "command_duplicate_total", "event_conflict_total",
            "package_hash_mismatch_total", "agent_restart_total", "staging_orphan_total",
        ):
            self.assertIn(key, ALL_METRIC_KEYS)
        self.assertIn("outbox_pending_age_seconds", AGENT_GAUGE_KEYS)

    def test_unknown_key_is_rejected(self):
        with self.assertRaises(MetricKeyError):
            self.metrics.inc("device_x_events_total")

    def test_platform_key_cannot_be_incremented_by_agent(self):
        with self.assertRaises(MetricKeyError):
            self.metrics.inc("idempotency_hit_total")

    def test_increment_must_be_positive(self):
        for bad in (0, -1):
            with self.assertRaises(MetricKeyError):
                self.metrics.inc("agent_restart_total", bad)

    def test_cumulative_and_pending(self):
        self.metrics.inc("agent_restart_total", 2)
        self.metrics.inc("agent_restart_total", 3)

        self.assertEqual(self.metrics.cumulative("agent_restart_total"), 5)
        self.assertEqual(self.metrics.pending_counters(), {"agent_restart_total": 5})
        # 未累加过的键必须读出 0，而不是抛错或 None
        self.assertEqual(self.metrics.cumulative("staging_orphan_total"), 0)


class TestMetricBatches(MetricsBase):
    def test_batch_is_reused_until_acked(self):
        self.metrics.inc("agent_restart_total", 1)

        first = self.metrics.next_batch()
        second = self.metrics.next_batch()

        self.assertIsNotNone(first)
        self.assertEqual(first[0], second[0], "未 ack 前必须复用同一 batch_id")
        self.assertEqual(first[1], second[1])

    def test_ack_clears_pending_and_is_idempotent(self):
        self.metrics.inc("staging_orphan_total", 3)
        batch_id, payload = self.metrics.next_batch()

        self.assertTrue(self.metrics.ack_batch(batch_id))
        self.assertFalse(self.metrics.ack_batch(batch_id), "同一批只能 ack 一次")
        self.assertEqual(self.metrics.pending_counters(), {})
        # 累计值不因 ack 改变
        self.assertEqual(self.metrics.cumulative("staging_orphan_total"), 3)
        self.assertIsNone(self.metrics.next_batch())

    def test_increments_after_ack_form_a_new_batch(self):
        self.metrics.inc("agent_restart_total", 1)
        batch_id, _ = self.metrics.next_batch()
        self.metrics.ack_batch(batch_id)

        self.metrics.inc("agent_restart_total", 1)
        second = self.metrics.next_batch()

        self.assertIsNotNone(second)
        self.assertNotEqual(second[0], batch_id)
        self.assertEqual(second[1], {"agent_restart_total": 1})

    def test_drop_batch_discards_without_acking(self):
        self.metrics.inc("outbox_flush_failed_total", 1)
        batch_id, _ = self.metrics.next_batch()

        self.metrics.drop_batch(batch_id)

        # 丢弃后仍是 pending（本地事实还在），但可以重新组批
        self.assertEqual(self.metrics.pending_counters(), {"outbox_flush_failed_total": 1})
        again = self.metrics.next_batch()
        self.assertIsNotNone(again)
        self.assertNotEqual(again[0], batch_id)


class TestStartupSelfHealing(MetricsBase):
    def _manager(self) -> TaskManager:
        return TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=_StubClient(),
            adapter=MockAdapter(app_dir=self.temp_dir),
            pubkey_pem="-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----",
            metrics=self.metrics,
        )

    def test_mark_agent_start_counts_restart(self):
        manager = self._manager()
        manager.mark_agent_start()
        manager.mark_agent_start()

        self.assertEqual(self.metrics.cumulative("agent_restart_total"), 2)

    def test_startup_sweeps_and_counts_staging_orphans(self):
        # 模拟上次被 kill -9：staging 目录没机会被 finally 清理
        skill_root = os.path.join(self.temp_dir, "skills", "skill_1")
        os.makedirs(os.path.join(skill_root, ".staging_abc123"), exist_ok=True)
        os.makedirs(os.path.join(skill_root, "v1.0.0"), exist_ok=True)

        manager = self._manager()
        result = manager.mark_agent_start()

        self.assertEqual(result["staging_orphans_cleaned"], 1)
        self.assertEqual(self.metrics.cumulative("staging_orphan_total"), 1)
        self.assertFalse(os.path.exists(os.path.join(skill_root, ".staging_abc123")))
        # 正常版本目录绝不能被扫掉
        self.assertTrue(os.path.isdir(os.path.join(skill_root, "v1.0.0")))

    def test_signature_rejection_is_counted_at_the_single_choke_point(self):
        manager = self._manager()
        manager.emit_event("task_x", PHASE_FAILED, ERR_SIG_INVALID, "签名不匹配")

        self.assertEqual(self.metrics.cumulative("command_signature_failed_total"), 1)

    def test_lease_conflict_is_counted(self):
        self.storage.save_installed_skill(
            skill_id="skill_lease",
            current_version="1.0.0",
            previous_version=None,
            status="installed",
            enabled=True,
            install_path=os.path.join(self.temp_dir, "skills", "skill_lease"),
        )
        self.assertTrue(
            self.storage.acquire_execution_lease("exec_a", "skill_lease", "motion_control", 30)
        )

        manager = self._manager()
        ok, status, message = manager.executor.execute("exec_b", "skill_lease")

        self.assertFalse(ok)
        self.assertIn("互斥阻塞", message or "")
        self.assertEqual(self.metrics.cumulative("task_lease_conflict_total"), 1)

    def test_outbox_flush_failure_is_counted(self):
        client = _StubClient()
        client.send_ok = False
        manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=client,
            adapter=MockAdapter(app_dir=self.temp_dir),
            pubkey_pem="-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----",
            metrics=self.metrics,
        )
        manager.emit_event("task_flush", PHASE_FAILED, "E_TEST", "占位事件")

        manager.flush_outbox()

        self.assertEqual(self.metrics.cumulative("outbox_flush_failed_total"), 1)


class TestCommandAndGaugeCounters(MetricsBase):
    """A3.2 字面项：command_received / command_duplicate / outbox_pending_age_seconds。"""

    def setUp(self):
        super().setUp()
        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        self.priv_pem = key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        ).decode("ascii")
        pub_pem = key.public_key().public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo,
        ).decode("ascii")
        self.manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=_StubClient(),
            adapter=MockAdapter(app_dir=self.temp_dir),
            pubkey_pem=pub_pem,
            metrics=self.metrics,
        )

    def _command(self, task_id: str, seq: int = 1) -> Dict[str, Any]:
        signed = {
            "protocol_version": PROTOCOL_V2,
            "task_id": task_id,
            "command_seq": seq,
            "did": "did_a3_bot",
            "operation": OP_INSTALL,
            "skill_id": "100",
            "version": "1.0.0",
            "task_expires_at": int(time.time()) + 3600,
        }
        return {
            "task_id": task_id,
            "command_seq": seq,
            "operation": OP_INSTALL,
            "skill_id": "100",
            "version": "1.0.0",
            "protocol_version": PROTOCOL_V2,
            "signed": signed,
            "signature": sign_data(canonical_v2(signed), self.priv_pem),
        }

    def test_received_counted_even_when_command_is_rejected(self):
        self.assertFalse(self.manager.process_command({"task_id": "T_A3_RECEIVED"}))
        self.assertEqual(self.metrics.cumulative("command_received_total"), 1)

    def test_duplicate_delivery_is_counted(self):
        self.assertTrue(self.manager.process_command(self._command("T_A3_DUP")))
        self.assertEqual(self.metrics.cumulative("command_duplicate_total"), 0)

        # 同 task_id 再投一次：幂等返回 True，但必须记一次重复
        self.assertTrue(self.manager.process_command(self._command("T_A3_DUP", seq=2)))
        self.assertEqual(self.metrics.cumulative("command_duplicate_total"), 1)
        self.assertEqual(self.metrics.cumulative("command_received_total"), 2)

    def test_outbox_pending_age_gauge_reads_oldest_unacked(self):
        self.assertEqual(self.metrics.outbox_pending_age_seconds(), 0)

        self.storage.record_event_outbox("evt_age", "T_AGE", PHASE_FAILED, {"error_code": "E"}, 0)
        with self.storage._connection() as conn:
            conn.execute(
                "UPDATE outbox SET created_at = ? WHERE event_id = ?",
                (int(time.time()) - 120, "evt_age"),
            )

        self.assertGreaterEqual(self.metrics.outbox_pending_age_seconds(), 120)
        self.assertEqual(
            self.metrics.gauges(),
            {"outbox_pending_age_seconds": self.metrics.outbox_pending_age_seconds()},
        )

        self.storage.mark_event_acked("evt_age")
        self.assertEqual(self.metrics.outbox_pending_age_seconds(), 0)


if __name__ == "__main__":
    unittest.main()
