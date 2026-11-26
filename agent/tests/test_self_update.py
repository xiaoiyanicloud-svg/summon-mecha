"""设备侧自更新闭环测试（B-13 / §4.22 F1·F3）。

约定：**不 mock 文件系统、不 mock SQLite、不 mock HTTP**。
  · 版本目录/指针落在 `tempfile.mkdtemp()` 的真实目录里；
  · 状态仓是真实 SQLite 文件；
  · 清单与工件由 `http.server.ThreadingHTTPServer` 真实提供；
  · 崩溃/并发用**真实子进程**（`python -c` + kill），不是同进程异常模拟。

覆盖（与卡片 a–f 一一对应）：
  a) 下载中途杀进程 → 重启清理残骸、旧版仍可用
  b) 签名合法但 hash 不符 → FAILED，不切换
  c) ts 过期 / 版本不增 / 旧清单重放 → 拒绝
  d) os.replace(current.json) 抛 OSError → previous 完好、FAILED
  e) 新版连续 2 次自检失败 → 自动回滚且只回滚一次，第 3 次停在 known-good
  f) 活动执行租约 → 拒绝更新；两进程并发 → 一个成功、一个锁超时
"""

import errno
import hashlib
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.parse
import uuid
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest import mock

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from zhjj_agent.client import AgentClient
from zhjj_agent.crypto import sign_data
from zhjj_agent.storage import Storage
from zhjj_agent.update import (
    BootGuard,
    ManifestVerifier,
    PHASE_CHECKING,
    PHASE_FAILED,
    PHASE_ROLLED_BACK,
    UpdateCoordinator,
    UpdateError,
    UpdateStateStore,
    canonical_update_string,
    compare_versions,
    run_self_check,
)

AGENT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DID = "did_selfupdate_test"

_KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
_PRIVATE_KEY_PEM = _KEY.private_bytes(
    serialization.Encoding.PEM,
    serialization.PrivateFormat.TraditionalOpenSSL,
    serialization.NoEncryption(),
).decode("ascii")
_PUBLIC_KEY_PEM = _KEY.public_key().public_bytes(
    serialization.Encoding.PEM,
    serialization.PublicFormat.SubjectPublicKeyInfo,
).decode("ascii")


# ── 本地 HTTP 工件站 ────────────────────────────────────────────────────────

class _ServerState:
    def __init__(self):
        self.manifest = {"update_available": False, "reason": "UP_TO_DATE"}
        self.artifact = b""
        self.chunk_delay = 0.0
        self.chunk_size = 32768
        self.manifest_requests = 0
        self.artifact_requests = 0
        self.base_url = ""


def _make_handler(state: _ServerState):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def do_GET(self):  # noqa: N802
            parsed = urllib.parse.urlparse(self.path)
            if parsed.path == "/api/agent/self-update":
                state.manifest_requests += 1
                body = json.dumps({"success": True, "data": state.manifest}).encode("utf-8")
                self._send(body, "application/json")
                return
            if parsed.path.startswith("/artifact/"):
                state.artifact_requests += 1
                data = state.artifact
                self.send_response(200)
                self.send_header("Content-Type", "application/octet-stream")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                for offset in range(0, len(data), state.chunk_size):
                    self.wfile.write(data[offset:offset + state.chunk_size])
                    self.wfile.flush()
                    if state.chunk_delay:
                        time.sleep(state.chunk_delay)
                return
            self.send_response(404)
            self.send_header("Content-Length", "0")
            self.end_headers()

        def _send(self, body: bytes, content_type: str):
            self.send_response(200)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *args):  # 测试里不污染输出
            return

    return Handler


def _start_server():
    state = _ServerState()

    class _Server(ThreadingHTTPServer):
        daemon_threads = True

        def handle_error(self, request, client_address):
            # 客户端被 kill 时服务线程会撞上 BrokenPipe；测试里不需要这份噪声。
            return

    server = _Server(("127.0.0.1", 0), _make_handler(state))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    state.base_url = "http://127.0.0.1:%d" % server.server_address[1]
    return server, state


# ── 工件与清单 ─────────────────────────────────────────────────────────────

