"""A1：agent-fact/v3 跨语言黄金向量与端侧信封签名。"""

import json
import os
import shutil
import tempfile
import time
import unittest

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.crypto import (
    canonical_fact_v3,
    fact_key_id,
    sign_data,
    verify_signature,
)
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


def _fixture():
    path = os.path.abspath(
        os.path.join(os.path.dirname(__file__), "..", "..", "qa", "wbs-20261105", "jcs-vectors.json")
    )
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


class TestFactEnvelopeV3(unittest.TestCase):
    def test_cross_language_canonical_and_key_id(self):
        fx = _fixture()

        self.assertEqual(
            canonical_fact_v3(fx["signed"]).decode("utf-8"),
            fx["expected_canonical"],
        )
        self.assertEqual(
            fact_key_id(fx["private_key_pem"]),
            fx["expected_key_id"],
        )

    def test_client_builds_verifiable_envelope(self):
        fx = _fixture()
        client = AgentClient(
            server_url="https://example.test",
            did="did_v3_golden",
            private_key_pem=fx["private_key_pem"],
        )

        payload = {
            "event_id": "evt_v3_py_1",
            "did": "did_v3_golden",
            "task_no": "T-V3-PY",
            "event_seq": 3,
            "phase": "done",
            "evidence": {"observer": "synthetic-test-only"},
            "reported_at": 1700000003,
        }
        fact = {"execution_id": "exec_py", "observed_status": "SUCCEEDED"}

        envelope = client.build_fact_envelope(payload, fact)

        self.assertIsNotNone(envelope)
        self.assertEqual(envelope["protocol"], "agent-fact/v3")
        self.assertEqual(envelope["key_id"], fx["expected_key_id"])

        signed = {k: envelope[k] for k in (
            "protocol", "event_id", "did", "task_no", "event_seq",
            "phase", "execution_fact", "evidence", "reported_at",
        )}

        self.assertTrue(
            verify_signature(canonical_fact_v3(signed), envelope["signature"], fx["public_key_pem"])
        )

        # 正文被替换：签名不再成立
        signed["phase"] = "failed"
        self.assertFalse(
            verify_signature(canonical_fact_v3(signed), envelope["signature"], fx["public_key_pem"])
        )

    def test_no_private_key_means_no_envelope(self):
        client = AgentClient(server_url="https://example.test", did="did_legacy", agent_key="shared")
        self.assertIsNone(client.build_fact_envelope({"event_id": "e"}, None))

    def test_emit_event_persists_signed_envelope_in_outbox(self):
        fx = _fixture()
        tmp = tempfile.mkdtemp()

        try:
            storage = Storage(tmp)
            client = AgentClient(
                server_url="https://example.test",
                did="did_v3_outbox",
                private_key_pem=fx["private_key_pem"],
            )
            # 模拟断网：事件必须留在 outbox，且已带签名信封。
            client.send_event = lambda **kwargs: (False, {"code": "NETWORK_DOWN"})

            manager = TaskManager(
                install_dir=tmp,
                storage=storage,
                client=client,
                adapter=MockAdapter(),
                pubkey_pem=fx["public_key_pem"],
            )

            # 走真实执行路径生成 fact（含独立观察时间），而不是手工拼裸 fact。
            manager.adapter.install("s_outbox", "1.0.0", tmp, {})
            manager.adapter.execute_skill("exec_outbox", "s_outbox", None, {})
            time.sleep(0.08)
            fact = manager._execution_fact("exec_outbox", "SUCCEEDED", True, True)

            manager.emit_event("T_OUTBOX", "done", execution_fact=fact)

            events = storage.get_unacked_events()
            self.assertEqual(len(events), 1)

            payload = events[0]["payload"]
            self.assertIn("fact_envelope", payload)

            envelope = payload["fact_envelope"]
            self.assertEqual(envelope["protocol"], "agent-fact/v3")
            self.assertEqual(envelope["event_id"], payload["event_id"])
            self.assertEqual(envelope["execution_fact"]["execution_id"], "exec_outbox")
            self.assertEqual(envelope["key_id"], fx["expected_key_id"])

            # A2.4：顶层 stop_observation 来自 Mock 的合成观察，stopped_at 与观察时间一致。
            self.assertIn("stop_observation", payload)
            self.assertEqual(payload["stop_observation"]["source_type"], "independent_watchdog")
            self.assertEqual(payload["stopped_at"], payload["stop_observation"]["observed_at"])

            signed = {k: envelope[k] for k in (
                "protocol", "event_id", "did", "task_no", "event_seq",
                "phase", "execution_fact", "evidence", "reported_at",
            )}
            self.assertTrue(
                verify_signature(canonical_fact_v3(signed), envelope["signature"], fx["public_key_pem"])
            )
        finally:
            shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    unittest.main()
