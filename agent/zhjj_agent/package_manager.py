"""包管理器：限量下载、流式 SHA-256 核验、安全解压（Zip Slip/符号链接/解压炸弹/磁盘预检）与 Staging 事务"""

import hashlib
import json
import os
import shutil
import stat
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from typing import Any, Dict, List, Optional, Tuple

from zhjj_agent.constants import (
    ERR_DISK_SPACE,
    ERR_DOWNLOAD_FAILED,
    ERR_PKG_HASH_MISMATCH,
    ERR_STAGING_FAILED,
    ERR_ZIP_BOMB,
    ERR_ZIP_INVALID,
    ERR_ZIP_SLIP,
    ERR_ZIP_SYMLINK,
)


class PackageManagerError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


class SafeRedirectHandler(urllib.request.HTTPRedirectHandler):
    """防凭据泄漏重定向处理器：当跨源站重定向时，自动剥离敏感设备凭据头。"""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        new_req = super().redirect_request(req, fp, code, msg, headers, newurl)
        if new_req is None:
            return None

        orig_parsed = urllib.parse.urlparse(req.get_full_url())
        new_parsed = urllib.parse.urlparse(newurl)

        # 若目标主机或端口与原站不同，剥离 X-Agent-* 与 Authorization 头
        if orig_parsed.netloc.lower() != new_parsed.netloc.lower():
            for h in list(new_req.headers.keys()):
                if h.lower().startswith("x-agent-") or h.lower() == "authorization":
                    del new_req.headers[h]
        return new_req


