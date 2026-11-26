"""A5 · 安装 / 回滚 / 卸载事务测试。

三类断言，缺一不可：
  · 结构：固定版本目录、原子激活指针、非法路径拒绝；
  · 失败语义：首装失败不留残骸、回滚失败恢复旧指针、活动执行未确认停止时拒绝；
  · 并发：两个真实独立进程同时安装同一技能，必须被技能级锁串行化。
"""

import hashlib
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
import zipfile
from typing import Any, Dict, Optional
from unittest import mock

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.constants import (
    ERR_DISK_SPACE,
    ERR_PATH_INVALID,
    ERR_RESTORE_MISMATCH,
    ERR_REVOKED,
    ERR_ROLLBACK_BLOCKED,
    ERR_ZIP_DUPLICATE_PATH,
    ERR_ZIP_INVALID,
    ERR_ZIP_SLIP,
    OP_INSTALL,
    OP_ROLLBACK,
    OP_UNINSTALL,
    PHASE_DONE,
    PHASE_FAILED,
    PROTOCOL_V2,
)
from zhjj_agent.crypto import canonical_v2, sign_data
from zhjj_agent.install_transaction import InstallTransaction, validate_component
from zhjj_agent.package_manager import PackageManager, PackageManagerError
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


class StubClient(AgentClient):
    def __init__(self, did: str = "did_a5_bot"):
        super().__init__("http://127.0.0.1:8000", did=did)
        self.sent_events = []
        self.sent_acks = []

    def fetch_commands(self):
        return True, {"data": []}

    def send_event(self, task_no, phase, error_code=None, error_msg=None, event_id=None, **kwargs):
        self.sent_events.append({"task_no": task_no, "phase": phase, "error_code": error_code, "error_msg": error_msg})
        return True, {"success": True, "data": {"task_no": task_no, "phase": phase}}

    def send_ack(self, task_no, event_id, stage):
        self.sent_acks.append({"task_no": task_no, "event_id": event_id, "stage": stage})
        return True, {"success": True, "data": {"task_no": task_no}}

    def reconcile(self, skills, tasks=None, **kwargs):
        return True, {"success": True, "data": {"aligned": True}}


class FailInstallAdapter(MockAdapter):
    def install(self, skill_id, version, install_path, manifest):
        return False


class FailObserveAdapter(MockAdapter):
    def install(self, skill_id, version, install_path, manifest):
        super().install(skill_id, version, install_path, manifest)
        return True

    def observe(self, skill_id):
        return {"installed": False, "status": "not_installed"}


class FailRollbackAdapter(MockAdapter):
    def rollback(self, skill_id, restore_version, restore_path):
        return False


class NeverStopAdapter(MockAdapter):
    """observe_execution 永远观望不到停止 —— 用来验证"拒绝回滚"而不是"假装停了"。"""

    def observe_execution(self, execution_id):
        return {"status": "RUNNING", "is_stopped": False, "error_msg": None}


def make_skill_zip(path: str, version: str, payload: bytes = b"payload") -> str:
    with zipfile.ZipFile(path, "w") as zf:
        zf.writestr("manifest.json", json.dumps({"version": version, "name": "A5 测试"}))
        zf.writestr("action.py", "# " + version)
        zf.writestr("data/payload.bin", payload)
    return path


