"""A4 · 设备侧目标状态对齐（只读、绝不自动装卸）。"""

import shutil
import tempfile
import unittest

from zhjj_agent.desired_state import DesiredStateTracker
from zhjj_agent.storage import Storage


class FakeClient:
    def __init__(self, response):
        self.response = response
        self.calls = 0

    def fetch_desired_state(self):
        self.calls += 1
        return self.response


class TestDesiredStateTracker(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_desired_")
        self.storage = Storage(self.temp_dir)

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _install(self, skill_id, version):
        self.storage.save_installed_skill(
            skill_id=skill_id,
            current_version=version,
            previous_version=None,
            status="installed",
            enabled=True,
            install_path=self.temp_dir,
            manifest={},
            files=[],
        )

    def test_computes_missing_mismatch_and_extra(self):
        self._install("100", "1.0.0")   # 版本落后
        self._install("200", "2.0.0")   # 本地多出来的

        client = FakeClient((True, {"data": {
            "generation": "gen-1",
            "desired": [
                {"skill_id": 100, "version": "1.1.0"},
                {"skill_id": 300, "version": "3.0.0"},
            ],
        }}))
        logs = []
        tracker = DesiredStateTracker(client, self.storage, logger=lambda *a, **k: logs.append((a, k)))

        snapshot = tracker.refresh()

        self.assertEqual(snapshot["generation"], "gen-1")
        self.assertEqual([m["skill_id"] for m in snapshot["missing"]], ["300"])
        self.assertEqual([m["skill_id"] for m in snapshot["version_mismatch"]], ["100"])
        self.assertEqual([m["skill_id"] for m in snapshot["extra"]], ["200"])
        self.assertEqual(len(logs), 1)
        self.assertEqual(logs[0][1]["extra"]["missing_count"], 1)

    def test_platform_failure_returns_none_and_keeps_last(self):
        client = FakeClient((True, {"data": {"generation": "g1", "desired": []}}))
        tracker = DesiredStateTracker(client, self.storage)
        self.assertIsNotNone(tracker.refresh())

        client.response = (False, {"code": "NETWORK_ERROR"})
        self.assertIsNone(tracker.refresh())
        self.assertEqual(tracker.last["generation"], "g1")

    def test_malformed_desired_is_ignored(self):
        client = FakeClient((True, {"data": {"generation": "g", "desired": "not-a-list"}}))
        tracker = DesiredStateTracker(client, self.storage)

        self.assertIsNone(tracker.refresh())

    def test_tracker_never_mutates_local_installs(self):
        self._install("100", "1.0.0")
        client = FakeClient((True, {"data": {"generation": "g", "desired": []}}))
        tracker = DesiredStateTracker(client, self.storage)

        snapshot = tracker.refresh()

        self.assertEqual([m["skill_id"] for m in snapshot["extra"]], ["100"])
        # extra 只是报告：本地记录必须原样保留
        self.assertIsNotNone(self.storage.get_installed_skill("100"))


if __name__ == "__main__":
    unittest.main()
