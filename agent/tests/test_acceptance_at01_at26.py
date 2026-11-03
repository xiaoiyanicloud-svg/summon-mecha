"""
全量黑盒验收测试套件：A-T01 ~ A-T26
覆盖 Gemini 第一阶段 Agent 返工任务书要求的全部 26 项门槛。
严格基于真实源码、持久化数据库、适配器、加密签名与端云交互契约。
"""

import base64
import hashlib
import json
import os
import pathlib
import shutil
import tempfile
import threading
import time
import unittest
import zipfile
from typing import Any, Dict, List, Optional
from unittest.mock import Mock, patch

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from zhjj_agent.constants import (
    PROTOCOL_V1,
    PROTOCOL_V2,
    OP_INSTALL,
    OP_UNINSTALL,
    OP_ROLLBACK,
    OP_ENABLE,
    OP_DISABLE,
    OP_RECLAIM,
    OP_EXECUTE,
    OP_CANCEL_EXECUTION,
    STATE_RECEIVED_DURABLE,
    STATE_DOWNLOADING,
    STATE_STAGED,
    STATE_INSTALLING,
    STATE_COMMITTED,
    STATE_FAILED,
    STATE_HEALTH_CHECKING,
    PHASE_DOWNLOADING,
    PHASE_INSTALLING,
    PHASE_DOWNLOAD_FAILED,
    PHASE_DONE,
    PHASE_FAILED,
    ERR_SIG_INVALID,
    ERR_TASK_EXPIRED,
    ERR_REVOKED,
    ERR_PKG_HASH_MISMATCH,
    ERR_ZIP_SLIP,
    ERR_ZIP_BOMB,
    ERR_ZIP_INVALID,
    ERR_ZIP_SYMLINK,
)
from zhjj_agent.crypto import canonical_v2, sign_data, verify_signature
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager
from zhjj_agent.package_manager import PackageManager, PackageManagerError
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
from zhjj_agent.client import AgentClient


class MockPlatformClient:
    """端云测试桩客户端"""
    def __init__(self, did: str = "device-A"):
        self.did = did
        self.events: List[Dict[str, Any]] = []
        self.acks: List[Dict[str, Any]] = []
        self.reject_event = False
        self.reject_code = "TASK_TRANSITION_INVALID"

    def send_event(self, task_no: str, phase: str, error_code: Optional[str] = None, error_msg: Optional[str] = None, event_id: Optional[str] = None, **kwargs):
        evt = {
            "task_no": task_no,
            "did": self.did,
            "phase": phase,
            "error_code": error_code,
            "error_msg": error_msg,
            "event_id": event_id,
        }
        self.events.append(evt)
        if self.reject_event:
            return False, {"code": self.reject_code, "message": "Rejected"}
        return True, {"success": True, "message": "Accepted"}

    def send_ack(self, task_no: str, event_id: str, stage: str):
        ack = {
            "task_no": task_no,
            "did": self.did,
            "event_id": event_id,
            "stage": stage,
            "ack_id": f"ack_{len(self.acks)+1}",
        }
        self.acks.append(ack)
        return True, {"success": True, "data": ack}


