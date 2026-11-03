"""测试 PackageManager：安全下载、哈希核验、Zip Slip、符号链接、解压炸弹与 Staging 事务"""

import io
import json
import os
import shutil
import tempfile
import unittest
import zipfile

from zhjj_agent.constants import (
    ERR_PKG_HASH_MISMATCH,
    ERR_ZIP_BOMB,
    ERR_ZIP_INVALID,
    ERR_ZIP_SLIP,
    ERR_ZIP_SYMLINK,
)
from zhjj_agent.package_manager import PackageManager, PackageManagerError


class TestPackageManager(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _create_valid_skill_zip(self, zip_path: str, manifest_data: dict = None):
        if manifest_data is None:
            manifest_data = {
                "schema": "zhaohuan.skill/v2",
                "kind": "motion",
                "name": {"zh_CN": "测试挥手"},
                "version": "1.0.0"
            }
        with zipfile.ZipFile(zip_path, "w") as zf:
            zf.writestr("manifest.json", json.dumps(manifest_data))
            zf.writestr("action.py", "# print('hello')")
            zf.writestr("data/sample.txt", "sample motion trajectory")

    def test_valid_unpack_and_manifest(self):
        zip_path = os.path.join(self.temp_dir, "valid.skill")
        self._create_valid_skill_zip(zip_path)

        target_dir = os.path.join(self.temp_dir, "extracted")
        manifest, files = PackageManager.inspect_and_unpack_zip(zip_path, target_dir)

        self.assertEqual(manifest["version"], "1.0.0")
        self.assertIn("manifest.json", files)
        self.assertIn("action.py", files)
        self.assertTrue(os.path.isfile(os.path.join(target_dir, "manifest.json")))

    def test_zip_slip_attack_rejected(self):
        zip_path = os.path.join(self.temp_dir, "zip_slip.zip")
        with zipfile.ZipFile(zip_path, "w") as zf:
            zf.writestr("../../etc/cron.d/malicious", "malicious payload")
            zf.writestr("normal.txt", "normal file")

        target_dir = os.path.join(self.temp_dir, "target")
        with self.assertRaises(PackageManagerError) as cm:
            PackageManager.inspect_and_unpack_zip(zip_path, target_dir)
        self.assertEqual(cm.exception.code, ERR_ZIP_SLIP)

    def test_zip_symlink_rejected(self):
        zip_path = os.path.join(self.temp_dir, "symlink.zip")
        with zipfile.ZipFile(zip_path, "w") as zf:
            # 构造带符号链接属性 (0o120000) 的 ZipInfo
            info = zipfile.ZipInfo("link_to_root")
            info.create_system = 3  # Unix
            info.external_attr = 0o120777 << 16  # Symlink mode
            zf.writestr(info, "/etc/shadow")

        target_dir = os.path.join(self.temp_dir, "target")
        with self.assertRaises(PackageManagerError) as cm:
            PackageManager.inspect_and_unpack_zip(zip_path, target_dir)
        self.assertEqual(cm.exception.code, ERR_ZIP_SYMLINK)

    def test_zip_bomb_file_count_rejected(self):
        zip_path = os.path.join(self.temp_dir, "many_files.zip")
        with zipfile.ZipFile(zip_path, "w") as zf:
            for i in range(PackageManager.MAX_FILE_COUNT + 10):
                zf.writestr(f"file_{i}.txt", "x")

        target_dir = os.path.join(self.temp_dir, "target")
        with self.assertRaises(PackageManagerError) as cm:
            PackageManager.inspect_and_unpack_zip(zip_path, target_dir)
        self.assertEqual(cm.exception.code, ERR_ZIP_BOMB)

    def test_staging_install_success_and_failure_isolation(self):
        install_dir = os.path.join(self.temp_dir, "install")
        os.makedirs(install_dir, exist_ok=True)

        # 1. 成功安装 v1.0.0
        v1_zip = os.path.join(self.temp_dir, "v1.skill")
        self._create_valid_skill_zip(v1_zip, {"version": "1.0.0"})
        v1_dir, _, _ = PackageManager.install_with_staging(v1_zip, install_dir, "skill_99", "1.0.0")
        self.assertTrue(os.path.isdir(v1_dir))
        self.assertTrue(os.path.isfile(os.path.join(v1_dir, "manifest.json")))

        # 2. 尝试安装恶意的 v1.1.0（含 Zip Slip 攻击）
        bad_zip = os.path.join(self.temp_dir, "v1_bad.skill")
        with zipfile.ZipFile(bad_zip, "w") as zf:
            zf.writestr("../evil.txt", "evil")

        with self.assertRaises(PackageManagerError):
            PackageManager.install_with_staging(bad_zip, install_dir, "skill_99", "1.1.0")

        # 3. 验证 v1.0.0 完好无损，且没有留下 staging 临时目录
        self.assertTrue(os.path.isdir(v1_dir))
        skill_root = os.path.join(install_dir, "skills", "skill_99")
        for name in os.listdir(skill_root):
            self.assertFalse(name.startswith(".staging_"), f"残留了临时目录: {name}")


if __name__ == "__main__":
    unittest.main()