def make_agent_zip(version: str, broken: bool = False, payload_size: int = 0) -> bytes:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
        if broken:
            archive.writestr("zhjj_agent/__init__.py", "raise RuntimeError('broken build')\n")
        else:
            archive.writestr("zhjj_agent/__init__.py", f'__version__ = "{version}"\n')
        archive.writestr("zhjj_agent/constants.py", 'PROTOCOL_V2 = "agent-task/v2"\n')
        if payload_size:
            archive.writestr("zhjj_agent/payload.bin", os.urandom(payload_size))
    return buffer.getvalue()


def make_manifest(state: _ServerState, version: str, ts=None, artifact_hash=None, private_key=_PRIVATE_KEY_PEM):
    digest = artifact_hash or hashlib.sha256(state.artifact).hexdigest()
    ts = int(time.time() if ts is None else ts)
    url = f"{state.base_url}/artifact/{version}.zip"
    signature = sign_data(canonical_update_string(version, url, digest, ts), private_key)
    return {
        "update_available": True,
        "version": version,
        "url": url,
        "hash": digest,
        "signature": signature,
        "ts": ts,
        "canonical_hint": "agent_self_update|version|url|hash|ts",
    }


# ── 安装目录引导 ────────────────────────────────────────────────────────────

def bootstrap_install(install_dir: str, version: str = "0.2.0") -> str:
    """构造一个"已经在跑"的安装：版本目录 + current.json（+ 完整标记）。"""
    version_dir = os.path.join(install_dir, "agent", "versions", version)
    package_dir = os.path.join(version_dir, "zhjj_agent")
    os.makedirs(package_dir, exist_ok=True)
    with open(os.path.join(package_dir, "__init__.py"), "w", encoding="utf-8") as handle:
        handle.write(f'__version__ = "{version}"\n')
    with open(os.path.join(version_dir, ".update-meta.json"), "w", encoding="utf-8") as handle:
        json.dump({"version": version, "artifact_sha256": None, "staged_at": int(time.time())}, handle)

    agent_dir = os.path.join(install_dir, "agent")
    with open(os.path.join(agent_dir, "current.json"), "w", encoding="utf-8") as handle:
        json.dump({"version": version, "path": f"versions/{version}", "artifact_sha256": None}, handle)
    return version_dir


def write_previous(install_dir: str, version: str = "0.2.0") -> None:
    with open(os.path.join(install_dir, "agent", "previous.json"), "w", encoding="utf-8") as handle:
        json.dump({"version": version, "path": f"versions/{version}", "artifact_sha256": None}, handle)


def read_pointer(install_dir: str, name: str):
    path = os.path.join(install_dir, "agent", name)
    if not os.path.isfile(path):
        return None
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


CHILD_RUN_ONCE = r"""
import json, sys
from zhjj_agent.client import AgentClient
from zhjj_agent.storage import Storage
from zhjj_agent.update import UpdateCoordinator

install_dir, server_url, pub_path, lock_timeout = sys.argv[1], sys.argv[2], sys.argv[3], float(sys.argv[4])
with open(pub_path, "r", encoding="utf-8") as handle:
    public_key = handle.read()
coordinator = UpdateCoordinator(
    install_dir,
    AgentClient(server_url, did="did_selfupdate_test"),
    public_key,
    Storage(install_dir),
    lock_timeout=lock_timeout,
)
sys.stdout.write(json.dumps(coordinator.run_once("0.2.0").to_dict()))
"""