class PackageManager:
    # 默认安全阈值
    MAX_DOWNLOAD_BYTES = 500 * 1024 * 1024          # 最大下载 500MB
    MAX_UNPACKED_BYTES = 1024 * 1024 * 1024         # 最大解压后 1GB
    MAX_FILE_COUNT = 2000                            # 最大文件数
    MAX_COMPRESSION_RATIO = 50.0                     # 最大解压膨胀比（防解压炸弹）
    MIN_FREE_DISK_BYTES = 50 * 1024 * 1024          # 磁盘最少剩余 50MB

    def __init__(self, install_dir: Optional[str] = None):
        self.install_dir = install_dir

    def download_and_verify(
        self,
        url: str,
        dest_path: str,
        expected_sha256: Optional[str] = None,
        headers: Optional[Dict[str, str]] = None
    ) -> str:
        os.makedirs(os.path.dirname(dest_path), exist_ok=True)
        got_sha = self.download_package(url, dest_path, headers=headers)
        if expected_sha256 and got_sha.lower() != expected_sha256.lower():
            if os.path.exists(dest_path):
                os.remove(dest_path)
            raise PackageManagerError(
                ERR_PKG_HASH_MISMATCH,
                f"下载字节 SHA-256 ({got_sha}) 与指令声明哈希 ({expected_sha256}) 不匹配"
            )
        return got_sha

    def unpack_to_staging(self, pkg_path: str, staging_dir: str) -> None:
        if os.path.exists(staging_dir):
            shutil.rmtree(staging_dir, ignore_errors=True)
        os.makedirs(staging_dir, exist_ok=True)
        if zipfile.is_zipfile(pkg_path):
            self.inspect_and_unpack_zip(pkg_path, staging_dir)
        else:
            raw_dest = os.path.join(staging_dir, "package.skill")
            shutil.copyfile(pkg_path, raw_dest)

    def read_and_validate_manifest(self, staging_dir: str) -> Dict[str, Any]:
        manifest_path = os.path.join(staging_dir, "manifest.json")
        if os.path.isfile(manifest_path):
            try:
                with open(manifest_path, "r", encoding="utf-8") as f:
                    return json.load(f)
            except Exception as e:
                raise PackageManagerError(ERR_ZIP_INVALID, f"manifest.json 格式非法: {e}")
        return {}

    def commit_staging_to_final(self, staging_dir: str, skill_id: str, version: str) -> Tuple[str, List[str]]:
        install_dir = self.install_dir or "."
        skill_root = os.path.join(install_dir, "skills", str(skill_id))
        final_version_dir = os.path.join(skill_root, "v" + str(version or "1.0.0"))
        os.makedirs(skill_root, exist_ok=True)

        files = []
        for root, _, filenames in os.walk(staging_dir):
            for fn in filenames:
                rel = os.path.relpath(os.path.join(root, fn), staging_dir)
                files.append(rel)

        if os.path.exists(final_version_dir):
            backup_dir = final_version_dir + ".old"
            if os.path.exists(backup_dir):
                shutil.rmtree(backup_dir, ignore_errors=True)
            os.rename(final_version_dir, backup_dir)
            try:
                os.rename(staging_dir, final_version_dir)
                shutil.rmtree(backup_dir, ignore_errors=True)
            except Exception as e:
                if os.path.exists(backup_dir):
                    os.rename(backup_dir, final_version_dir)
                raise PackageManagerError(ERR_STAGING_FAILED, f"原子切换安装目录失败: {e}")
        else:
            os.rename(staging_dir, final_version_dir)

        return final_version_dir, files

    @staticmethod
    def check_disk_space(target_path: str, required_bytes: int):
        """检查目标路径所在磁盘空间。"""
        folder = target_path if os.path.exists(target_path) else os.path.dirname(os.path.abspath(target_path))
        while folder and not os.path.exists(folder):
            folder = os.path.dirname(folder)
        if not folder:
            folder = "."
        try:
            usage = shutil.disk_usage(folder)
            if usage.free < required_bytes + PackageManager.MIN_FREE_DISK_BYTES:
                raise PackageManagerError(
                    ERR_DISK_SPACE,
                    f"磁盘剩余空间不足: 可用 {usage.free} bytes, 所需 {required_bytes} bytes"
                )
        except OSError:
            pass

    @classmethod
    def download_package(
        cls,
        url: str,
        dest_path: str,
        headers: Optional[Dict[str, str]] = None,
        max_bytes: int = MAX_DOWNLOAD_BYTES
    ) -> str:
        """流式限量下载并计算 SHA-256，防内存撑爆与超大文件攻击。返回小写十六进制 SHA-256。"""
        req = urllib.request.Request(url, method="GET")
        for k, v in (headers or {}).items():
            req.add_header(k, v)

        h = hashlib.sha256()
        total_read = 0

        # 预先检查磁盘空间
        cls.check_disk_space(dest_path, 10 * 1024 * 1024)

        opener = urllib.request.build_opener(SafeRedirectHandler())

        try:
            with opener.open(req, timeout=60) as r:
                with open(dest_path, "wb") as out_f:
                    while True:
                        chunk = r.read(65536)
                        if not chunk:
                            break
                        total_read += len(chunk)
                        if total_read > max_bytes:
                            raise PackageManagerError(
                                ERR_ZIP_BOMB,
                                f"下载包体积超过安全上限 {max_bytes} 字节"
                            )
                        h.update(chunk)
                        out_f.write(chunk)
        except PackageManagerError:
            if os.path.exists(dest_path):
                os.remove(dest_path)
            raise
        except urllib.error.HTTPError as e:
            if os.path.exists(dest_path):
                os.remove(dest_path)
            if e.code in (401, 403):
                raise PackageManagerError("AUTH_FAILED", f"下载身份认证失败 HTTP {e.code}: {e.reason}")
            raise PackageManagerError(ERR_DOWNLOAD_FAILED, f"下载 HTTP 错误 {e.code}: {e.reason}")
        except urllib.error.URLError as e:
            if os.path.exists(dest_path):
                os.remove(dest_path)
            raise PackageManagerError(ERR_DOWNLOAD_FAILED, f"下载网络连接错误: {e.reason}")
        except Exception as e:
            if os.path.exists(dest_path):
                os.remove(dest_path)
            raise PackageManagerError(ERR_DOWNLOAD_FAILED, f"下载网络或IO异常: {e}")

        return h.hexdigest().lower()

    @classmethod
    def inspect_and_unpack_zip(
        cls,
        zip_path: str,
        target_dir: str
    ) -> Tuple[Dict[str, Any], List[str]]:
        """对 ZIP 包执行严格安全审计并解包到 target_dir。
        安全检查包括：
        1. 格式有效性验证
        2. Zip Slip 路径越界检查
        3. 符号链接（Symlink）拒绝
        4. 文件总数与总大小上限预检
        5. 解压膨胀比预检（防 Zip Bomb）
        """
        if not zipfile.is_zipfile(zip_path):
            raise PackageManagerError(ERR_ZIP_INVALID, "目标文件不是合法的 ZIP 包")

        abs_target_dir = os.path.abspath(target_dir)
        total_uncompressed = 0
        extracted_files = []

        with zipfile.ZipFile(zip_path, "r") as zf:
            infolist = zf.infolist()
            if len(infolist) > cls.MAX_FILE_COUNT:
                raise PackageManagerError(
                    ERR_ZIP_BOMB,
                    f"ZIP 内文件数 ({len(infolist)}) 超过安全限制 ({cls.MAX_FILE_COUNT})"
                )

            compressed_size = os.path.getsize(zip_path) or 1
            for info in infolist:
                total_uncompressed += info.file_size
                if total_uncompressed > cls.MAX_UNPACKED_BYTES:
                    raise PackageManagerError(
                        ERR_ZIP_BOMB,
                        f"解压后总体积超过安全上限 {cls.MAX_UNPACKED_BYTES} 字节"
                    )

                # 符号链接安全检查
                is_symlink = (info.external_attr >> 16) & 0o170000 == 0o120000
                if is_symlink:
                    raise PackageManagerError(
                        ERR_ZIP_SYMLINK,
                        f"技能包内禁止包含符号链接: {info.filename}"
                    )

                # Zip Slip 路径越界检查
                member_dest = os.path.abspath(os.path.join(abs_target_dir, info.filename))
                if not (member_dest == abs_target_dir or member_dest.startswith(abs_target_dir + os.sep)):
                    raise PackageManagerError(
                        ERR_ZIP_SLIP,
                        f"检测到路径穿越攻击 (Zip Slip): {info.filename}"
                    )

            # 膨胀比检查
            ratio = total_uncompressed / compressed_size
            if total_uncompressed > 10 * 1024 * 1024 and ratio > cls.MAX_COMPRESSION_RATIO:
                raise PackageManagerError(
                    ERR_ZIP_BOMB,
                    f"压缩膨胀比过高 ({ratio:.1f}x > {cls.MAX_COMPRESSION_RATIO}x)，疑为解压炸弹"
                )

            # 磁盘预检
            cls.check_disk_space(abs_target_dir, total_uncompressed)

            # 执行解压
            os.makedirs(abs_target_dir, exist_ok=True)
            for info in infolist:
                # 再次校验路径
                dest_file = os.path.abspath(os.path.join(abs_target_dir, info.filename))
                if info.is_dir():
                    os.makedirs(dest_file, exist_ok=True)
                else:
                    os.makedirs(os.path.dirname(dest_file), exist_ok=True)
                    with zf.open(info) as source, open(dest_file, "wb") as target:
                        shutil.copyfileobj(source, target)
                    extracted_files.append(info.filename)

        # 检查并解析 manifest.json
        manifest = {}
        manifest_path = os.path.join(abs_target_dir, "manifest.json")
        if os.path.isfile(manifest_path):
            try:
                with open(manifest_path, "r", encoding="utf-8") as mf:
                    manifest = json.load(mf)
            except Exception as e:
                raise PackageManagerError(ERR_ZIP_INVALID, f"manifest.json 格式非法: {e}")

        return manifest, extracted_files

    @classmethod
    def install_with_staging(
        cls,
        pkg_path: str,
        install_dir: str,
        skill_id: str,
        version: str
    ) -> Tuple[str, Dict[str, Any], List[str]]:
        """Staging 事务安装：
        先解压至独立临时暂存目录 (.staging_<task_id>)，全部校验成功后再原子切换至版本目录 v<version>。
        若失败则清理 staging 目录，绝不污染既有已安装版本。
        返回 (final_version_dir, manifest, files)。
        """
        skill_key = str(skill_id)
        ver_str = str(version or "1.0.0")
        skill_root = os.path.join(install_dir, "skills", skill_key)
        final_version_dir = os.path.join(skill_root, "v" + ver_str)
        os.makedirs(skill_root, exist_ok=True)

        staging_dir = tempfile.mkdtemp(prefix=".staging_", dir=skill_root)
        try:
            if zipfile.is_zipfile(pkg_path):
                manifest, files = cls.inspect_and_unpack_zip(pkg_path, staging_dir)
            else:
                # 裸单文件载体支持
                raw_dest = os.path.join(staging_dir, "package.skill")
                shutil.copyfile(pkg_path, raw_dest)
                manifest = {}
                files = ["package.skill"]

            # 原子切换：若已有目标版本则安全清理后重命名
            if os.path.exists(final_version_dir):
                backup_dir = final_version_dir + ".old"
                if os.path.exists(backup_dir):
                    shutil.rmtree(backup_dir, ignore_errors=True)
                os.rename(final_version_dir, backup_dir)
                try:
                    os.rename(staging_dir, final_version_dir)
                    shutil.rmtree(backup_dir, ignore_errors=True)
                except Exception as e:
                    # 回滚目录
                    if os.path.exists(backup_dir):
                        os.rename(backup_dir, final_version_dir)
                    raise PackageManagerError(ERR_STAGING_FAILED, f"原子切换安装目录失败: {e}")
            else:
                os.rename(staging_dir, final_version_dir)

            return final_version_dir, manifest, files

        except PackageManagerError:
            if os.path.exists(staging_dir):
                shutil.rmtree(staging_dir, ignore_errors=True)
            raise
        except Exception as e:
            if os.path.exists(staging_dir):
                shutil.rmtree(staging_dir, ignore_errors=True)
            raise PackageManagerError(ERR_STAGING_FAILED, f"Staging 安装事务失败: {e}")