def sha_file(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


class TestInstallTransactionUnits(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()
        self.tx = InstallTransaction(self.temp_dir)

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    # ── A5.1 固定版本目录 + 原子激活 ─────────────────────────────────────────

    def test_a5_01_固定版本目录与原子激活指针(self):
        staging = self.tx.new_staging("100")
        with open(os.path.join(staging, "manifest.json"), "w", encoding="utf-8") as handle:
            json.dump({"version": "1.0.0"}, handle)

        target = self.tx.commit(staging, "100", "1.0.0")
        self.assertEqual(target, os.path.join(self.tx.skills_root(), "100", "v1.0.0"))
        self.assertTrue(os.path.isfile(os.path.join(target, "manifest.json")))

        meta = self.tx.read_install_meta(target)
        self.assertIsNotNone(meta)
        self.assertIn("tree_sha256", meta)
        self.assertEqual(meta["tree_sha256"], self.tx.verify_tree(target, meta["tree_sha256"]))

        self.assertIsNone(self.tx.read_active("100"))
        self.tx.activate("100", "1.0.0", package_sha256="deadbeef", tree_sha256=meta["tree_sha256"])
        active = self.tx.read_active("100")
        self.assertEqual(active["version"], "1.0.0")
        self.assertEqual(active["package_sha256"], "deadbeef")

        # 再激活另一个版本：指针整体替换，不留半截 JSON
        self.tx.activate("100", "1.0.0", reason="rollback")
        self.assertEqual(self.tx.read_active("100")["reason"], "rollback")

    def test_a5_02_非法技能号与版本号一律拒绝(self):
        for bad in ("../evil", "a/b", "a\\b", ".hidden", "CON", "com1.txt", "x.", " "):
            with self.assertRaises(PackageManagerError, msg=f"应拒绝 {bad!r}") as ctx:
                validate_component(bad, "skill_id")
            self.assertEqual(ctx.exception.code, ERR_PATH_INVALID)

        with self.assertRaises(PackageManagerError):
            self.tx.version_dir("100", "1.0.0/../../etc")

    def test_a5_03_技能根目录是符号链接时拒绝进入(self):
        skills = self.tx.skills_root()
        os.makedirs(skills, exist_ok=True)
        outside = os.path.join(self.temp_dir, "outside")
        os.makedirs(outside)
        link = os.path.join(skills, "100")
        try:
            os.symlink(outside, link, target_is_directory=True)
        except (OSError, NotImplementedError):
            self.skipTest("当前环境不允许创建目录符号链接（Windows 需开发者模式）")

        with self.assertRaises(PackageManagerError) as ctx:
            self.tx.skill_root("100")
        self.assertEqual(ctx.exception.code, "PATH_ESCAPE")

    # ── A5.2 包内容审计 ──────────────────────────────────────────────────────

    def test_a5_04_重复路径与大小写冲突被拒绝(self):
        for names, label in (
            (["a.txt", "a.txt"], "完全重复"),
            (["A.txt", "a.txt"], "大小写冲突"),
            (["dir", "dir/inner.txt"], "文件与目录同名"),
        ):
            path = os.path.join(self.temp_dir, f"dup_{abs(hash(tuple(names)))}.zip")
            with zipfile.ZipFile(path, "w") as zf:
                for name in names:
                    zf.writestr(name, "x")
            with self.assertRaises(PackageManagerError, msg=label) as ctx:
                PackageManager.inspect_and_unpack_zip(path, os.path.join(self.temp_dir, "out_dup"))
            self.assertEqual(ctx.exception.code, ERR_ZIP_DUPLICATE_PATH, label)

    def test_a5_05_绝对路径与盘符穿越被拒绝(self):
        for name in ("/etc/passwd", "C:/windows/system32/x.txt", "..\\..\\evil.txt", "a/../../evil.txt"):
            path = os.path.join(self.temp_dir, f"slip_{abs(hash(name))}.zip")
            with zipfile.ZipFile(path, "w") as zf:
                zf.writestr(name, "x")
            with self.assertRaises(PackageManagerError, msg=name) as ctx:
                PackageManager.inspect_and_unpack_zip(path, os.path.join(self.temp_dir, "out_slip"))
            self.assertEqual(ctx.exception.code, ERR_ZIP_SLIP, name)

    def test_a5_06_损坏的zip包被识别而不是当成裸文件(self):
        good = make_skill_zip(os.path.join(self.temp_dir, "good.skill"), "1.0.0", b"x" * 4096)
        corrupted = os.path.join(self.temp_dir, "corrupted.skill")
        with open(good, "rb") as src, open(corrupted, "wb") as dst:
            dst.write(src.read()[:200])  # 截断：中央目录丢失，但仍以 PK\x03\x04 开头

        self.assertFalse(zipfile.is_zipfile(corrupted))
        staging = os.path.join(self.temp_dir, "staging_corrupt")
        with self.assertRaises(PackageManagerError) as ctx:
            PackageManager(self.temp_dir).unpack_to_staging(corrupted, staging)
        self.assertEqual(ctx.exception.code, ERR_ZIP_INVALID)

    def test_a5_07_磁盘空间预检失败即拒装(self):
        fake_usage = shutil._ntuple_diskusage(total=100, used=99, free=1) if hasattr(shutil, "_ntuple_diskusage") else None
        if fake_usage is None:
            fake_usage = type("Usage", (), {"total": 100, "used": 99, "free": 1})()
        with mock.patch("shutil.disk_usage", return_value=fake_usage):
            with self.assertRaises(PackageManagerError) as ctx:
                PackageManager.check_disk_space(self.temp_dir, 10 * 1024 * 1024)
        self.assertEqual(ctx.exception.code, ERR_DISK_SPACE)

    def test_a5_08_权限不足翻译成明确错误码(self):
        staging = self.tx.new_staging("100")
        with open(os.path.join(staging, "x.txt"), "w", encoding="utf-8") as handle:
            handle.write("x")

        real_replace = os.replace

        def deny(src, dst):
            raise PermissionError(13, "Permission denied")

        with mock.patch("os.replace", side_effect=deny):
            with self.assertRaises(PackageManagerError) as ctx:
                self.tx.commit(staging, "100", "1.0.0")
        self.assertEqual(ctx.exception.code, "PERMISSION_DENIED")

        # 确认没有留下任何版本目录
        self.assertFalse(os.path.exists(self.tx.version_dir("100", "1.0.0")))
        os.replace = real_replace

    # ── A5.5 并发 ────────────────────────────────────────────────────────────

    def test_a5_09_两个真实进程并发安装同一版本不会互相踩(self):
        package = make_skill_zip(os.path.join(self.temp_dir, "concurrent.skill"), "1.0.0", b"c" * 2048)
        script = (
            "import sys, time\n"
            "from zhjj_agent.install_transaction import InstallTransaction\n"
            "from zhjj_agent.package_manager import PackageManager\n"
            "tx = InstallTransaction(sys.argv[1])\n"
            "with tx.lock('100', timeout=30):\n"
            "    staging = tx.new_staging('100')\n"
            "    PackageManager(sys.argv[1]).inspect_and_unpack_zip(sys.argv[2], staging)\n"
            "    time.sleep(0.3)\n"
            "    tx.commit(staging, '100', '1.0.0')\n"
            "print('done')\n"
        )
        env = dict(os.environ)
        env["PYTHONPATH"] = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

        procs = [
            subprocess.Popen([sys.executable, "-c", script, self.temp_dir, package], stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)
            for _ in range(2)
        ]
        outputs = [proc.communicate(timeout=60) for proc in procs]

        for proc, (out, err) in zip(procs, outputs):
            self.assertEqual(proc.returncode, 0, err.decode("utf-8", errors="replace"))
            self.assertIn(b"done", out)

        target = self.tx.version_dir("100", "1.0.0")
        self.assertTrue(os.path.isfile(os.path.join(target, "manifest.json")))
        self.assertEqual(sha_file(os.path.join(target, "data", "payload.bin")), hashlib.sha256(b"c" * 2048).hexdigest())

        # 不留 staging / backup 残骸
        leftovers = [n for n in os.listdir(os.path.dirname(target)) if n.startswith(".staging_") or ".old-" in n]
        self.assertEqual(leftovers, [])

    def test_a5_18_安装与卸载并发后不会留下半成品(self):
        """两个真实进程：一个安装、一个卸载。最终状态可以"装上"也可以"卸掉"（取决于谁先拿到锁），
        但**不允许**出现"目录在、内容不完整"或锁超时失败的中间态。"""
        package = make_skill_zip(os.path.join(self.temp_dir, "race.skill"), "1.0.0", b"r" * 1024)
        env = dict(os.environ)
        env["PYTHONPATH"] = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

        install_script = (
            "import sys, time\n"
            "from zhjj_agent.install_transaction import InstallTransaction\n"
            "from zhjj_agent.package_manager import PackageManager\n"
            "tx = InstallTransaction(sys.argv[1])\n"
            "with tx.lock('100', timeout=30):\n"
            "    staging = tx.new_staging('100')\n"
            "    PackageManager(sys.argv[1]).inspect_and_unpack_zip(sys.argv[2], staging)\n"
            "    time.sleep(0.2)\n"
            "    tx.commit(staging, '100', '1.0.0')\n"
            "print('install-done')\n"
        )
        uninstall_script = (
            "import sys, time\n"
            "from zhjj_agent.install_transaction import InstallTransaction\n"
            "tx = InstallTransaction(sys.argv[1])\n"
            "time.sleep(0.1)\n"
            "with tx.lock('100', timeout=30):\n"
            "    tx.uninstall('100')\n"
            "print('uninstall-done')\n"
        )

        procs = [
            subprocess.Popen([sys.executable, "-c", install_script, self.temp_dir, package], stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env),
            subprocess.Popen([sys.executable, "-c", uninstall_script, self.temp_dir], stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env),
        ]
        outputs = [proc.communicate(timeout=60) for proc in procs]

        for proc, (out, err) in zip(procs, outputs):
            self.assertEqual(proc.returncode, 0, err.decode("utf-8", errors="replace"))

        target = self.tx.version_dir("100", "1.0.0")
        if os.path.isdir(target):
            # 允许"最后装上"，但内容必须完整且元数据齐全
            self.assertTrue(os.path.isfile(os.path.join(target, "manifest.json")))
            self.assertTrue(os.path.isfile(os.path.join(target, "data", "payload.bin")))
            self.assertIsNotNone(self.tx.read_install_meta(target))

        leftovers = []
        if os.path.isdir(self.tx.skills_root()):
            leftovers = [n for n in os.listdir(self.tx.skills_root()) if n.startswith(".removed_") or n.startswith(".staging_")]
        self.assertEqual(leftovers, [])


class TestTaskManagerInstallRollback(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()
        self.storage = Storage(self.temp_dir)
        self.client = StubClient()
        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        self.priv_pem = key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        ).decode("ascii")
        self.pub_pem = key.public_key().public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo,
        ).decode("ascii")

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _manager(self, adapter) -> TaskManager:
        return TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=self.client,
            adapter=adapter,
            pubkey_pem=self.pub_pem,
        )

    def _command(self, signed: Dict[str, Any]) -> Dict[str, Any]:
        signed.setdefault("protocol_version", PROTOCOL_V2)
        signed.setdefault("did", "did_a5_bot")
        signed.setdefault("command_seq", 1)
        signed.setdefault("task_expires_at", int(time.time()) + 3600)
        signature = sign_data(canonical_v2(signed), self.priv_pem)
        return {
            "task_id": signed["task_id"],
            "command_seq": signed.get("command_seq", 1),
            "operation": signed["operation"],
            "skill_id": signed["skill_id"],
            "version": signed.get("version", "1.0.0"),
            "protocol_version": PROTOCOL_V2,
            "signed": signed,
            "signature": signature,
        }

    def _install(self, manager, task_id: str, version: str, package_url: str, sha: str, seq: int = 1) -> bool:
        command = self._command({
            "task_id": task_id,
            "command_seq": seq,
            "operation": OP_INSTALL,
            "skill_id": "100",
            "version": version,
            "package_sha256": sha,
        })
        # 平台设计：下载地址在签名串之外（限时 URL 会变），字节由签住的 sha256 锁定。
        command["package_url"] = package_url
        return manager.process_command(command)

    # ── A5.5 首装失败 ────────────────────────────────────────────────────────

    def test_a5_10_首装适配器失败不留版本目录与激活指针(self):
        package = make_skill_zip(os.path.join(self.temp_dir, "pkg.skill"), "1.0.0")
        manager = self._manager(FailInstallAdapter())

        ok = self._install(manager, "T_A5_FAIL_1", "1.0.0", "file:///" + package.replace(os.sep, "/"), sha_file(package))

        self.assertFalse(ok)
        self.assertIsNone(self.storage.get_installed_skill("100"))
        tx = InstallTransaction(self.temp_dir)
        self.assertFalse(os.path.exists(tx.version_dir("100", "1.0.0")))
        self.assertIsNone(tx.read_active("100"))
        self.assertEqual(self.client.sent_events[-1]["error_code"], "ADAPTER_INSTALL_FAILED")

    def test_a5_11_健康探针失败也不留残骸(self):
        package = make_skill_zip(os.path.join(self.temp_dir, "pkg2.skill"), "1.0.0")
        manager = self._manager(FailObserveAdapter())

        ok = self._install(manager, "T_A5_FAIL_2", "1.0.0", "file:///" + package.replace(os.sep, "/"), sha_file(package))

        self.assertFalse(ok)
        tx = InstallTransaction(self.temp_dir)
        self.assertFalse(os.path.exists(tx.version_dir("100", "1.0.0")))
        self.assertIsNone(tx.read_active("100"))

    # ── A5.1 / A5.4 / A5.5 成功安装与旧版本恢复 ──────────────────────────────

    def test_a5_12_安装写激活指针且回滚到旧版本成功(self):
        v1 = make_skill_zip(os.path.join(self.temp_dir, "v1.skill"), "1.0.0", b"one")
        v2 = make_skill_zip(os.path.join(self.temp_dir, "v2.skill"), "2.0.0", b"two")
        manager = self._manager(MockAdapter())
        tx = InstallTransaction(self.temp_dir)

        self.assertTrue(self._install(manager, "T_A5_OK_1", "1.0.0", "file:///" + v1.replace(os.sep, "/"), sha_file(v1), seq=1))
        self.assertEqual(tx.read_active("100")["version"], "1.0.0")

        self.assertTrue(self._install(manager, "T_A5_OK_2", "2.0.0", "file:///" + v2.replace(os.sep, "/"), sha_file(v2), seq=2))
        self.assertEqual(tx.read_active("100")["version"], "2.0.0")
        self.assertEqual(self.storage.get_installed_skill("100")["previous_version"], "1.0.0")

        ok = manager.process_command(self._command({
            "task_id": "T_A5_ROLLBACK_1",
            "command_seq": 3,
            "operation": OP_ROLLBACK,
            "skill_id": "100",
            "version": "2.0.0",
            "restore_version": "1.0.0",
            "restore_sha256": sha_file(v1),
        }))

        self.assertTrue(ok)
        self.assertEqual(tx.read_active("100")["version"], "1.0.0")
        skill = self.storage.get_installed_skill("100")
        self.assertEqual(skill["current_version"], "1.0.0")
        self.assertEqual(skill["status"], "rolled_back")
        self.assertEqual(self.client.sent_events[-1]["phase"], PHASE_DONE)

    def test_a5_13_restore_sha256不符或磁盘内容被改都拒绝回滚(self):
        v1 = make_skill_zip(os.path.join(self.temp_dir, "r1.skill"), "1.0.0", b"one")
        v2 = make_skill_zip(os.path.join(self.temp_dir, "r2.skill"), "2.0.0", b"two")
        manager = self._manager(MockAdapter())
        tx = InstallTransaction(self.temp_dir)

        self.assertTrue(self._install(manager, "T_A5_R_1", "1.0.0", "file:///" + v1.replace(os.sep, "/"), sha_file(v1), seq=1))
        self.assertTrue(self._install(manager, "T_A5_R_2", "2.0.0", "file:///" + v2.replace(os.sep, "/"), sha_file(v2), seq=2))

        # ① 签名里的 restore_sha256 与恢复目标版本记录的包哈希不符
        ok = manager.process_command(self._command({
            "task_id": "T_A5_R_BAD_SHA",
            "command_seq": 3,
            "operation": OP_ROLLBACK,
            "skill_id": "100",
            "version": "2.0.0",
            "restore_version": "1.0.0",
            "restore_sha256": "0" * 64,
        }))
        self.assertFalse(ok)
        self.assertEqual(self.client.sent_events[-1]["error_code"], ERR_RESTORE_MISMATCH)
        self.assertEqual(tx.read_active("100")["version"], "2.0.0")

        # ② 磁盘内容被改（包哈希仍对得上，但目录摘要对不上）
        target = tx.version_dir("100", "1.0.0")
        with open(os.path.join(target, "action.py"), "w", encoding="utf-8") as handle:
            handle.write("# tampered")
        ok = manager.process_command(self._command({
            "task_id": "T_A5_R_TAMPER",
            "command_seq": 4,
            "operation": OP_ROLLBACK,
            "skill_id": "100",
            "version": "2.0.0",
            "restore_version": "1.0.0",
            "restore_sha256": sha_file(v1),
        }))
        self.assertFalse(ok)
        self.assertEqual(self.client.sent_events[-1]["error_code"], ERR_RESTORE_MISMATCH)
        self.assertEqual(tx.read_active("100")["version"], "2.0.0")

    def test_a5_14_适配器回滚失败时激活指针回到原版本(self):
        v1 = make_skill_zip(os.path.join(self.temp_dir, "f1.skill"), "1.0.0", b"one")
        v2 = make_skill_zip(os.path.join(self.temp_dir, "f2.skill"), "2.0.0", b"two")
        tx = InstallTransaction(self.temp_dir)

        good = self._manager(MockAdapter())
        self.assertTrue(self._install(good, "T_A5_F_1", "1.0.0", "file:///" + v1.replace(os.sep, "/"), sha_file(v1), seq=1))
        self.assertTrue(self._install(good, "T_A5_F_2", "2.0.0", "file:///" + v2.replace(os.sep, "/"), sha_file(v2), seq=2))

        bad = self._manager(FailRollbackAdapter())
        ok = bad.process_command(self._command({
            "task_id": "T_A5_F_ROLLBACK",
            "command_seq": 3,
            "operation": OP_ROLLBACK,
            "skill_id": "100",
            "version": "2.0.0",
            "restore_version": "1.0.0",
            "restore_sha256": sha_file(v1),
        }))

        self.assertFalse(ok)
        self.assertEqual(self.client.sent_events[-1]["error_code"], "ROLLBACK_FAILED")
        self.assertEqual(tx.read_active("100")["version"], "2.0.0", "适配器失败后指针必须回到回滚前的版本")
        self.assertEqual(self.storage.get_installed_skill("100")["current_version"], "2.0.0")

    # ── A5.3 活动执行 ────────────────────────────────────────────────────────

    def test_a5_15_活动执行未确认停止时拒绝回滚(self):
        v1 = make_skill_zip(os.path.join(self.temp_dir, "e1.skill"), "1.0.0", b"one")
        v2 = make_skill_zip(os.path.join(self.temp_dir, "e2.skill"), "2.0.0", b"two")
        tx = InstallTransaction(self.temp_dir)

        good = self._manager(MockAdapter())
        self.assertTrue(self._install(good, "T_A5_E_1", "1.0.0", "file:///" + v1.replace(os.sep, "/"), sha_file(v1), seq=1))
        self.assertTrue(self._install(good, "T_A5_E_2", "2.0.0", "file:///" + v2.replace(os.sep, "/"), sha_file(v2), seq=2))

        self.storage.acquire_execution_lease("exec_a5_running", "100", "motion_control", 120)

        manager = self._manager(NeverStopAdapter())
        ok = manager.process_command(self._command({
            "task_id": "T_A5_E_ROLLBACK",
            "command_seq": 3,
            "operation": OP_ROLLBACK,
            "skill_id": "100",
            "version": "2.0.0",
            "restore_version": "1.0.0",
            "restore_sha256": sha_file(v1),
        }))

        self.assertFalse(ok)
        self.assertEqual(self.client.sent_events[-1]["error_code"], ERR_ROLLBACK_BLOCKED)
        self.assertEqual(tx.read_active("100")["version"], "2.0.0")
        self.assertIsNotNone(self.storage.get_execution_lease("exec_a5_running"), "未确认停止时租约不得释放")

    def test_a5_16_授权过期时拒绝回滚到手可执行状态(self):
        v1 = make_skill_zip(os.path.join(self.temp_dir, "g1.skill"), "1.0.0", b"one")
        v2 = make_skill_zip(os.path.join(self.temp_dir, "g2.skill"), "2.0.0", b"two")
        tx = InstallTransaction(self.temp_dir)

        good = self._manager(MockAdapter())
        self.assertTrue(self._install(good, "T_A5_G_1", "1.0.0", "file:///" + v1.replace(os.sep, "/"), sha_file(v1), seq=1))
        self.assertTrue(self._install(good, "T_A5_G_2", "2.0.0", "file:///" + v2.replace(os.sep, "/"), sha_file(v2), seq=2))

        ok = good.process_command(self._command({
            "task_id": "T_A5_G_ROLLBACK",
            "command_seq": 3,
            "operation": OP_ROLLBACK,
            "skill_id": "100",
            "version": "2.0.0",
            "restore_version": "1.0.0",
            "restore_sha256": sha_file(v1),
            "authorization_expires_at": int(time.time()) - 60,
        }))

        self.assertFalse(ok)
        self.assertEqual(self.client.sent_events[-1]["error_code"], ERR_REVOKED)
        self.assertEqual(tx.read_active("100")["version"], "2.0.0")

    # ── A5.1 卸载 ────────────────────────────────────────────────────────────

    def test_a5_17_卸载原子移除技能目录与激活指针(self):
        v1 = make_skill_zip(os.path.join(self.temp_dir, "u1.skill"), "1.0.0", b"one")
        manager = self._manager(MockAdapter())
        tx = InstallTransaction(self.temp_dir)

        self.assertTrue(self._install(manager, "T_A5_U_1", "1.0.0", "file:///" + v1.replace(os.sep, "/"), sha_file(v1), seq=1))
        self.assertTrue(os.path.isdir(tx.skill_root("100")))

        ok = manager.process_command(self._command({
            "task_id": "T_A5_UNINSTALL",
            "command_seq": 2,
            "operation": OP_UNINSTALL,
            "skill_id": "100",
            "version": "1.0.0",
        }))

        self.assertTrue(ok)
        self.assertFalse(os.path.isdir(tx.skill_root("100")))
        self.assertEqual(self.storage.get_installed_skill("100")["status"], "uninstalled")


if __name__ == "__main__":
    unittest.main()
