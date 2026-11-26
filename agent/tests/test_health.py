"""A2 · 设备健康采样与心跳载荷。

真实文件树（/proc、/sys 用临时目录模拟），不 mock 采样逻辑本身。
重点不是“能读出数字”，而是：读不到/格式坏/越界时必须给 None，且绝不影响心跳。
"""

import os
import shutil
import tempfile
import unittest

from zhjj_agent.client import AgentClient
from zhjj_agent.health import HealthSampler
from zhjj_agent.metrics import AGENT_METRIC_KEYS


def write(path: str, content: str) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(content)


class FakeMetrics:
    def __init__(self, values):
        self._values = values

    def snapshot(self):
        return dict(self._values)


class TestHealthSampler(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_health_")
        self.proc = os.path.join(self.temp_dir, "proc")
        self.sys = os.path.join(self.temp_dir, "sys")
        self.install_dir = os.path.join(self.temp_dir, "agent")
        os.makedirs(self.install_dir, exist_ok=True)

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _write_full_tree(self):
        write(os.path.join(self.proc, "uptime"), "3600.50 1000.00\n")
        write(
            os.path.join(self.proc, "meminfo"),
            "MemTotal:        1048576 kB\n"
            "MemFree:          200000 kB\n"
            "MemAvailable:     524288 kB\n",
        )
        write(os.path.join(self.proc, "stat"), "cpu  100 0 100 800 0 0 0 0 0 0\n")
        write(os.path.join(self.sys, "class", "power_supply", "BAT0", "capacity"), "87\n")
        write(
            os.path.join(self.sys, "class", "thermal", "thermal_zone0", "temp"),
            "41500\n",
        )
        write(
            os.path.join(self.sys, "class", "thermal", "thermal_zone1", "temp"),
            "39000\n",
        )

    def test_samples_real_values_from_fake_tree(self):
        self._write_full_tree()
        sampler = HealthSampler(
            self.install_dir,
            proc_root=self.proc,
            sys_root=self.sys,
            metrics=FakeMetrics({
                "agent_restart_total": 3,
                "command_signature_failed_total": 2,
                "outbox_flush_failed_total": 0,
            }),
        )

        sample = sampler.sample()

        self.assertEqual(sample["battery_pct"], 87)
        self.assertEqual(sample["temperature_c"], 41.5)  # 取最高温区
        self.assertEqual(sample["memory_used_mb"], 512)
        self.assertEqual(sample["memory_total_mb"], 1024)
        self.assertEqual(sample["uptime_seconds"], 3600)
        self.assertIsNotNone(sample["disk_total_mb"])
        self.assertGreaterEqual(sample["disk_free_mb"], 0)
        # 只带非零错误计数；正常计数（重启/收到指令）不属于错误码趋势
        self.assertEqual(sample["error_counters"], {"command_signature_failed_total": 2})
        self.assertIsInstance(sample["sampled_at"], int)

    def test_cpu_requires_two_samples_and_computes_delta(self):
        write(os.path.join(self.proc, "stat"), "cpu  100 0 100 800 0 0 0 0 0 0\n")
        sampler = HealthSampler(self.install_dir, proc_root=self.proc, sys_root=self.sys)

        # 第一次只建立基线
        self.assertIsNone(sampler._cpu_pct())

        write(os.path.join(self.proc, "stat"), "cpu  160 0 160 840 0 0 0 0 0 0\n")
        # 总增量 160，其中 idle 增量 40 → busy 75%
        self.assertEqual(sampler._cpu_pct(), 75.0)

    def test_missing_or_malformed_sources_return_none_and_never_raise(self):
        write(os.path.join(self.sys, "class", "power_supply", "BAT0", "capacity"), "not-a-number\n")
        write(os.path.join(self.sys, "class", "thermal", "thermal_zone0", "temp"), "999999\n")
        write(os.path.join(self.proc, "meminfo"), "garbage\n")
        write(os.path.join(self.proc, "stat"), "cpu broken\n")

        sampler = HealthSampler(self.install_dir, proc_root=self.proc, sys_root=self.sys)
        sample = sampler.sample()

        self.assertIsNone(sample["battery_pct"])
        self.assertIsNone(sample["temperature_c"])  # 99.9 万毫度越界
        self.assertIsNone(sample["cpu_pct"])
        self.assertIsNone(sample["memory_total_mb"])
        self.assertIsNone(sample["memory_used_mb"])
        # 磁盘与运行时长仍可用（不因其它探针失败而整体失败）
        self.assertIsNotNone(sample["disk_total_mb"])
        self.assertGreaterEqual(sample["uptime_seconds"], 0)

    def test_out_of_range_battery_is_not_reported(self):
        write(os.path.join(self.sys, "class", "power_supply", "BAT0", "capacity"), "180\n")
        sampler = HealthSampler(self.install_dir, proc_root=self.proc, sys_root=self.sys)

        self.assertIsNone(sampler._battery_pct())

    def test_metrics_failure_does_not_break_sampling(self):
        class BrokenMetrics:
            def snapshot(self):
                raise RuntimeError("db is gone")

        sampler = HealthSampler(
            self.install_dir, proc_root=self.proc, sys_root=self.sys, metrics=BrokenMetrics()
        )

        self.assertEqual(sampler.sample()["error_counters"], {})


class CapturingClient(AgentClient):
    def __init__(self):
        super().__init__(server_url="http://127.0.0.1:1", did="did_test")
        self.captured = None

    def request(self, method, endpoint, payload=None, params=None):
        self.captured = payload
        return True, {"success": True}


class TestHeartbeatHealthPayload(unittest.TestCase):
    def test_health_is_attached_when_present(self):
        client = CapturingClient()
        health = {"battery_pct": 50, "sampled_at": 1}

        ok, _ = client.heartbeat("0.4.0", {"model_code": "mock"}, health=health)

        self.assertTrue(ok)
        self.assertEqual(client.captured["health"], health)

    def test_health_is_omitted_when_absent_or_empty(self):
        client = CapturingClient()
        client.heartbeat("0.4.0")

        self.assertNotIn("health", client.captured)

        client.heartbeat("0.4.0", health={})
        self.assertNotIn("health", client.captured)

    def test_contract_metric_keys_are_known(self):
        # 防止 error_counters 过滤逻辑与 metrics 契约漂移
        self.assertIn("agent_restart_total", AGENT_METRIC_KEYS)
        self.assertNotIn("idempotency_hit_total", AGENT_METRIC_KEYS)


if __name__ == "__main__":
    unittest.main()
