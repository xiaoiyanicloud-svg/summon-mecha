"""A2.4：stopped_at 必须来自独立观察，禁止 now() 伪造；缺失/伪造来源一律降级 UNKNOWN。"""

import shutil
import tempfile
import unittest

from zhjj_agent.client import AgentClient
from zhjj_agent.storage import Storage
from zhjj_agent.stop_observation import synthetic_observation
from zhjj_agent.task_manager import TaskManager


class _FixedObservationAdapter:
    model_code = "mock"
    is_hardware_verified = False

    def __init__(self, observation):
        self.observation = observation

    def observe_execution(self, execution_id):
        return {
            "status": "SUCCEEDED",
            "is_stopped": True,
            "error_msg": None,
            "observation": self.observation,
        }


class _NoObservationAdapter:
    model_code = "mock"
    is_hardware_verified = False

    def observe_execution(self, execution_id):
        return {"status": "SUCCEEDED", "is_stopped": True, "error_msg": None}


class TestStopObservationTime(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _manager(self, adapter):
        return TaskManager(
            install_dir=self.temp_dir,
            storage=Storage(self.temp_dir),
            client=AgentClient(server_url="https://example.test", did="did_time"),
            adapter=adapter,
            pubkey_pem="",
        )

    def test_stopped_at_comes_from_observation_not_now(self):
        observation = synthetic_observation("exec_fixed")
        observation["observed_at"] = 1700000000

        fact = self._manager(_FixedObservationAdapter(observation))._execution_fact(
            "exec_fixed", "SUCCEEDED", True, True
        )

        self.assertTrue(fact["actual_stopped"])
        self.assertEqual(fact["stopped_at"], 1700000000)
        self.assertEqual(fact["stop_observation"]["observed_at"], 1700000000)
        self.assertEqual(fact["evidence"]["observed_at"], 1700000000)
        self.assertEqual(fact["stop_observation"]["collector_version"], "synthetic-test-only")

    def test_missing_observation_downgrades_terminal_to_unknown(self):
        fact = self._manager(_NoObservationAdapter())._execution_fact(
            "exec_missing", "SUCCEEDED", True, True
        )

        self.assertEqual(fact["observed_status"], "UNKNOWN")
        self.assertFalse(fact["actual_stopped"])
        self.assertIsNone(fact["stopped_at"])
        self.assertEqual(fact["error_code"], "STOP_OBSERVATION_MISSING")
        self.assertIsNone(fact["stop_observation"])

    def test_forbidden_source_type_is_rejected(self):
        observation = synthetic_observation("exec_forbidden", source_type="mock")
        observation["observed_at"] = 1700000000

        fact = self._manager(_FixedObservationAdapter(observation))._execution_fact(
            "exec_forbidden", "SUCCEEDED", True, True
        )

        self.assertEqual(fact["observed_status"], "UNKNOWN")
        self.assertFalse(fact["actual_stopped"])
        self.assertIsNone(fact["stopped_at"])
        self.assertEqual(fact["error_code"], "STOP_OBSERVATION_MISSING")

    def test_bad_quality_is_rejected(self):
        observation = synthetic_observation("exec_bad_quality")
        observation["observed_at"] = 1700000000
        observation["quality"] = "bad"

        fact = self._manager(_FixedObservationAdapter(observation))._execution_fact(
            "exec_bad_quality", "SUCCEEDED", True, True
        )

        self.assertEqual(fact["observed_status"], "UNKNOWN")
        self.assertFalse(fact["actual_stopped"])
        self.assertIsNone(fact["stopped_at"])


if __name__ == "__main__":
    unittest.main()
