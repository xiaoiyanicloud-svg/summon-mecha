"""T6 · 沙箱接入执行路径（WBS A6 的"接线"部分）。

这里测的不是"沙箱能不能跑"（那是 test_sandbox.py），而是：

  · `ZHJJ_REQUIRE_SANDBOX` 打开且没有 runner 时，执行路径 **fail-closed**：
    不调用适配器、不占租约、返回明确的 SANDBOX_UNAVAILABLE；
  · 提供 runner 时，runner 真的被注入到适配器，结果对象仍然 `is_safety_evidence=False`；
  · 开关默认关闭时，行为与接线前完全一致（回归）；
  · `BaseInstallAdapter.run_local_process()` 在没有 runner 时**抛异常**，
    绝不静默降级成裸 `subprocess`。

诚实边界：`SubprocessSandboxRunner` 只是受控子进程（超时/输出上限/环境白名单/
工作目录/进程树杀死），**没有** seccomp/cgroup/namespace/Job Object。
本文件任何断言都不把它当 OS 级隔离或物理停止证据。
"""

import os
import shutil
import sys
import tempfile
import unittest

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.executor import SkillExecutor, sandbox_required
from zhjj_agent.sandbox import (
    SandboxLimits,
    SandboxUnavailableError,
    SubprocessSandboxRunner,
)
from zhjj_agent.storage import Storage

SWITCH = "ZHJJ_REQUIRE_SANDBOX"


class _CountingAdapter(MockAdapter):
    """记录适配器是否被调用过。"""

    def __init__(self, app_dir=None):
        super().__init__(app_dir=app_dir)
        self.execute_calls = 0

    def execute_skill(self, execution_id, skill_id, plan_digest, params):
        self.execute_calls += 1
        return True


class _SandboxCallingAdapter(_CountingAdapter):
    """真的通过 `run_local_process()` 起一个受控子进程。"""

    def __init__(self, app_dir=None):
        super().__init__(app_dir=app_dir)
        self.sandbox_result = None

    def execute_skill(self, execution_id, skill_id, plan_digest, params):
        self.execute_calls += 1
        self.sandbox_result = self.run_local_process(
            [sys.executable, "-c", "print('wired')"],
            cwd=self.app_dir or os.getcwd(),
            limits=SandboxLimits(timeout_seconds=15),
        )
        return True


class SandboxWiringTestBase(unittest.TestCase):
    def setUp(self):
        self._saved_switch = os.environ.get(SWITCH)
        os.environ.pop(SWITCH, None)
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_wire_")
        self.storage = Storage(self.temp_dir)
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

    def tearDown(self):
        if self._saved_switch is None:
            os.environ.pop(SWITCH, None)
        else:
            os.environ[SWITCH] = self._saved_switch
        shutil.rmtree(self.temp_dir, ignore_errors=True)


class TestSandboxWiring(SandboxWiringTestBase):
    def test_a6_wire_01_开关开且无runner时拒绝执行且适配器零调用不占租约(self):
        os.environ[SWITCH] = "1"
        self.assertTrue(sandbox_required())

        adapter = _CountingAdapter(app_dir=self.temp_dir)
        executor = SkillExecutor(self.storage, adapter)  # 不注入 runner

        ok, status, err = executor.execute("exec_wire_01", "100")

        self.assertFalse(ok)
        self.assertEqual(status, "FAILED")
        self.assertEqual(err, "SANDBOX_UNAVAILABLE")
        self.assertEqual(adapter.execute_calls, 0, "fail-closed 时绝不能调用适配器")
        self.assertIsNone(
            self.storage.get_execution_lease("exec_wire_01"),
            "fail-closed 时绝不能占租约",
        )

    def test_a6_wire_02_开关开且有runner时执行路径拿到runner且结果不是安全证据(self):
        os.environ[SWITCH] = "1"
        runner = SubprocessSandboxRunner(sandbox_root=self.temp_dir)
        adapter = _SandboxCallingAdapter(app_dir=self.temp_dir)
        executor = SkillExecutor(self.storage, adapter, sandbox_runner=runner)

        ok, status, err = executor.execute("exec_wire_02", "100")

        self.assertTrue(ok, f"有 runner 时应正常执行: {status} {err}")
        self.assertEqual(status, "RUNNING")
        self.assertEqual(adapter.execute_calls, 1)
        self.assertIsNotNone(adapter.sandbox_result)
        result = adapter.sandbox_result
        self.assertEqual(result.exit_code, 0)
        self.assertIn("wired", result.stdout)
        # A6.4 不变量：沙箱成功 ≠ 动作安全 ≠ 物理停止
        self.assertFalse(result.is_safety_evidence)
        self.assertFalse(result.physical_stop_confirmed)
        self.assertIn("timeout", result.enforced)

    def test_a6_wire_02b_capabilities如实说明只是进程边界而非OS级隔离(self):
        runner = SubprocessSandboxRunner(sandbox_root=self.temp_dir)
        adapter = _CountingAdapter(app_dir=self.temp_dir)
        adapter.bind_sandbox_runner(runner)

        caps = adapter.capabilities()

        self.assertEqual(caps["sandbox_runner"], "SubprocessSandboxRunner")
        self.assertEqual(caps["isolation_level"], "PROCESS_BOUNDARY_ONLY")
        self.assertFalse(caps["memory_limit"])
        self.assertFalse(caps["filesystem_isolation"])
        self.assertFalse(caps["network_isolation"])
        self.assertEqual(caps["os_level_hardening"], "NOT_RUN")
        self.assertFalse(caps["physical_stop_evidence"])
        for name, value in caps["os_level"].items():
            self.assertFalse(value, f"OS 级加固 {name} 未实现，不得声明为 True")

        # 没有 runner 时也必须如实报告"什么都拦着"
        bare = _CountingAdapter(app_dir=self.temp_dir).capabilities()
        self.assertEqual(bare["process_execution"], "BLOCKED_NO_RUNNER")
        self.assertEqual(bare["enforced"], [])

    def test_a6_wire_03_开关默认关闭时行为与接线前完全一致(self):
        self.assertFalse(sandbox_required(), "默认必须是关闭")

        adapter = _CountingAdapter(app_dir=self.temp_dir)
        executor = SkillExecutor(self.storage, adapter)  # 没有 runner，但开关关着

        ok, status, err = executor.execute("exec_wire_03", "100")

        self.assertTrue(ok)
        self.assertEqual(status, "RUNNING")
        self.assertIsNone(err)
        self.assertEqual(adapter.execute_calls, 1, "开关关闭时适配器照常被调用")

    def test_a6_wire_04_无runner时run_local_process抛明确异常而不是静默降级(self):
        adapter = _CountingAdapter(app_dir=self.temp_dir)

        with self.assertRaises(SandboxUnavailableError) as ctx:
            adapter.run_local_process(
                [sys.executable, "-c", "print('should-not-run')"],
                cwd=self.temp_dir,
            )

        self.assertIn("拒绝启动本地进程", str(ctx.exception))
        # 不能退化成裸 subprocess：异常必须来自我们自己的类型，而不是 Popen 的 OSError
        self.assertIsInstance(ctx.exception, RuntimeError)


if __name__ == "__main__":
    unittest.main()
