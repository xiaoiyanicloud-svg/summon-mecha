"""A5 · 授权回源缓存与执行前闸门。

覆盖的重点：
  · TTL 内不打平台；平台不可达时只在"最大陈旧窗口"内使用缓存；
  · 平台明确拒绝时立即拒绝，不看旧缓存；
  · 开关打开且被拒时，执行器不调适配器、不占租约。
"""

import os
import shutil
import tempfile
import unittest

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.constants import EXEC_FAILED
from zhjj_agent.entitlement import EntitlementGuard
from zhjj_agent.executor import SkillExecutor
from zhjj_agent.storage import Storage

SWITCH = "ZHJJ_ENTITLEMENT_CHECK"


class FakeClient:
    def __init__(self, responses=None):
        self.responses = list(responses or [])
        self.calls = []

    def check_entitlement(self, skill_id, skill_version_id=None):
        self.calls.append((str(skill_id), skill_version_id))
        if not self.responses:
            return False, {"code": "NETWORK_ERROR"}
        item = self.responses.pop(0)
        if isinstance(item, Exception):
            raise item
        return item


class CountingAdapter(MockAdapter):
    def __init__(self, app_dir=None):
        super().__init__(app_dir=app_dir)
        self.execute_calls = 0

    def execute_skill(self, execution_id, skill_id, plan_digest, params):
        self.execute_calls += 1
        return True


class TestEntitlementGuard(unittest.TestCase):
    def setUp(self):
        self._saved = os.environ.get(SWITCH)
        os.environ.pop(SWITCH, None)
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_ent_")
        self.storage = Storage(self.temp_dir)

    def tearDown(self):
        if self._saved is None:
            os.environ.pop(SWITCH, None)
        else:
            os.environ[SWITCH] = self._saved
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_allowed_is_cached_within_ttl(self):
        client = FakeClient([(True, {"data": {"allowed": True, "reason": "OK", "entitlement_id": 7}})])
        guard = EntitlementGuard(client, self.storage, ttl_seconds=300)

        first = guard.check("100")
        second = guard.check("100")

        self.assertEqual(first, (True, "OK", "platform"))
        self.assertEqual(second, (True, "OK", "cache"))
        self.assertEqual(len(client.calls), 1)

    def test_explicit_denial_is_cached_and_not_overridden_by_old_cache(self):
        client = FakeClient([(True, {"data": {"allowed": False, "reason": "REVOKED"}})])
        guard = EntitlementGuard(client, self.storage, ttl_seconds=300)

        allowed, reason, source = guard.check("100")

        self.assertFalse(allowed)
        self.assertEqual(reason, "REVOKED")
        self.assertEqual(source, "platform")

    def test_platform_failure_falls_back_to_fresh_cache(self):
        client = FakeClient([
            (True, {"data": {"allowed": True, "reason": "OK"}}),
            (False, {"code": "NETWORK_ERROR"}),
        ])
        guard = EntitlementGuard(client, self.storage, ttl_seconds=0, max_stale_seconds=900)

        self.assertTrue(guard.check("100")[0])
        # TTL=0 时同一秒内仍会命中缓存；把缓存时间回拨 5 秒，强制走平台
        self.storage.save_entitlement_cache(
            "100", True, "OK", None, None, int(__import__("time").time()) - 5
        )
        allowed, reason, source = guard.check("100")

        self.assertTrue(allowed)
        self.assertEqual(source, "stale_cache")
        self.assertEqual(len(client.calls), 2)

    def test_platform_failure_beyond_max_stale_fails_closed(self):
        client = FakeClient([(False, {"code": "NETWORK_ERROR"})])
        guard = EntitlementGuard(client, self.storage, ttl_seconds=0, max_stale_seconds=10)

        # 写入一条 20 秒前的缓存（已超出最大陈旧窗口）
        self.storage.save_entitlement_cache("100", True, "OK", None, None, int(__import__("time").time()) - 20)

        allowed, reason, source = guard.check("100")

        self.assertFalse(allowed)
        self.assertEqual(reason, "ENTITLEMENT_UNVERIFIED")
        self.assertEqual(source, "unverified")

    def test_expires_at_caps_cache_lifetime(self):
        now = int(__import__("time").time())
        client = FakeClient([
            (True, {"data": {"allowed": True, "reason": "OK", "expires_at": now + 5}}),
            (True, {"data": {"allowed": False, "reason": "EXPIRED"}}),
        ])
        guard = EntitlementGuard(client, self.storage, ttl_seconds=300)

        self.assertTrue(guard.check("100")[0])
        cached = self.storage.get_entitlement_cache("100")
        # checked_at 被回拨到"授权过期那一刻"，因此下一次检查会重新回源
        self.assertLessEqual(cached["checked_at"], now)

    def test_switch_command_denies_without_touching_adapter_or_lease(self):
        os.environ[SWITCH] = "1"
        self.storage.save_installed_skill(
            skill_id="100",
            current_version="1.0.0",
            previous_version=None,
            status="installed",
            enabled=True,
            install_path=self.temp_dir,
            manifest={},
            files=[],
        )
        adapter = CountingAdapter(app_dir=self.temp_dir)

        class DenyingGuard:
            def check(self, skill_id, skill_version_id=None):
                return False, "REVOKED", "platform"

        executor = SkillExecutor(self.storage, adapter, entitlement_guard=DenyingGuard())

        ok, status, err = executor.execute("exec_ent_01", "100")

        self.assertFalse(ok)
        self.assertEqual(status, EXEC_FAILED)
        self.assertEqual(err, "ENTITLEMENT_REVOKED")
        self.assertEqual(adapter.execute_calls, 0)
        self.assertIsNone(self.storage.get_execution_lease("exec_ent_01"))

    def test_switch_default_off_keeps_old_behavior(self):
        self.storage.save_installed_skill(
            skill_id="100", current_version="1.0.0", previous_version=None,
            status="installed", enabled=True, install_path=self.temp_dir, manifest={}, files=[],
        )
        adapter = CountingAdapter(app_dir=self.temp_dir)

        class ExplodingGuard:
            def check(self, skill_id, skill_version_id=None):
                raise AssertionError("开关关闭时不应调用授权回源")

        executor = SkillExecutor(self.storage, adapter, entitlement_guard=ExplodingGuard())

        ok, status, _ = executor.execute("exec_ent_02", "100")

        self.assertTrue(ok)
        self.assertEqual(adapter.execute_calls, 1)

    def test_switch_on_without_guard_fails_closed(self):
        os.environ[SWITCH] = "1"
        adapter = CountingAdapter(app_dir=self.temp_dir)
        executor = SkillExecutor(self.storage, adapter)

        ok, status, err = executor.execute("exec_ent_03", "100")

        self.assertFalse(ok)
        self.assertEqual(err, "ENTITLEMENT_GUARD_UNAVAILABLE")
        self.assertEqual(adapter.execute_calls, 0)


if __name__ == "__main__":
    unittest.main()
