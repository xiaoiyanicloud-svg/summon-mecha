"""A6 · 沙箱接口真实测试（当前可运行进程隔离 + watchdog）。

这些用例真的启动子进程、真的杀进程树、真的截断输出 —— 不是 mock。
OS 级加固（memory / filesystem / network / cgroup / seccomp / AppArmor / Job Object）
本机无法验证，`capabilities()` 必须如实返回 False，见 test_a6_08。
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

from zhjj_agent.sandbox import SandboxLimits, SandboxResult, SubprocessSandboxRunner


def child(code: str):
    return [sys.executable, "-c", code]


class TestSubprocessSandbox(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()
        self.runner = SubprocessSandboxRunner(self.temp_dir)

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_a6_01_超时被杀且不是退出码0(self):
        started = time.monotonic()
        result = self.runner.run(
            child("import time; time.sleep(30)"),
            cwd=self.temp_dir,
            limits=SandboxLimits(timeout_seconds=0.5),
        )
        elapsed = time.monotonic() - started

        self.assertIsInstance(result, SandboxResult)
        self.assertTrue(result.timed_out)
        self.assertEqual(result.kill_reason, "timeout")
        self.assertLess(elapsed, 10, "watchdog 必须在合理时间内杀死进程")
        self.assertNotEqual(result.exit_code, 0)

    def test_a6_02_stdout超限即截断并杀进程(self):
        result = self.runner.run(
            child("import sys\nfor i in range(20000):\n    sys.stdout.write('x' * 100)\nsys.stdout.flush()"),
            cwd=self.temp_dir,
            limits=SandboxLimits(timeout_seconds=20, max_stdout_bytes=4096, max_stderr_bytes=4096),
        )

        self.assertTrue(result.stdout_truncated)
        self.assertEqual(result.kill_reason, "stdout_limit")
        self.assertLessEqual(len(result.stdout), 4096 + 8)  # 允许极小的读取粒度误差

    def test_a6_03_stderr同样受限(self):
        result = self.runner.run(
            child("import sys\nfor i in range(20000):\n    sys.stderr.write('e' * 100)\nsys.stderr.flush()"),
            cwd=self.temp_dir,
            limits=SandboxLimits(timeout_seconds=20, max_stdout_bytes=1024, max_stderr_bytes=2048),
        )

        self.assertTrue(result.stderr_truncated)
        self.assertEqual(result.kill_reason, "stderr_limit")
        self.assertLessEqual(len(result.stderr), 2048 + 8)

    def test_a6_04_环境变量白名单不泄露宿主秘密(self):
        os.environ["ZHJJ_HOST_SECRET_SHOULD_NOT_LEAK"] = "leak-me"
        try:
            result = self.runner.run(
                child("import os, json; print(json.dumps(dict(os.environ)))"),
                cwd=self.temp_dir,
                limits=SandboxLimits(timeout_seconds=10, env={"ALLOWED_FLAG": "1"}),
            )
        finally:
            os.environ.pop("ZHJJ_HOST_SECRET_SHOULD_NOT_LEAK", None)

        env = json.loads(result.stdout)
        self.assertNotIn("ZHJJ_HOST_SECRET_SHOULD_NOT_LEAK", env)
        self.assertEqual(env.get("ALLOWED_FLAG"), "1")
        self.assertEqual(os.path.realpath(env["TMP"]), os.path.realpath(os.path.join(self.temp_dir, "tmp")))

    def test_a6_05_cwd被指定(self):
        result = self.runner.run(
            child("import os; print(os.path.realpath(os.getcwd()))"),
            cwd=self.temp_dir,
            limits=SandboxLimits(timeout_seconds=10),
        )
        self.assertEqual(os.path.realpath(result.stdout.strip()), os.path.realpath(self.temp_dir))

    def test_a6_06_退出码0绝不是物理停止证据(self):
        result = self.runner.run(
            child("print('action finished')"),
            cwd=self.temp_dir,
            limits=SandboxLimits(timeout_seconds=10),
        )

        self.assertEqual(result.exit_code, 0)
        self.assertFalse(result.timed_out)
        # A6.4：即使子进程"成功退出"，也不得被当成动作安全或物理停止
        self.assertFalse(result.physical_stop_confirmed)
        self.assertFalse(result.is_safety_evidence)
        payload = result.to_dict()
        self.assertFalse(payload["physical_stop_confirmed"])
        self.assertFalse(payload["is_safety_evidence"])

    def test_a6_07_杀的是整棵进程树而不是只有父进程(self):
        marker = os.path.join(self.temp_dir, "grandchild_alive.txt")
        code = (
            "import subprocess, sys, time\n"
            "marker = sys.argv[1]\n"
            "child_code = \"import time, pathlib; time.sleep(2.0); pathlib.Path(r'\" + marker + \"').write_text('alive')\"\n"
            "subprocess.Popen([sys.executable, '-c', child_code])\n"
            "time.sleep(30)\n"
        )

        result = self.runner.run(
            [sys.executable, "-c", code, marker],
            cwd=self.temp_dir,
            limits=SandboxLimits(timeout_seconds=1.0),
        )

        self.assertTrue(result.timed_out)
        self.assertEqual(result.kill_reason, "timeout")

        # 等到"如果孙进程还活着就会写标记"的时刻
        time.sleep(3.0)
        self.assertFalse(os.path.exists(marker), "孙进程未被杀死：只杀了父进程")

    def test_a6_08_OS级加固缺失必须如实声明(self):
        caps = self.runner.capabilities()

        self.assertTrue(caps["process_isolation"])
        self.assertTrue(caps["timeout"])
        self.assertTrue(caps["env_allowlist"])

        for name in ("memory_limit", "filesystem_isolation", "network_isolation"):
            self.assertFalse(caps[name], f"{name} 未实现，不得声明为 True")

        for name, value in caps["os_level"].items():
            self.assertFalse(value, f"OS 级加固 {name} 本机未实现")

        self.assertEqual(caps["os_level_hardening"], "NOT_RUN")
        self.assertFalse(caps["physical_stop_evidence"])


if __name__ == "__main__":
    unittest.main()
