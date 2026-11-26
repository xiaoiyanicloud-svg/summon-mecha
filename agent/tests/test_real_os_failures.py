"""T4 · 真实操作系统级失败（ENOSPC / EACCES）下的安装事务。

与 A5 已有的"注入式"用例（mock 抛 `OSError(errno.ENOSPC)`）不同：
这里用**真实的文件系统权限拒绝**触发 `EACCES`，观察安装事务是否**受控失败**
（明确错误码、不留半装状态、锁已释放、恢复权限后能装成功）。

ENOSPC 分支说明（诚实）：
  建 64MB 动态 VHD 需要 `diskpart` / `Mount-DiskImage` 的**管理员权限**。
  本机当前进程未提权（`IsInRole(Administrator)=False`，diskpart 返回
  "The requested operation requires elevation"），因此**无法构造真实满盘**。
  本文件仍保留 ENOSPC 用例，但只有在显式提供 `ZHJJ_ENOSPC_ROOT`
  （一个已挂载、已填到只剩几 MB 的卷/目录）时才执行；否则 skip 并写明
  `BLOCKED_ELEVATION`。这比把注入式用例标成 PROVEN 诚实。
"""

import json
import os
import shutil
import subprocess
import tempfile
import unittest
import zipfile

from zhjj_agent.constants import ERR_PERMISSION_DENIED
from zhjj_agent.install_transaction import InstallTransaction
from zhjj_agent.package_manager import PackageManager, PackageManagerError

EVERYONE_SID = "*S-1-1-0"


def make_skill_package(path: str) -> None:
    with zipfile.ZipFile(path, "w") as zf:
        zf.writestr(
            "manifest.json",
            json.dumps({"name": "acl-probe", "version": "1.0.0", "kind": "app"}),
        )
        zf.writestr("data/payload.bin", b"p" * 4096)