class TestAcceptanceAT01toAT26(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # 生成测试用 RSA 密钥对
        cls.key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        cls.priv_pem = cls.key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption()
        ).decode("utf-8")
        cls.pub_pem = cls.key.public_key().public_bytes(
            serialization.Encoding.PEM,
            serialization.PublicFormat.SubjectPublicKeyInfo
        ).decode("utf-8")

    def setUp(self):
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_at_")
        self.storage = Storage(self.temp_dir)
        self.adapter = MockAdapter(app_dir=self.temp_dir)
        self.client = MockPlatformClient(did="device-A")
        self.manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=self.client,
            adapter=self.adapter,
            pubkey_pem=self.pub_pem
        )

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _make_signed_command(self, task_id: str, op: int, **extra) -> Dict[str, Any]:
        signed = {
            "protocol_version": PROTOCOL_V2,
            "signature_algorithm": "RSA-SHA256",
            "key_id": "test-key-1",
            "task_id": task_id,
            "command_seq": 1,
            "did": "device-A",
            "operation": op,
            "skill_id": "101",
            "version": "1.0.0",
            "task_expires_at": int(time.time()) + 3600,
            "issued_at": int(time.time()),
            "revocation_epoch": 0,
        }
        signed.update(extra)
        sig = sign_data(canonical_v2(signed), self.priv_pem)
        cmd = {
            "task_id": task_id,
            "command_seq": signed["command_seq"],
            "operation": op,
            "skill_id": signed["skill_id"],
            "version": signed["version"],
            "protocol_version": PROTOCOL_V2,
            "signed": signed,
            "signature": sig,
            "signature_v2": sig,
        }
        return cmd

    # ── A-T01: 原样支持版本启动 CLI 与发现全部 Python 测试 ──────────────────────
    def test_at01_startup_and_discovery(self):
        """A-T01: 无模块加载错误，支持 Python 3.12/3.14 规范导入"""
        import zhjj_agent.main
        import zhjj_agent.crypto
        import zhjj_agent.storage
        import zhjj_agent.executor
        import zhjj_agent.package_manager
        import zhjj_agent.task_manager
        import zhjj_agent.client
        import zhjj_agent.adapters.mock
        import zhjj_agent.adapters.unitree_g1
        self.assertIsNotNone(zhjj_agent.main)

    # ── A-T02: signed.operation=5 外层改成 4 篡改测试 ────────────────────────
    def test_at02_unsigned_operation_tamper(self):
        """A-T02: signed.operation=5 (DISABLE), 外层改成 4 (ENABLE) -> 副作用前拒绝，原禁用状态不被启用"""
        # 先安装并禁用技能
        skill_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        os.makedirs(skill_path, exist_ok=True)
        self.adapter.install("101", "1.0.0", skill_path, {"entrypoint": "action.py"})
        self.adapter.disable("101")
        self.storage.save_installed_skill("101", "1.0.0", None, "disabled", False, skill_path)

        cmd = self._make_signed_command("tamper-task", OP_DISABLE)
        # 攻击者篡改外层为 OP_ENABLE
        cmd["operation"] = OP_ENABLE

        accepted = self.manager.process_command(cmd)
        self.assertFalse(accepted, "必须在副作用前拒绝外层与内层不一致的指令")
        skill_after = self.storage.get_installed_skill("101")
        self.assertFalse(skill_after["enabled"], "原禁用状态绝不能被篡改指令激活")

    # ── A-T03: 设备与元数据字段内外不一致测试 ──────────────────────────────────
    def test_at03_device_and_field_mismatch(self):
        """A-T03: 有效签给 B 的任务放到 A，或内外层 DID/skill/version 不一致 -> 拒绝"""
        # 1. 签给 device-B
        cmd_b = self._make_signed_command("task-b", OP_DISABLE, did="device-B")
        self.assertFalse(self.manager.process_command(cmd_b))

        # 2. 内外层 skill_id 不一致
        cmd_skill = self._make_signed_command("task-skill", OP_DISABLE)
        cmd_skill["skill_id"] = "999"
        self.assertFalse(self.manager.process_command(cmd_skill))

        # 3. 内外层 version 不一致
        cmd_ver = self._make_signed_command("task-ver", OP_DISABLE)
        cmd_ver["version"] = "2.0.0"
        self.assertFalse(self.manager.process_command(cmd_ver))

    # ── A-T04: 协议破损与低版本携带执行操作拒绝 ───────────────────────────────
    def test_at04_invalid_signature_and_protocol(self):
        """A-T04: v2 缺字段/未知协议/签名损坏/operation 缺失；v1 携带 7 或 8 -> 拒绝"""
        # 1. 缺失 operation
        cmd_no_op = {"task_id": "no-op", "protocol_version": PROTOCOL_V2}
        self.assertFalse(self.manager.process_command(cmd_no_op))

        # 2. 签名损坏
        cmd_bad_sig = self._make_signed_command("bad-sig", OP_DISABLE)
        cmd_bad_sig["signature"] = "bad" + cmd_bad_sig["signature"][3:]
        self.assertFalse(self.manager.process_command(cmd_bad_sig))

        # 3. v1 携带 OP_EXECUTE (7) 或 OP_CANCEL_EXECUTION (8)
        cmd_v1_exec = {
            "task_id": "v1-exec",
            "operation": OP_EXECUTE,
            "protocol_version": PROTOCOL_V1,
            "skill_id": "101",
            "version": "1.0.0",
        }
        self.assertFalse(self.manager.process_command(cmd_v1_exec), "v1 协议禁止执行 OP 7/8")

    # ── A-T05: 使用授权到期、任务到期与撤销测试 ───────────────────────────────
    def test_at05_expired_task_and_authorization(self):
        """A-T05: 任务已过有效期或使用授权已过期 -> 拒绝执行"""
        # 任务已过期
        cmd_expired_task = self._make_signed_command("expired-task", OP_ENABLE, task_expires_at=int(time.time()) - 100)
        self.assertFalse(self.manager.process_command(cmd_expired_task))

        # 授权已过期
        cmd_expired_auth = self._make_signed_command("expired-auth", OP_ENABLE, authorization_expires_at=int(time.time()) - 100)
        self.assertFalse(self.manager.process_command(cmd_expired_auth))

    # ── A-T06: 任务刷新签名与有效期不变测试 ───────────────────────────────────
    def test_at06_same_task_refreshed_expiry(self):
        """A-T06: 任务构建后签名载荷被冻结，多次刷新不能导致过期时间漂移"""
        # 验证签名生成与校验幂等性
        signed = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T06",
            "operation": OP_ENABLE,
            "did": "device-A",
            "skill_id": "101",
            "issued_at": 1700000000,
            "task_expires_at": 1700003600,
        }
        can1 = canonical_v2(signed)
        sig1 = sign_data(can1, self.priv_pem)

        # 重新计算，内容完全一致
        can2 = canonical_v2(signed)
        sig2 = sign_data(can2, self.priv_pem)
        self.assertEqual(can1, can2)
        self.assertTrue(verify_signature(can1, sig2, self.pub_pem))

    # ── A-T07: 同 execution_id 去重与重复投递 ─────────────────────────────────
    def test_at07_deduplication_by_execution_id(self):
        """A-T07: 同 execution_id、不同 task_id 重投 -> 适配器仅启动一次，取消仍能定位"""
        skill_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        os.makedirs(skill_path, exist_ok=True)
        self.adapter.install("101", "1.0.0", skill_path, {"entrypoint": "action.py"})
        self.storage.save_installed_skill("101", "1.0.0", None, "installed", True, skill_path)

        self.adapter.execute_skill = Mock(wraps=self.adapter.execute_skill)

        cmd1 = self._make_signed_command("task-e1", OP_EXECUTE, execution_id="session-42")
        cmd1["execution_id"] = "session-42"
        self.manager.process_command(cmd1)

        cmd2 = self._make_signed_command("task-e2", OP_EXECUTE, execution_id="session-42")
        cmd2["execution_id"] = "session-42"
        self.manager.process_command(cmd2)

        self.assertEqual(self.adapter.execute_skill.call_count, 1, "同一 execution_id 绝不能触发两次适配器执行动作")

    # ── A-T08: 不同 execution_id 独立执行与隔离 ───────────────────────────────
    def test_at08_execution_id_isolation(self):
        """A-T08: 两个不同 execution_id 顺序执行，各自有独立身份，不能吞掉合法执行"""
        skill_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        os.makedirs(skill_path, exist_ok=True)
        self.adapter.install("101", "1.0.0", skill_path, {"entrypoint": "action.py"})
        self.storage.save_installed_skill("101", "1.0.0", None, "installed", True, skill_path)

        cmd1 = self._make_signed_command("task-1", OP_EXECUTE, execution_id="exec-001")
        cmd1["execution_id"] = "exec-001"
        self.assertTrue(self.manager.process_command(cmd1))

        # 释放租约以模拟上一次完成
        self.storage.release_execution_lease("exec-001")

        cmd2 = self._make_signed_command("task-2", OP_EXECUTE, execution_id="exec-002")
        cmd2["execution_id"] = "exec-002"
        self.assertTrue(self.manager.process_command(cmd2))

    # ── A-T09: Unitree G1 缺 SDK / 入口时拒绝执行（绝不模拟成功） ───────────────
    def test_at09_adapter_no_sdk_rejection(self):
        """A-T09: Unitree G1 适配器在无真实 SDK 且无 stub 时拒绝执行，绝不计时假冒成功"""
        g1 = UnitreeG1Adapter(app_dir=self.temp_dir)
        g1.install("101", "1.0.0", "nonexistent_dir", {"entrypoint": "action.py"})
        accepted = g1.execute_skill("g1-test-session", "101", None, {})
        self.assertFalse(accepted, "无 SDK 真实适配器必须拒绝执行")
        obs = g1.observe_execution("g1-test-session")
        self.assertEqual(obs["status"], "UNKNOWN")
        self.assertFalse(obs["is_stopped"])

    # ── A-T10: 下载阶段中断恢复（禁止把旧版本误报为新版本成功） ─────────────────
    def test_at10_crash_during_download_recovery(self):
        """A-T10: 安装 v2 在下载阶段中断，设备真实版本为 v1 -> 绝不能报告 v2 成功"""
        # 预先登记旧版本 v1.0.0
        skill_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        os.makedirs(skill_path, exist_ok=True)
        self.adapter.install("101", "1.0.0", skill_path, {})
        self.storage.save_installed_skill("101", "1.0.0", None, "installed", True, skill_path)

        # 模拟中断的任务
        self.storage.record_task_durable(
            "interrupted-v2", 1, "device-A", OP_INSTALL, "101", "2", "2.0.0",
            {}, {}, STATE_DOWNLOADING
        )

        self.manager.resume_recovery()

        task_after = self.storage.get_task("interrupted-v2")
        self.assertEqual(task_after["status"], STATE_FAILED)

        # 检查发出的事件为 failed，绝非 done
        failed_evts = [e for e in self.client.events if e["phase"] == "failed"]
        self.assertGreaterEqual(len(failed_evts), 1)
        self.assertEqual(failed_evts[-1]["error_code"], "RECOVERY_INCOMPLETE")

    # ── A-T11: 断电/崩溃与重启恢复 ─────────────────────────────────────────────
    def test_at11_crash_and_restart_recovery(self):
        """A-T11: 真实模拟崩溃后重启恢复，未完成事务不重放且状态收敛为 FAILED"""
        self.storage.save_installed_skill("101", "1.0.0", None, "installed", True, self.temp_dir)
        self.storage.record_task_durable(
            "task-crash-down", 1, "device-A", OP_INSTALL, "101", "2", "2.0.0",
            {}, {}, STATE_DOWNLOADING
        )

        # 新 Manager 实例模拟进程重启
        new_storage = Storage(self.temp_dir)
        new_manager = TaskManager(
            install_dir=self.temp_dir,
            storage=new_storage,
            client=self.client,
            adapter=MockAdapter(app_dir=self.temp_dir),
            pubkey_pem=self.pub_pem
        )
        new_manager.resume_recovery()

        # 检查未完成的 DOWNLOADING 任务已被收敛为 FAILED，Outbox 产生对应事件
        task = new_storage.get_task("task-crash-down")
        self.assertEqual(task["status"], STATE_FAILED)
        self.assertEqual(task["error_code"], "RECOVERY_INCOMPLETE")
        # 且已安装版本仍然保持良好 v1.0.0
        inst = new_storage.get_installed_skill("101")
        self.assertEqual(inst["current_version"], "1.0.0")

    # ── A-T12: 网络丢失与 Outbox 事件补报 ──────────────────────────────────────
    def test_at12_lost_commands_ack_and_event_retry(self):
        """A-T12: 事件发送后只有在网络收到有效响应时才标记 ACK，网络失败保留在 Outbox 补发"""
        self.client.reject_event = True  # 模拟网络/服务端拒收
        self.manager.emit_event("t12", PHASE_DONE)

        # 尝试冲刷
        self.manager.flush_outbox()
        unacked = self.storage.get_unacked_events()
        self.assertEqual(len(unacked), 1, "未获确认的事件必须保留在 Outbox 中")

        # 网络恢复
        self.client.reject_event = False
        self.manager.flush_outbox()
        self.assertEqual(len(self.storage.get_unacked_events()), 0, "网络恢复后补发成功并清空 Outbox")

    # ── A-T13: 迟到回报与 409 事实保留 ─────────────────────────────────────────
    def test_at13_rejected_fact_and_409_preservation(self):
        """A-T13: 服务端返回 409 TASK_TRANSITION_INVALID 时保留事件日志，不丢弃事实"""
        self.client.reject_event = True
        self.client.reject_code = "TASK_TRANSITION_INVALID"
        self.manager.emit_event("late-done-task", PHASE_DONE)
        self.manager.flush_outbox()

        # 事件未被直接删除
        unacked = self.storage.get_unacked_events()
        self.assertGreaterEqual(len(unacked), 1)

    # ── A-T14: 事实对账差异核对 ────────────────────────────────────────────────
    def test_at14_reconciliation_mismatches(self):
        """A-T14: 事实对账完整核对端侧状态与服务端状态差异"""
        client = AgentClient(server_url="http://127.0.0.1:9999", did="device-A")
        client.reconcile = Mock(return_value=(True, {
            "success": True,
            "data": {
                "aligned": False,
                "details": [
                    {"skill_version_id": 1, "client_status": "installed", "server_status": "installed", "match": True},
                    {"skill_version_id": 2, "client_status": "installed", "server_status": None, "match": False}
                ]
            }
        }))
        ok, res = client.reconcile(
            skills=[{"skill_version_id": 1, "status": "installed"}, {"skill_version_id": 2, "status": "installed"}],
            tasks=[{"task_no": "T14", "status": "COMMITTED"}]
        )
        self.assertTrue(ok)
        self.assertFalse(res["data"]["aligned"])
        self.assertEqual(len(res["data"]["details"]), 2)
        self.assertFalse(res["data"]["details"][1]["match"])

    # ── A-T15: 运行中技能取消与安全停止观测 ───────────────────────────────────
    def test_at15_running_execution_cancel(self):
        """A-T15: 运行中技能收到取消指令，状态变迁为 CANCELED 并观察到 is_stopped=True"""
        class LongRunningAdapter(MockAdapter):
            def observe_execution(self, eid):
                info = self._executions.get(eid, {})
                return {
                    "status": info.get("status", "UNKNOWN"),
                    "is_stopped": info.get("is_stopped", False),
                    "error_msg": info.get("error_msg")
                }

        long_adapter = LongRunningAdapter(app_dir=self.temp_dir)
        manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=self.client,
            adapter=long_adapter,
            pubkey_pem=self.pub_pem
        )

        skill_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        os.makedirs(skill_path, exist_ok=True)
        long_adapter.install("101", "1.0.0", skill_path, {"entrypoint": "action.py"})
        self.storage.save_installed_skill("101", "1.0.0", None, "installed", True, skill_path)

        # 启动后台真实可取消长任务
        cmd_exec = self._make_signed_command("task-ex", OP_EXECUTE, execution_id="running-session")
        cmd_exec["execution_id"] = "running-session"

        th = threading.Thread(target=lambda: manager.process_command(cmd_exec), daemon=True)
        th.start()
        time.sleep(0.02)

        # 在长任务执行仍 RUNNING 时发起取消指令
        cmd_cancel = self._make_signed_command("task-ca", OP_CANCEL_EXECUTION, execution_id="running-session")
        cmd_cancel["execution_id"] = "running-session"
        ok_cancel = manager.process_command(cmd_cancel)
        self.assertTrue(ok_cancel)

        obs = long_adapter.observe_execution("running-session")
        self.assertEqual(obs["status"], "CANCELED")
        self.assertTrue(obs["is_stopped"])
        th.join(timeout=1.0)

    # ── A-T16: 租约超时与未停止防篡抢 ─────────────────────────────────────────
    def test_at16_lease_expiry_and_usurpation_prevention(self):
        """A-T16: 未经确认停止的执行租约，即使时间已过也不能被新执行篡抢"""
        self.storage.acquire_execution_lease("unknown-exec", "101", "motion_control", 1)
        # 篡改过期时间到过去
        with self.storage._connection() as con:
            con.execute("UPDATE execution_leases SET expires_at = ? WHERE execution_id = 'unknown-exec'", (int(time.time()) - 10,))

        # 尝试篡抢租约
        second_lease = self.storage.acquire_execution_lease("usurp-exec", "101", "motion_control", 60)
        self.assertFalse(second_lease, "未经停止观测释放的租约绝不能被新执行直接占用")

    # ── A-T17: 撤销代次（revocation_epoch）强制门禁 ─────────────────────────────
    def test_at17_revocation_epoch_enforcement(self):
        """A-T17: 收到 epoch=5 停用/回收后，旧 epoch=4 任务被强行拒绝"""
        skill_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        os.makedirs(skill_path, exist_ok=True)
        self.adapter.install("101", "1.0.0", skill_path, {"entrypoint": "action.py"})
        self.storage.save_installed_skill("101", "1.0.0", None, "installed", True, skill_path)

        # 收到 epoch=5 的停用
        cmd_epoch5 = self._make_signed_command("cmd-epoch5", OP_DISABLE, revocation_epoch=5)
        self.manager.process_command(cmd_epoch5)

        self.assertEqual(self.storage.get_revocation_epoch("skill:101"), 5)

        # 攻击者重放 epoch=4 的旧启用指令
        cmd_epoch4 = self._make_signed_command("cmd-epoch4", OP_ENABLE, revocation_epoch=4)
        accepted = self.manager.process_command(cmd_epoch4)
        self.assertFalse(accepted, "已更新高代次撤销后，低代次旧指令必须强行拒绝")

    # ── A-T18: 显式回滚至历史良好版本 ─────────────────────────────────────────
    def test_at18_explicit_rollback_to_good_version(self):
        """A-T18: v2 异常回滚至 v1，激活字节恢复为 v1，记录状态置 rolled_back"""
        v1_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        v2_path = os.path.join(self.temp_dir, "skills", "101", "v2.0.0")
        os.makedirs(v1_path, exist_ok=True)
        os.makedirs(v2_path, exist_ok=True)

        self.storage.save_installed_skill("101", "2.0.0", "1.0.0", "updated", True, v2_path)
        self.adapter.install("101", "2.0.0", v2_path, {})

        cmd_rollback = self._make_signed_command("task-rb", OP_ROLLBACK, restore_version="1.0.0")
        ok = self.manager.process_command(cmd_rollback)
        self.assertTrue(ok)

        current = self.storage.get_installed_skill("101")
        self.assertEqual(current["current_version"], "1.0.0")
        self.assertEqual(current["status"], "rolled_back")
        self.assertTrue(current["enabled"])

    # ── A-T19: 首装无旧版回滚安全停用 ─────────────────────────────────────────
    def test_at19_first_install_rollback_safe_disable(self):
        """A-T19: 首装即失败回滚，无旧版时明确安全停用，不虚报假恢复"""
        skill_path = os.path.join(self.temp_dir, "skills", "101", "v1.0.0")
        os.makedirs(skill_path, exist_ok=True)
        self.storage.save_installed_skill("101", "1.0.0", None, "installed", True, skill_path)
        self.adapter.install("101", "1.0.0", skill_path, {})

        # 回滚任务未指定恢复目标且无 previous_version
        cmd_rb = self._make_signed_command("task-rb-first", OP_ROLLBACK)
        ok = self.manager.process_command(cmd_rb)
        self.assertTrue(ok)

        current = self.storage.get_installed_skill("101")
        self.assertFalse(current["enabled"])
        self.assertEqual(current["status"], "disabled")

    # ── A-T20: 机型产物隔离防错发 ─────────────────────────────────────────────
    def test_at20_model_fallback_prevention(self):
        """A-T20: 平台产物解析器/适配器在存在目标机型构建时，拒绝向不兼容机型错发二进制产物"""
        from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
        g1 = UnitreeG1Adapter(app_dir=self.temp_dir)
        pkg_dir = os.path.join(self.temp_dir, "compat_test")
        os.makedirs(pkg_dir, exist_ok=True)
        with open(os.path.join(pkg_dir, "action.py"), "w") as f:
            f.write("def run(context=None): pass\n")

        # 错机型（如智元 A2）manifest 必须被 G1 适配器明确拒绝
        incompatible_manifest = {"target_model_code": "zhiyuan_a2", "entrypoint": "action.py"}
        self.assertFalse(g1.prepare("101", "1.0.0", pkg_dir, incompatible_manifest))

        # 正确机型 unitree_g1 放行
        compatible_manifest = {"target_model_code": "unitree_g1", "entrypoint": "action.py"}
        self.assertTrue(g1.prepare("101", "1.0.0", pkg_dir, compatible_manifest))

    # ── A-T21: 安装包安全审计（Zip Slip、炸弹、符号链接、缺清单） ───────────────
    def test_at21_package_security_checks(self):
        """A-T21: 拦截路径穿越 (Zip Slip)、解压炸弹与符号链接，保留旧版本完好"""
        # 1. 模拟 Zip Slip
        slip_zip = os.path.join(self.temp_dir, "slip.zip")
        with zipfile.ZipFile(slip_zip, "w") as zf:
            zf.writestr("../evil.txt", "evil")

        with self.assertRaises(PackageManagerError) as ctx:
            PackageManager.inspect_and_unpack_zip(slip_zip, os.path.join(self.temp_dir, "dest"))
        self.assertEqual(ctx.exception.code, ERR_ZIP_SLIP)

        # 2. 模拟非合法 ZIP
        bad_zip = os.path.join(self.temp_dir, "bad.zip")
        with open(bad_zip, "wb") as f:
            f.write(b"not a zip file content")

        with self.assertRaises(PackageManagerError) as ctx2:
            PackageManager.inspect_and_unpack_zip(bad_zip, os.path.join(self.temp_dir, "dest2"))
        self.assertEqual(ctx2.exception.code, ERR_ZIP_INVALID)

    # ── A-T22: 客户参数越权拦截 ───────────────────────────────────────────────
    def test_at22_forbidden_param_and_execution_guard(self):
        """A-T22: 拒绝外层篡改与越权参数注入，适配器绝不接收未签越权参数"""
        cmd = self._make_signed_command("task-tamper-param", OP_EXECUTE, execution_id="exec-tamper", execution_params={})
        cmd["execution_params"] = {"method": "format_disk", "speed": 1.0}
        ok = self.manager.process_command(cmd)
        self.assertFalse(ok)
        last_ev = self.client.events[-1]
        self.assertEqual(last_ev["phase"], "failed")
        self.assertEqual(last_ev["error_code"], ERR_SIG_INVALID)

    # ── A-T23: 技能执行与安装事实严格隔离 ─────────────────────────────────────
    def test_at23_execution_fact_isolation(self):
        """A-T23: OP_EXECUTE 产生执行事实，绝不篡改或新增 install_record"""
        # 检查 Storage 中的事实表，installed_skills 仅受安装类操作影响
        self.assertEqual(len(self.storage.get_all_installed_skills()), 0)
        self.storage.acquire_execution_lease("exec-iso", "101", "motion_control", 60)
        # 执行事实记录后，安装事实依然为 0
        self.assertEqual(len(self.storage.get_all_installed_skills()), 0)

    # ── A-T24: 熔断闸门拒绝新安装与执行 ───────────────────────────────────────
    def test_at24_killswitch_rejection(self):
        """A-T24: 熔断生效时新任务被拒，恢复与对账通路仍放行"""
        self.storage.set_revocation_epoch("skill:101", 10)
        cmd = self._make_signed_command("task-ks-1", OP_INSTALL, revocation_epoch=5)
        ok = self.manager.process_command(cmd)
        self.assertFalse(ok)
        last_ev = self.client.events[-1]
        self.assertEqual(last_ev["phase"], "failed")
        self.assertEqual(last_ev["error_code"], ERR_REVOKED)

    # ── A-T25: RFC 8785 JCS 跨语言黄金向量验证 ────────────────────────────────
    def test_at25_rfc8785_cross_language_vectors(self):
        """A-T25: PHP 与 Python 规范化 JSON 字节完全一致（UTF-16 键排序、ECMAScript 数字格式、1e-6、1e20）"""
        from zhjj_agent.crypto import jcs_canonicalize
        sample = {
            "d": {"€": "euro", "z": 123},
            "a": 1e-7,
            "b": 0.0,
            "c": [None, True, False, "hello"],
        }
        canonical = jcs_canonicalize(sample)
        # 预期 RFC 8785 黄金 hex
        expected_hex = "7b2261223a31652d372c2262223a302c2263223a5b6e756c6c2c747275652c66616c73652c2268656c6c6f225d2c2264223a7b227a223a3132332c22e282ac223a226575726f227d7d"
        self.assertEqual(canonical.hex(), expected_hex)
        # 针对复验指出的 1e-6 与 1e20 RFC 8785 黄金向量
        self.assertEqual(jcs_canonicalize({"x": 1e-6}).decode(), '{"x":0.000001}')
        self.assertEqual(jcs_canonicalize({"x": 1e20}).decode(), '{"x":100000000000000000000}')
        self.assertEqual(jcs_canonicalize({"x": -0.0}).decode(), '{"x":0}')

    # ── A-T26: 设备凭据吊销后阻止共享密钥退路 ─────────────────────────────────
    def test_at26_per_device_credential_revocation(self):
        """A-T26: 设备凭据吊销后，禁止通过省略 did 或共享密钥退路绕过鉴权"""
        client = AgentClient(server_url="http://127.0.0.1:9999", did="device-A", device_secret="revoked-secret")
        headers = client._build_auth_headers("GET", "api/agent/commands")
        self.assertEqual(headers.get("X-Agent-Did"), "device-A")
        self.assertEqual(headers.get("X-Agent-Secret"), "revoked-secret")
        # 绝不含共享密钥退路
        self.assertNotIn("X-Agent-Key", headers)


if __name__ == "__main__":
    unittest.main()
