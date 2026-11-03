"""测试 SkillExecutor：执行去重、互斥租约、安全取消与物理观测"""

import shutil
import tempfile
import unittest

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.constants import EXEC_CANCELED, EXEC_FAILED, EXEC_RUNNING, EXEC_SUCCEEDED
from zhjj_agent.executor import SkillExecutor
from zhjj_agent.storage import Storage


class TestExecutor(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()
        self.storage = Storage(self.temp_dir)
        self.adapter = MockAdapter()
        self.executor = SkillExecutor(self.storage, self.adapter)

        # 登记一个启用的技能
        self.storage.save_installed_skill(
            skill_id="skill_100",
            current_version="1.0.0",
            previous_version=None,
            status="installed",
            enabled=True,
            install_path="/path/to/v1"
        )
        self.adapter.install("skill_100", "1.0.0", "/path/to/v1", {})

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_execute_and_observe_succeeded(self):
        ok, status, err = self.executor.execute(
            execution_id="exec_001",
            skill_id="skill_100",
            plan_digest="sha256_dummy",
            params={"wave_hand": "right"}
        )
        self.assertTrue(ok)
        self.assertEqual(status, EXEC_RUNNING)

        # 观测完成
        import time
        time.sleep(0.06)
        obs = self.executor.observe("exec_001")
        self.assertEqual(obs["status"], EXEC_SUCCEEDED)
        self.assertTrue(obs["is_stopped"])

        # 完成后租约自动释放，可再次执行
        ok2, _, _ = self.executor.execute("exec_002", "skill_100")
        self.assertTrue(ok2)

    def test_resource_mutex_conflict_blocked(self):
        # 第一次执行获取 motion_control
        ok1, _, _ = self.executor.execute("exec_010", "skill_100", resource_name="motion_control")
        self.assertTrue(ok1)

        # 第二次冲突执行试图获取同个资源 -> 被阻塞拒绝
        ok2, status2, err2 = self.executor.execute("exec_011", "skill_100", resource_name="motion_control")
        self.assertFalse(ok2)
        self.assertEqual(status2, EXEC_FAILED)
        self.assertIn("互斥阻塞", err2)

    def test_safe_cancel_execution(self):
        self.executor.execute("exec_cancel_1", "skill_100")
        ok, status, _ = self.executor.cancel("exec_cancel_1")
        self.assertTrue(ok)
        self.assertEqual(status, EXEC_CANCELED)

        # 取消确认停止后，租约已释放，后续执行可获资源
        ok_next, _, _ = self.executor.execute("exec_cancel_2", "skill_100")
        self.assertTrue(ok_next)

    def test_disabled_skill_cannot_execute(self):
        self.storage.save_installed_skill(
            skill_id="skill_disabled",
            current_version="1.0.0",
            previous_version=None,
            status="disabled",
            enabled=False,
            install_path="/path/to/v1"
        )
        ok, status, err = self.executor.execute("exec_disabled_1", "skill_disabled")
        self.assertFalse(ok)
        self.assertIn("禁用状态", err)


if __name__ == "__main__":
    unittest.main()