def icacls(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(["icacls", *args], capture_output=True, text=True, check=False)


def deny_write(path: str) -> bool:
    return icacls(path, "/deny", f"{EVERYONE_SID}:(W)").returncode == 0


def allow_write(path: str) -> None:
    # 忽略失败：收尾阶段即使 ACL 已被手工改回，也不该让用例报错
    icacls(path, "/remove:d", EVERYONE_SID)


def write_denied(path: str) -> bool:
    """真实探测"写不进去"——不是凭 icacls 返回码猜。"""
    probe = os.path.join(path, "._zhjj_probe")
    try:
        with open(probe, "wb") as handle:
            handle.write(b"x")
    except PermissionError:
        return True
    else:
        try:
            os.remove(probe)
        except OSError:
            pass
        return False


class AcLayout:
    """一套最小安装目录：state/locks 可写，skills/ 可被外部 ACL 拒绝。"""

    def __init__(self):
        self.base = tempfile.mkdtemp(prefix="zhjj_acl_")
        self.install_dir = os.path.join(self.base, "agent")
        self.skills = os.path.join(self.install_dir, "skills")
        os.makedirs(os.path.join(self.install_dir, "state", "locks"), exist_ok=True)
        os.makedirs(self.skills, exist_ok=True)
        self.package = os.path.join(self.base, "probe.skill")
        make_skill_package(self.package)

    def cleanup(self) -> None:
        allow_write(self.skills)
        shutil.rmtree(self.base, ignore_errors=True)


class TestRealAcl(unittest.TestCase):
    def setUp(self):
        self.layout = AcLayout()
        if not deny_write(self.layout.skills):
            self.layout.cleanup()
            self.skipTest("BLOCKED_OS: icacls 拒绝写权限失败，无法构造真实 EACCES")

        if not write_denied(self.layout.skills):
            self.layout.cleanup()
            self.skipTest(
                "BLOCKED_OS: 当前进程的特权上下文绕过了 ACL 拒绝（管理员/备份特权），"
                "本机无法构造真实 EACCES —— 拒绝把注入式用例标成 PROVEN"
            )

    def tearDown(self):
        if hasattr(self, "layout"):
            self.layout.cleanup()

    def test_a5_acl_01_真实EACCES下安装受控失败且锁已释放(self):
        tx = InstallTransaction(self.layout.install_dir)

        with self.assertRaises(PackageManagerError) as ctx:
            with tx.lock("100", timeout=5):
                staging = tx.new_staging("100")
                PackageManager(self.layout.install_dir).unpack_to_staging(
                    self.layout.package, staging
                )
                tx.commit(staging, "100", "1.0.0")

        # 1) 受控失败：明确错误码，而不是未捕获的 PermissionError 栈
        self.assertEqual(ctx.exception.code, ERR_PERMISSION_DENIED)
        self.assertIn("权限不足", str(ctx.exception))

        # 2) 无半装状态：版本目录与激活指针都不存在
        self.assertFalse(os.path.isdir(tx.version_dir("100", "1.0.0")))
        self.assertIsNone(tx.read_active("100"))

        # 3) 锁已释放：能再次拿到（超时 2s 足够，若泄漏会超时抛错）
        with tx.lock("100", timeout=2):
            pass

    def test_a5_acl_02_恢复权限后安装成功(self):
        allow_write(self.layout.skills)
        self.assertFalse(write_denied(self.layout.skills), "ACL 必须真的恢复")

        tx = InstallTransaction(self.layout.install_dir)
        with tx.lock("100", timeout=5):
            staging = tx.new_staging("100")
            PackageManager(self.layout.install_dir).unpack_to_staging(
                self.layout.package, staging
            )
            target = tx.commit(staging, "100", "1.0.0")
            tx.activate("100", "1.0.0")

        self.assertTrue(os.path.isfile(os.path.join(target, "manifest.json")))
        self.assertTrue(os.path.isfile(os.path.join(target, "data", "payload.bin")))
        self.assertEqual((tx.read_active("100") or {}).get("version"), "1.0.0")


class TestRealEnospc(unittest.TestCase):
    """真实满盘。只有显式提供 `ZHJJ_ENOSPC_ROOT` 时才执行。

    准备方式（需要管理员权限，本机未提权，见文件头）：
      · 建 64MB 动态 VHD → attach → NTFS 格式化 → 分配盘符 X:；
      · 写一个大文件把 X: 填到只剩几 MB；
      · 设 `ZHJJ_ENOSPC_ROOT=X:\\` 后运行本类。
    """

    @classmethod
    def setUpClass(cls):
        cls.root = os.environ.get("ZHJJ_ENOSPC_ROOT")
        if not cls.root:
            raise unittest.SkipTest(
                "BLOCKED_ELEVATION: 未提供 ZHJJ_ENOSPC_ROOT（本机无管理员权限，"
                "diskpart/Mount-DiskImage 均报 requires elevation），无法构造真实满盘"
            )
        if not os.path.isdir(cls.root):
            raise unittest.SkipTest(f"BLOCKED_ENV: ZHJJ_ENOSPC_ROOT 不存在: {cls.root}")

    def _prepare(self):
        base = tempfile.mkdtemp(prefix="zhjj_enospc_")
        install_dir = os.path.join(base, "agent")
        os.makedirs(os.path.join(install_dir, "skills"), exist_ok=True)
        package = os.path.join(base, "probe.skill")
        make_skill_package(package)
        return base, install_dir, package

    def test_a5_enospc_01_真实满盘下安装受控失败(self):
        base, install_dir, package = self._prepare()
        try:
            tx = InstallTransaction(install_dir)
            with self.assertRaises(PackageManagerError) as ctx:
                with tx.lock("100", timeout=5):
                    staging = tx.new_staging("100")
                    PackageManager(install_dir).unpack_to_staging(package, staging)
                    tx.commit(staging, "100", "1.0.0")
            self.assertIn(ctx.exception.code, ("DISK_SPACE", "STAGING_FAILED"))
            self.assertFalse(os.path.isdir(tx.version_dir("100", "1.0.0")))
            self.assertIsNone(tx.read_active("100"))
        finally:
            shutil.rmtree(base, ignore_errors=True)

    def test_a5_enospc_02_失败后无半装状态(self):
        base, install_dir, package = self._prepare()
        tx = InstallTransaction(install_dir)
        try:
            with self.assertRaises(PackageManagerError):
                with tx.lock("100", timeout=5):
                    staging = tx.new_staging("100")
                    PackageManager(install_dir).unpack_to_staging(package, staging)
                    tx.commit(staging, "100", "1.0.0")

            # 激活指针与版本内容的真相必须一致：既没有半成品版本目录，也没有指向它的指针
            self.assertFalse(os.path.isdir(tx.version_dir("100", "1.0.0")))
            self.assertIsNone(tx.read_active("100"))
            self.assertEqual(tx.read_install_meta(tx.version_dir("100", "1.0.0")), None)
        finally:
            shutil.rmtree(base, ignore_errors=True)

    def test_a5_enospc_03_换到有空间的目录重试成功(self):
        base, install_dir, package = self._prepare()
        good = tempfile.mkdtemp(prefix="zhjj_enospc_good_")
        try:
            tx = InstallTransaction(good)
            with tx.lock("100", timeout=5):
                staging = tx.new_staging("100")
                PackageManager(good).unpack_to_staging(package, staging)
                target = tx.commit(staging, "100", "1.0.0")
            self.assertTrue(os.path.isfile(os.path.join(target, "manifest.json")))
        finally:
            shutil.rmtree(base, ignore_errors=True)
            shutil.rmtree(good, ignore_errors=True)


if __name__ == "__main__":
    unittest.main()
