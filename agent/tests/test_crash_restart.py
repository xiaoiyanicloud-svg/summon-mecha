"""A4 崩溃重启：**真实独立进程**被强杀后，重启能否自愈。

为什么必须用独立进程：同进程内 `try/finally` / mock 一个"崩溃"证明不了任何事 ——
真实崩溃是进程被内核直接抹掉，没有任何栈可展开、没有一个 finally 会执行。
这里的做法是：

  子进程 A：写入"运行中的执行 + 未确认 outbox + 一个 staging 残骸"，然后挂起
  父进程  ：`proc.kill()` 强杀 A（等价 kill -9）
  子进程 B：重新启动，跑真实的 `TaskManager.mark_agent_start()`，打印本地状态

断言的是"重启后的事实"：重启计数 +1、staging 残骸被清理、执行记录**仍在**（没被丢）、
未确认事件**仍未被标记已确认**（还要补发）。
"""

import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import time
import unittest


AGENT_DIR = pathlib.Path(__file__).resolve().parent.parent

CHILD_SCRIPT = r"""
import json, os, sys, time

from zhjj_agent.client import AgentClient
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.metrics import AgentMetrics
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager

install_dir, mode, marker = sys.argv[1], sys.argv[2], sys.argv[3]

storage = Storage(install_dir)
metrics = AgentMetrics(storage.db_path)

if mode == "crash":
    metrics.inc("agent_restart_total", 1)

    # 模拟上次崩溃留下的现场
    staging = os.path.join(install_dir, "skills", "skill_crash", ".staging_deadbeef")
    os.makedirs(staging, exist_ok=True)
    with open(os.path.join(staging, "half-written.bin"), "wb") as f:
        f.write(b"X" * 1024)

    storage.record_execution("exec_crash", "task_crash", "skill_crash", "1.0.0", {}, status="RUNNING")
    storage.record_event_outbox("evt_crash_1", "task_crash", "running", {"phase": "running"})

    with open(marker, "w", encoding="utf-8") as f:
        f.write("ready")

    # 挂起等待被强杀
    time.sleep(120)
    sys.exit(0)

# mode == "restart"：走真实启动自愈路径
manager = TaskManager(
    install_dir=install_dir,
    storage=storage,
    client=AgentClient("http://127.0.0.1:9", did="did_crash_bot"),
    adapter=MockAdapter(app_dir=install_dir),
    pubkey_pem="-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----",
    metrics=metrics,
)
recovery = manager.mark_agent_start()

execution = storage.get_execution("exec_crash")
unacked = storage.get_unacked_events()

print(json.dumps({
    "recovery": recovery,
    "agent_restart_total": metrics.cumulative("agent_restart_total"),
    "staging_orphan_total": metrics.cumulative("staging_orphan_total"),
    "staging_dir_exists": os.path.isdir(os.path.join(install_dir, "skills", "skill_crash", ".staging_deadbeef")),
    "execution_status": (execution or {}).get("status"),
    "execution_id": (execution or {}).get("execution_id"),
    "unacked_event_ids": [e["event_id"] for e in unacked],
}, ensure_ascii=False))
"""


class TestCrashRestart(unittest.TestCase):
    def setUp(self):
        self.install_dir = tempfile.mkdtemp(prefix="zhjj_a4_")
        self.marker = os.path.join(self.install_dir, "ready.marker")

    def tearDown(self):
        shutil.rmtree(self.install_dir, ignore_errors=True)

    def _spawn(self, mode: str) -> subprocess.Popen:
        env = os.environ.copy()
        env["PYTHONPATH"] = str(AGENT_DIR) + os.pathsep + env.get("PYTHONPATH", "")
        return subprocess.Popen(
            [sys.executable, "-c", CHILD_SCRIPT, self.install_dir, mode, self.marker],
            cwd=str(AGENT_DIR),
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )

    def test_kill_then_restart_recovers(self):
        # ── 1. 子进程 A 建立现场并挂起 ─────────────────────────────────────
        child_a = self._spawn("crash")

        deadline = time.time() + 30
        while time.time() < deadline and not os.path.exists(self.marker):
            if child_a.poll() is not None:
                out, err = child_a.communicate()
                self.fail(f"崩溃子进程提前退出: {out}\n{err}")
            time.sleep(0.1)

        self.assertTrue(os.path.exists(self.marker), "子进程 A 未就绪")

        # ── 2. 强杀（不等它自己退出，也没有任何清理机会）───────────────────
        child_a.kill()
        child_a.communicate(timeout=10)
        self.assertNotEqual(child_a.returncode, 0, "进程应当是被强杀的")

        # 现场确实还在：staging 残骸没被清掉（这正是崩溃的样子）
        self.assertTrue(
            os.path.isdir(os.path.join(self.install_dir, "skills", "skill_crash", ".staging_deadbeef"))
        )

        # ── 3. 子进程 B 走真实启动自愈 ─────────────────────────────────────
        child_b = self._spawn("restart")
        out, err = child_b.communicate(timeout=60)
        self.assertEqual(child_b.returncode, 0, f"重启进程失败: {err}")

        result = json.loads([l for l in out.splitlines() if l.strip().startswith("{")][-1])

        # 重启被计数（A 一次 + B 一次）
        self.assertEqual(result["agent_restart_total"], 2)
        # staging 残骸被清理并计数
        self.assertEqual(result["recovery"]["staging_orphans_cleaned"], 1)
        self.assertEqual(result["staging_orphan_total"], 1)
        self.assertFalse(result["staging_dir_exists"])
        # 执行记录没有因为崩溃而丢失
        self.assertEqual(result["execution_id"], "exec_crash")
        self.assertEqual(result["execution_status"], "RUNNING")
        # 未确认事件仍在队列里（要补发，不能被当成"已送达"）
        self.assertEqual(result["unacked_event_ids"], ["evt_crash_1"])


if __name__ == "__main__":
    unittest.main()
