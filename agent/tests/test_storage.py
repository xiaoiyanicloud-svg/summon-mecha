"""测试 SQLite Storage 核心持久化功能：Journal、Checkpoints、Installed、Outbox、Leases"""

import os
import shutil
import tempfile
import unittest
from zhjj_agent.storage import Storage


class TestStorage(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()
        self.storage = Storage(self.temp_dir)

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_storage_journal_and_idempotency(self):
        # 1. 首次持久化任务
        ok1 = self.storage.record_task_durable(
            task_id="task_001",
            command_seq=1,
            did="did_robot_1",
            operation=1,
            skill_id="101",
            skill_version_id="201",
            version="1.0.0",
            signed_payload={"task_id": "task_001", "operation": 1},
            raw_command={"task_id": "task_001"}
        )
        self.assertTrue(ok1)

        # 2. 重复记录相同 task_id -> 幂等拒绝（返回 False）
        ok2 = self.storage.record_task_durable(
            task_id="task_001",
            command_seq=1,
            did="did_robot_1",
            operation=1,
            skill_id="101",
            skill_version_id="201",
            version="1.0.0",
            signed_payload={"task_id": "task_001", "operation": 1},
            raw_command={"task_id": "task_001"}
        )
        self.assertFalse(ok2)

        # 3. 查任务
        t = self.storage.get_task("task_001")
        self.assertIsNotNone(t)
        self.assertEqual(t["task_id"], "task_001")
        self.assertEqual(t["command_seq"], 1)
        self.assertEqual(t["status"], "RECEIVED_DURABLE")
        self.assertEqual(t["signed_payload"], {"task_id": "task_001", "operation": 1})

        # 4. 状态推进与检查点
        self.storage.save_checkpoint("task_001", "STAGED", {"staged_dir": "/tmp/staged"})
        self.storage.update_task_status("task_001", "COMMITTED")
        t_updated = self.storage.get_task("task_001")
        self.assertEqual(t_updated["status"], "COMMITTED")

        cp = self.storage.get_latest_checkpoint("task_001")
        self.assertIsNotNone(cp)
        self.assertEqual(cp["stage"], "STAGED")
        self.assertEqual(cp["checkpoint_data"]["staged_dir"], "/tmp/staged")

    def test_storage_installed_skills_and_json_sync(self):
        # 保存安装技能状态
        res = self.storage.save_installed_skill(
            skill_id="101",
            current_version="1.0.0",
            previous_version=None,
            status="installed",
            enabled=True,
            install_path=os.path.join(self.temp_dir, "skills/101/v1.0.0"),
            package_sha256="abc123sha",
            manifest={"schema": "zhaohuan.skill/v2"},
            files=["manifest.json", "main.py"]
        )
        self.assertEqual(res["current_version"], "1.0.0")
        self.assertTrue(res["enabled"])

        # 检查是否同步写出 state/installed.json
        self.assertTrue(os.path.isfile(self.storage.installed_json_path))

        # 升级到 1.1.0
        res2 = self.storage.save_installed_skill(
            skill_id="101",
            current_version="1.1.0",
            previous_version="1.0.0",
            status="updated",
            enabled=True,
            install_path=os.path.join(self.temp_dir, "skills/101/v1.1.0"),
            package_sha256="def456sha"
        )
        self.assertEqual(res2["current_version"], "1.1.0")
        self.assertEqual(res2["previous_version"], "1.0.0")
        self.assertEqual(res2["status"], "updated")

        # 回滚到 1.0.0
        res3 = self.storage.save_installed_skill(
            skill_id="101",
            current_version="1.0.0",
            previous_version="1.1.0",
            status="rolled_back",
            enabled=True,
            install_path=os.path.join(self.temp_dir, "skills/101/v1.0.0"),
            package_sha256="abc123sha"
        )
        self.assertEqual(res3["current_version"], "1.0.0")
        self.assertEqual(res3["status"], "rolled_back")

    def test_storage_outbox_and_leases(self):
        # Outbox 登记
        self.storage.record_event_outbox("evt_01", "task_001", "installing", {"did": "did_1"})
        self.storage.record_event_outbox("evt_02", "task_001", "done", {"did": "did_1"})

        unacked = self.storage.get_unacked_events()
        self.assertEqual(len(unacked), 2)
        self.assertEqual(unacked[0]["event_id"], "evt_01")

        # ACK 掉 evt_01
        self.storage.mark_event_acked("evt_01", {"ack_id": "ack_01"})
        unacked_after = self.storage.get_unacked_events()
        self.assertEqual(len(unacked_after), 1)
        self.assertEqual(unacked_after[0]["event_id"], "evt_02")

        # 资源租约获取与互斥
        acquired1 = self.storage.acquire_execution_lease("exec_1", "101", "motion_control", duration_seconds=60)
        self.assertTrue(acquired1)

        # 冲突租约获取失败
        acquired2 = self.storage.acquire_execution_lease("exec_2", "102", "motion_control", duration_seconds=60)
        self.assertFalse(acquired2)

        # 释放后可以获取
        self.storage.release_execution_lease("exec_1")
        acquired3 = self.storage.acquire_execution_lease("exec_2", "102", "motion_control", duration_seconds=60)
        self.assertTrue(acquired3)


if __name__ == "__main__":
    unittest.main()