class SelfUpdateTestBase(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_selfupdate_")
        self.addCleanup(lambda: shutil.rmtree(self.temp_dir, ignore_errors=True))
        self.server, self.state = _start_server()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.public_key_path = os.path.join(self.temp_dir, "public.pem")
        with open(self.public_key_path, "w", encoding="utf-8") as handle:
            handle.write(_PUBLIC_KEY_PEM)
        self._children = []
        self.addCleanup(self._kill_children)

    def _kill_children(self):
        for proc in self._children:
            if proc.poll() is None:
                proc.kill()
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                pass
            for stream in (proc.stdout, proc.stderr):
                if stream is not None:
                    try:
                        stream.close()
                    except OSError:
                        pass

    def new_install(self, version: str = "0.2.0") -> str:
        install_dir = os.path.join(self.temp_dir, "install-" + uuid.uuid4().hex[:8])
        bootstrap_install(install_dir, version)
        return install_dir

    def coordinator(self, install_dir: str, lock_timeout: float = 30.0) -> UpdateCoordinator:
        return UpdateCoordinator(
            install_dir=install_dir,
            client=AgentClient(self.state.base_url, did=DID),
            public_key_pem=_PUBLIC_KEY_PEM,
            storage=Storage(install_dir),
            lock_timeout=lock_timeout,
        )

    def spawn_child(self, install_dir: str, lock_timeout: float) -> subprocess.Popen:
        env = dict(os.environ)
        env["PYTHONPATH"] = AGENT_ROOT + (os.pathsep + env["PYTHONPATH"] if env.get("PYTHONPATH") else "")
        proc = subprocess.Popen(
            [sys.executable, "-c", CHILD_RUN_ONCE, install_dir, self.state.base_url, self.public_key_path, str(lock_timeout)],
            cwd=AGENT_ROOT,
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        self._children.append(proc)
        return proc

    def download_part(self, install_dir: str, version: str) -> str:
        return os.path.join(install_dir, "agent", "versions", ".downloads", f"{version}.part")


# ── 单元：版本比较 / 清单校验 / 状态仓 ─────────────────────────────────────

class TestUpdateUnits(SelfUpdateTestBase):
    def test_version_compare_mirrors_platform(self):
        self.assertLess(compare_versions("0.9.9", "0.10.0"), 0)
        self.assertEqual(compare_versions("v0.3.0", "0.3.0"), 0)
        self.assertLess(compare_versions("1.0.0-beta", "1.0.0"), 0)
        self.assertGreater(compare_versions("1.2.0", "1.1.9"), 0)

    def test_verifier_rejects_bad_signature_clock_and_downgrade(self):
        verifier = ManifestVerifier(_PUBLIC_KEY_PEM)
        self.state.artifact = make_agent_zip("0.3.0")
        good = make_manifest(self.state, "0.3.0")

        # 签名被篡改
        tampered = dict(good)
        tampered["hash"] = "0" * 64
        with self.assertRaises(UpdateError) as ctx:
            verifier.verify(tampered, "0.2.0", int(time.time()))
        self.assertEqual(ctx.exception.code, "UPDATE_SIGNATURE_INVALID")

        # 时钟偏差 > 300s
        stale = make_manifest(self.state, "0.3.0", ts=int(time.time()) - 1000)
        with self.assertRaises(UpdateError) as ctx:
            verifier.verify(stale, "0.2.0", int(time.time()))
        self.assertEqual(ctx.exception.code, "UPDATE_CLOCK_SKEW")

        # 版本不递增
        equal = make_manifest(self.state, "0.2.0")
        with self.assertRaises(UpdateError) as ctx:
            verifier.verify(equal, "0.2.0", int(time.time()))
        self.assertEqual(ctx.exception.code, "UPDATE_VERSION_NOT_NEWER")

        # 正常清单通过
        verifier.verify(good, "0.2.0", int(time.time()))

    def test_state_store_rejects_illegal_transition(self):
        store = UpdateStateStore(os.path.join(self.temp_dir, "state", "agent.db"))
        store.ensure("0.3.0")
        with self.assertRaises(UpdateError) as ctx:
            store.transition("0.3.0", "DOWNLOADING")
        self.assertEqual(ctx.exception.code, "UPDATE_INVALID_TRANSITION")
        self.assertEqual(store.get("0.3.0")["phase"], "IDLE")

        store.transition("0.3.0", "CHECKING")
        store.transition("0.3.0", "FAILED", error_code="X")
        self.assertEqual(store.get("0.3.0")["phase"], "FAILED")
        # FAILED → CHECKING 允许（同一版本重试）
        store.transition("0.3.0", "CHECKING")
        self.assertEqual(store.get("0.3.0")["attempts"], 2)


# ── a) 下载中途杀进程 ───────────────────────────────────────────────────────

class TestCrashDuringDownload(SelfUpdateTestBase):
    def test_killed_mid_download_leaves_old_version_usable(self):
        install_dir = self.new_install("0.2.0")
        self.state.artifact = make_agent_zip("0.3.0", payload_size=2 * 1024 * 1024)
        self.state.chunk_delay = 0.04
        self.state.manifest = make_manifest(self.state, "0.3.0")

        proc = self.spawn_child(install_dir, lock_timeout=30.0)
        part = self.download_part(install_dir, "0.3.0")

        deadline = time.time() + 20
        while time.time() < deadline:
            if os.path.exists(part) and os.path.getsize(part) > 0:
                break
            if proc.poll() is not None:
                self.fail(f"子进程提前退出: {(proc.stderr.read() or '')[-800:]}")
            time.sleep(0.05)
        else:
            self.fail("下载没有在 20s 内开始写 .part")

        proc.kill()
        proc.wait(timeout=10)

        # 旧版仍可用，指针没动，新版本目录没出现
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")
        self.assertFalse(os.path.isdir(os.path.join(install_dir, "agent", "versions", "0.3.0")))
        self.assertTrue(run_self_check(os.path.join(install_dir, "agent", "versions", "0.2.0"), "0.2.0").passed)

        # 重启：平台不再提供更新；协调器必须先清理残骸，且能拿到锁（被 kill 的进程已释放）
        self.state.manifest = {"update_available": False, "reason": "UP_TO_DATE"}
        outcome = self.coordinator(install_dir).run_once("0.2.0")
        self.assertTrue(outcome.success)
        self.assertFalse(outcome.updated)
        self.assertFalse(os.path.exists(part), "重启后 .part 残骸必须被清理")
        leftovers = [n for n in os.listdir(os.path.join(install_dir, "agent", "versions")) if n.startswith(".staging-")]
        self.assertEqual(leftovers, [])
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")


# ── b) 签名合法但 hash 不符 ────────────────────────────────────────────────

class TestHashMismatch(SelfUpdateTestBase):
    def test_hash_mismatch_fails_without_switch(self):
        install_dir = self.new_install("0.2.0")
        good_artifact = make_agent_zip("0.3.0")
        served_artifact = make_agent_zip("0.3.0", payload_size=128)
        self.assertNotEqual(hashlib.sha256(good_artifact).hexdigest(), hashlib.sha256(served_artifact).hexdigest())

        # 清单签名覆盖的是 good_artifact 的 hash（签名完全合法），但服务端发的是另一份字节
        self.state.artifact = served_artifact
        self.state.manifest = make_manifest(self.state, "0.3.0", artifact_hash=hashlib.sha256(good_artifact).hexdigest())

        outcome = self.coordinator(install_dir).run_once("0.2.0")

        self.assertFalse(outcome.success)
        self.assertEqual(outcome.error_code, "UPDATE_ARTIFACT_HASH_MISMATCH")
        self.assertFalse(outcome.updated)
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")
        self.assertFalse(os.path.isdir(os.path.join(install_dir, "agent", "versions", "0.3.0")))
        self.assertFalse(os.path.exists(self.download_part(install_dir, "0.3.0")))
        store = UpdateStateStore(os.path.join(install_dir, "state", "agent.db"))
        self.assertEqual(store.get("0.3.0")["phase"], PHASE_FAILED)
        self.assertEqual(store.get("0.3.0")["error_code"], "UPDATE_ARTIFACT_HASH_MISMATCH")


# ── c) ts 过期 / 版本不增 / 旧清单重放 ──────────────────────────────────────

class TestManifestRejections(SelfUpdateTestBase):
    def test_expired_ts_and_non_increasing_version(self):
        install_dir = self.new_install("0.2.0")
        self.state.artifact = make_agent_zip("0.3.0")

        self.state.manifest = make_manifest(self.state, "0.3.0", ts=int(time.time()) - 3600)
        outcome = self.coordinator(install_dir).run_once("0.2.0")
        self.assertEqual(outcome.error_code, "UPDATE_CLOCK_SKEW")
        self.assertEqual(outcome.phase, PHASE_CHECKING)

        self.state.manifest = make_manifest(self.state, "0.2.0")
        outcome = self.coordinator(install_dir).run_once("0.2.0")
        self.assertEqual(outcome.error_code, "UPDATE_VERSION_NOT_NEWER")

        self.state.manifest = make_manifest(self.state, "0.1.0")
        outcome = self.coordinator(install_dir).run_once("0.2.0")
        self.assertEqual(outcome.error_code, "UPDATE_VERSION_NOT_NEWER")

        self.assertEqual(self.state.artifact_requests, 0, "清单被拒时不得触发任何下载")
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")

    def test_replayed_older_manifest_is_rejected(self):
        install_dir = self.new_install("0.2.0")
        self.state.artifact = make_agent_zip("0.3.0")

        accepted_ts = int(time.time())
        # 第一次：清单被接受（记录 ts），随后因 hash 不符失败 —— 不影响"已接受清单"事实
        self.state.manifest = make_manifest(
            self.state, "0.3.0", ts=accepted_ts, artifact_hash="f" * 64
        )
        first = self.coordinator(install_dir).run_once("0.2.0")
        self.assertEqual(first.error_code, "UPDATE_ARTIFACT_HASH_MISMATCH")

        # 第二次：同一版本、时间戳更旧（仍在 300s 窗口内，签名合法）→ 重放
        self.state.manifest = make_manifest(self.state, "0.3.0", ts=accepted_ts - 10)
        second = self.coordinator(install_dir).run_once("0.2.0")
        self.assertEqual(second.error_code, "UPDATE_MANIFEST_REPLAY")
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")


# ── d) 指针写入失败 ────────────────────────────────────────────────────────

class TestPointerSwitchFailure(SelfUpdateTestBase):
    def test_os_replace_failure_keeps_previous_and_fails(self):
        install_dir = self.new_install("0.2.0")
        write_previous(install_dir, "0.2.0")
        self.state.artifact = make_agent_zip("0.3.0")
        self.state.manifest = make_manifest(self.state, "0.3.0")

        current_path = os.path.join(install_dir, "agent", "current.json")
        real_replace = os.replace

        def failing_replace(src, dst, *args, **kwargs):
            if os.path.abspath(dst) == os.path.abspath(current_path):
                raise OSError(errno.EIO, "injected replace failure")
            return real_replace(src, dst, *args, **kwargs)

        with mock.patch("zhjj_agent.update.os.replace", side_effect=failing_replace):
            outcome = self.coordinator(install_dir).run_once("0.2.0")

        self.assertFalse(outcome.success)
        self.assertEqual(outcome.error_code, "UPDATE_SWITCH_FAILED")
        self.assertFalse(outcome.updated)
        # previous 完好、current 未动
        self.assertEqual(read_pointer(install_dir, "previous.json")["version"], "0.2.0")
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")
        store = UpdateStateStore(os.path.join(install_dir, "state", "agent.db"))
        self.assertEqual(store.get("0.3.0")["phase"], PHASE_FAILED)
        self.assertEqual(store.get("0.3.0")["error_code"], "UPDATE_SWITCH_FAILED")
        # 版本目录已完整落盘（只差切指针），旧版仍可启动
        self.assertTrue(run_self_check(os.path.join(install_dir, "agent", "versions", "0.2.0"), "0.2.0").passed)


# ── e) 连续 2 次自检失败 → 回滚且只回滚一次 ────────────────────────────────

class TestBootRollback(SelfUpdateTestBase):
    def test_two_failed_boots_roll_back_once_then_stay_on_known_good(self):
        install_dir = self.new_install("0.2.0")
        self.state.artifact = make_agent_zip("0.3.0", broken=True)
        self.state.manifest = make_manifest(self.state, "0.3.0")

        outcome = self.coordinator(install_dir).run_once("0.2.0")
        self.assertTrue(outcome.success, outcome.message)
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.3.0")

        guard = BootGuard(install_dir)

        # 第 1 次启动：自检失败，计 1 次，要求 supervisor 再拉起
        first = guard.evaluate_boot()
        self.assertEqual(first.action, "restart_required")
        self.assertEqual(first.error_code, "UPDATE_HEALTH_FAILED")
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.3.0")

        # 第 2 次启动：自检再失败 → 自动回滚（且只在这里回滚一次）
        second = guard.evaluate_boot()
        self.assertEqual(second.action, "rollback_and_run")
        self.assertTrue(second.rollback_performed)
        self.assertEqual(second.version, "0.2.0")
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")

        store = UpdateStateStore(os.path.join(install_dir, "state", "agent.db"))
        row = store.get("0.3.0")
        self.assertEqual(row["phase"], PHASE_ROLLED_BACK)
        self.assertEqual(row["boot_attempts"], 2)

        # 第 3 次启动：停在 known-good，不再回滚、不再计数
        third = guard.evaluate_boot()
        self.assertEqual(third.action, "run")
        self.assertEqual(third.version, "0.2.0")
        fourth = guard.evaluate_boot()
        self.assertEqual(fourth.action, "run")
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")
        self.assertEqual(UpdateStateStore(os.path.join(install_dir, "state", "agent.db")).get("0.3.0")["boot_attempts"], 2)

        # 防死循环：平台若继续把已被回滚的 0.3.0 当"最新"下发，设备必须拒绝重装，
        # 而不是"再装一次坏版本 → 再回滚"。只能由更高版本打破。
        blocked = self.coordinator(install_dir).run_once("0.2.0")
        self.assertFalse(blocked.success)
        self.assertEqual(blocked.error_code, "UPDATE_VERSION_BLOCKED")
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")


# ── f) 活动租约 / 跨进程并发 ────────────────────────────────────────────────

class TestBlockingAndConcurrency(SelfUpdateTestBase):
    def test_active_execution_lease_blocks_update(self):
        install_dir = self.new_install("0.2.0")
        storage = Storage(install_dir)
        self.assertTrue(storage.acquire_execution_lease("exec_live", "skill_wave", "arm", duration_seconds=120))

        self.state.artifact = make_agent_zip("0.3.0")
        self.state.manifest = make_manifest(self.state, "0.3.0")

        outcome = self.coordinator(install_dir).run_once("0.2.0")

        self.assertFalse(outcome.success)
        self.assertEqual(outcome.error_code, "UPDATE_BLOCKED_ACTIVE_EXECUTION")
        self.assertEqual(self.state.manifest_requests, 0, "被租约挡住时不应发起任何网络请求")
        self.assertEqual(self.state.artifact_requests, 0)
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.2.0")

        storage.release_execution_lease("exec_live")
        outcome = self.coordinator(install_dir).run_once("0.2.0")
        self.assertTrue(outcome.success, outcome.message)
        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.3.0")

    def test_two_processes_one_wins_one_lock_timeout(self):
        install_dir = self.new_install("0.2.0")
        self.state.artifact = make_agent_zip("0.3.0", payload_size=1024 * 1024)
        self.state.chunk_delay = 0.04  # ~1.5s，足以让第二个进程撞上锁
        self.state.manifest = make_manifest(self.state, "0.3.0")

        first = self.spawn_child(install_dir, lock_timeout=60.0)
        part = self.download_part(install_dir, "0.3.0")
        deadline = time.time() + 20
        while time.time() < deadline and not (os.path.exists(part) and os.path.getsize(part) > 0):
            self.assertIsNone(first.poll(), "第一个进程提前退出")
            time.sleep(0.05)
        self.assertTrue(os.path.exists(part), "第一个进程未能开始下载（锁未持有）")

        second = self.spawn_child(install_dir, lock_timeout=0.2)
        second_out, second_err = second.communicate(timeout=30)
        self.assertEqual(second.returncode, 0, second_err)
        second_outcome = json.loads(second_out)
        self.assertEqual(second_outcome["error_code"], "UPDATE_LOCK_TIMEOUT")

        first_out, first_err = first.communicate(timeout=60)
        self.assertEqual(first.returncode, 0, first_err)
        first_outcome = json.loads(first_out)
        self.assertTrue(first_outcome["success"], first_outcome)

        self.assertEqual(read_pointer(install_dir, "current.json")["version"], "0.3.0")


if __name__ == "__main__":
    unittest.main()
