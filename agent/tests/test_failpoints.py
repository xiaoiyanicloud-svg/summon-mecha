"""A4.1/A4.2/A4.5：九个具名 failpoint 的真实进程崩溃与重启恢复。

每个用例都：
  1. 起一个**独立子进程**跑真实 TaskManager，在指定边界 `os._exit(86)`（不跑 finally）；
  2. 父进程直接检查磁盘/DB 的"崩溃现场"；
  3. 再起一个独立进程执行 `mark_agent_start + resume_recovery`，检查恢复后**没有谎报、没有重复动作、没有误释放租约**。
"""

import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
import zipfile

from zhjj_agent.failpoints import EXIT_CODE, FAILPOINTS
from zhjj_agent.storage import Storage

AGENT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CHILD = os.path.join(AGENT_ROOT, "tests", "failpoint_child.py")

MODE = {
    "before_intent": "install",
    "after_download": "install",
    "after_unpack": "install",
    "after_verify": "install",
    "after_activate": "install",
    "after_execution_start": "execute",
    "before_outbox": "event",
    "after_send_before_ack": "event",
    "after_restart": "start",
}


class TestFailpoints(unittest.TestCase):
    def _env(self, point=None):
        env = dict(os.environ)
        env["PYTHONPATH"] = AGENT_ROOT
        env.pop("ZHJJ_FAILPOINT", None)
        env.pop("ZHJJ_FAILPOINT_MODE", None)
        if point:
            env["ZHJJ_FAILPOINT"] = point
        return env

    def _run_child(self, mode, install_dir, point=None):
        return subprocess.run(
            [sys.executable, CHILD, mode, install_dir],
            capture_output=True,
            text=True,
            env=self._env(point),
            timeout=120,
        )

    # ── A4.2：SQLite 事务边界（WAL） ─────────────────────────────────────────

    def test_a4_02_sqlite_uses_wal_journal(self):
        temp_dir = tempfile.mkdtemp(prefix="zhjj_a4_wal_")
        try:
            storage = Storage(temp_dir)
            with storage._connection() as conn:
                mode = conn.execute("PRAGMA journal_mode").fetchone()[0]
            self.assertEqual(str(mode).lower(), "wal", "崩溃恢复依赖 WAL；journal_mode 不是 wal 时，断电容错语义会变")
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

    # ── A4.1 / A4.5：九个边界 ────────────────────────────────────────────────

    def test_a4_01_all_failpoints_crash_then_recover_without_lying(self):
        self.assertEqual(len(FAILPOINTS), 9)

        for point in FAILPOINTS:
            with self.subTest(failpoint=point):
                temp_dir = tempfile.mkdtemp(prefix=f"zhjj_a4_{point}_")
                try:
                    crashed = self._run_child(MODE[point], temp_dir, point=point)
                    self.assertEqual(
                        crashed.returncode,
                        EXIT_CODE,
                        f"{point} 未在预期边界崩溃: rc={crashed.returncode}\nstdout={crashed.stdout}\nstderr={crashed.stderr[-2000:]}",
                    )

                    self._assert_crash_scene(point, temp_dir)

                    recovered = self._run_child("recover", temp_dir)
                    self.assertEqual(recovered.returncode, 0, recovered.stderr[-2000:])
                    state = json.loads(recovered.stdout.strip().splitlines()[-1])
                    self._assert_recovered(point, temp_dir, state)
                finally:
                    shutil.rmtree(temp_dir, ignore_errors=True)

    # ── 断言 ─────────────────────────────────────────────────────────────────

    def _assert_crash_scene(self, point, install_dir):
        storage = Storage(install_dir)
        skill_root = os.path.join(install_dir, "skills", "100")
        staging = [n for n in os.listdir(skill_root) if n.startswith(".staging_")] if os.path.isdir(skill_root) else []

        if point == "before_intent":
            self.assertIsNone(storage.get_task("T_A4_FP"), "写意图前崩溃不应留下任务记录")
            return

        if point == "after_download":
            task = storage.get_task("T_A4_FP")
            self.assertIsNotNone(task)
            self.assertEqual(task["status"], "DOWNLOADING")
            self.assertFalse(os.path.isdir(os.path.join(skill_root, "v1.0.0")))
            return

        if point in ("after_unpack", "after_verify"):
            self.assertTrue(staging, f"{point}: 崩溃现场应保留 staging 残骸（A4.5 证据）")
            self.assertFalse(os.path.isdir(os.path.join(skill_root, "v1.0.0")))
            return

        if point == "after_activate":
            self.assertTrue(os.path.isfile(os.path.join(skill_root, ".active.json")))
            # 激活指针已落盘、安装事实尚未写库 —— 这正是"半成功"现场
            self.assertIsNone(storage.get_installed_skill("100"))
            return

        if point == "after_execution_start":
            executions = {e["execution_id"]: e["status"] for e in storage.get_all_executions()}
            self.assertIn(executions.get("exec_a4_fp"), ("ACCEPTED", "RUNNING"))
            self.assertTrue(storage.get_active_leases_for_skill("100"), "执行启动后租约必须在")
            return

        if point == "before_outbox":
            with storage._connection() as conn:
                count = conn.execute("SELECT COUNT(*) FROM outbox").fetchone()[0]
            self.assertEqual(int(count), 0, "before_outbox 崩溃后不应有已落盘的事件")
            return

        if point == "after_send_before_ack":
            with storage._connection() as conn:
                unacked = conn.execute("SELECT COUNT(*) FROM outbox WHERE acked = 0").fetchone()[0]
            self.assertEqual(int(unacked), 1, "发送后/ACK 前崩溃必须留下一条未确认事件")
            return

        if point == "after_restart":
            # 启动自愈本身没有留下残骸；这里只验证 failpoint 可达
            return

    def _assert_recovered(self, point, install_dir, state):
        # 通用：任何终态都不允许在**没有独立观察**的情况下被谎报成 SUCCEEDED
        for execution in state["executions"]:
            self.assertNotEqual(
                execution["status"], "SUCCEEDED",
                f"{point}: 重启后不得把未验证的执行标成 SUCCEEDED",
            )

        if point == "before_intent":
            self.assertEqual(state["tasks"], [])
            return

        if point in ("after_unpack", "after_verify"):
            self.assertGreaterEqual(state["cleaned"], 1, f"{point}: 启动必须清理 staging 残骸")
            skill_root = os.path.join(install_dir, "skills", "100")
            leftovers = [n for n in os.listdir(skill_root) if n.startswith(".staging_")]
            self.assertEqual(leftovers, [])
            archive = os.path.join(install_dir, "state", "audit", "staging_orphans.jsonl")
            self.assertTrue(os.path.isfile(archive), "A4.5：清理前必须归档残骸清单")
            with open(archive, encoding="utf-8") as handle:
                record = json.loads(handle.read().strip().splitlines()[-1])
            self.assertIn("manifest.json", [f["path"] for f in record["files"]])
            self.assertTrue(any(f.get("sha256") for f in record["files"]))
            return

        if point == "after_activate":
            # 指针在、事实库没有：恢复流程不得把它当成"安装成功"
            self.assertIsNotNone(state["active"])
            self.assertEqual(state["active"]["version"], "1.0.0")
            if state["installed"] is not None:
                self.assertNotEqual(state["installed"]["status"], "installed")
            return

        if point == "after_execution_start":
            self.assertTrue(
                any(l["execution_id"] == "exec_a4_fp" for l in state["leases"]),
                "未确认停止的执行，重启后绝不能释放租约",
            )
            self.assertTrue(
                any(e["execution_id"] == "exec_a4_fp" and e["status"] in ("RUNNING", "UNKNOWN") for e in state["executions"]),
                "重启后执行必须收敛为 RUNNING/UNKNOWN，不得凭空成功",
            )
            return

        if point == "before_outbox":
            self.assertEqual(state["unacked"], [], "before_outbox 崩溃时事实确实丢了（这是该边界的已知代价），不应伪造事件")
            return

        if point == "after_send_before_ack":
            self.assertEqual(state["unacked_before_flush"], 1, "重启时必须看到那条未确认事件（崩溃没把它变成已送达）")
            self.assertEqual(state["unacked"], [], "重启后 flush_outbox 应把它补发成功并 ack")
            return

        if point == "after_restart":
            self.assertEqual(state["cleaned"], 0)
            return


if __name__ == "__main__":
    unittest.main()
