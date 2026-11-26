"""包管理器：限量下载、流式 SHA-256 核验、安全解压（Zip Slip/符号链接/解压炸弹/重复路径/磁盘预检）与 Staging 事务"""

import hashlib
import json
import os
import re
import shutil
import stat
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import zipfile
import zlib
from typing import Any, Dict, List, Optional, Tuple

from zhjj_agent.constants import (
    ERR_DISK_SPACE,
    ERR_DOWNLOAD_FAILED,
    ERR_PATH_INVALID,
    ERR_PKG_HASH_MISMATCH,
    ERR_STAGING_FAILED,
    ERR_ZIP_BOMB,
    ERR_ZIP_DUPLICATE_PATH,
    ERR_ZIP_INVALID,
    ERR_ZIP_SLIP,
    ERR_ZIP_SYMLINK,
)

# Windows 保留设备名与非法字符 —— 在 NTFS 上 `CON` / `a:b` / 结尾点号都会让后续 IO 失败。
_WINDOWS_RESERVED = {
    "CON", "PRN", "AUX", "NUL",
    *(f"COM{i}" for i in range(1, 10)),
    *(f"LPT{i}" for i in range(1, 10)),
}
_DRIVE_PREFIX = re.compile(r"^[A-Za-z]:")


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
            return

        # 「不是合法 zip」有两种完全不同的含义：
        #   ① 本来就是裸单文件载体（合法的 `package.skill`）；
        #   ② 一个**被截断/损坏的 zip** —— `is_zipfile()` 只扫中央目录尾，损坏后会返回 False，
        #      若把它当裸文件拷贝，损坏包会被"成功安装"，直到运行期才炸。
        # 用本地文件头魔数区分：以 `PK\x03\x04` 开头却不是合法 zip ⇒ 文件损坏。
        with open(pkg_path, "rb") as handle:
            magic = handle.read(4)
        if magic == b"PK\x03\x04":
            raise PackageManagerError(ERR_ZIP_INVALID, "技能包以 ZIP 头开始但中央目录损坏（文件被截断或篡改）")

        raw_dest = os.path.join(staging_dir, "package.skill")
        try:
            shutil.copyfile(pkg_path, raw_dest)
        except OSError as error:
            raise self._translate_os_error(error, "复制裸单文件载体失败")

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
        """把 staging 原子切换为固定版本目录。

        ⚠ 这里刻意**不重复实现**备份/回滚逻辑：安装事务只有一份实现
        （`InstallTransaction.commit`），否则两条路径迟早分叉成"测试走 A、生产走 B"。
        """
        from zhjj_agent.install_transaction import InstallTransaction

        files = []
        for root, _, filenames in os.walk(staging_dir):
            for fn in filenames:
                rel = os.path.relpath(os.path.join(root, fn), staging_dir)
                files.append(rel)

        transaction = InstallTransaction(self.install_dir or ".")
        with transaction.lock(str(skill_id)):
            final_version_dir = transaction.commit(staging_dir, str(skill_id), str(version or "1.0.0"))

        return final_version_dir, files

    @staticmethod
    def _translate_os_error(error: OSError, context: str) -> "PackageManagerError":
        import errno as _errno

        code = getattr(error, "errno", None)
        if code in (_errno.ENOSPC, _errno.EDQUOT):
            return PackageManagerError(ERR_DISK_SPACE, f"{context}: 磁盘空间不足 ({error})")
        if code in (_errno.EACCES, _errno.EPERM, _errno.EROFS):
            from zhjj_agent.constants import ERR_PERMISSION_DENIED

            return PackageManagerError(ERR_PERMISSION_DENIED, f"{context}: 权限不足 ({error})")
        return PackageManagerError(ERR_STAGING_FAILED, f"{context}: IO 失败 ({error})")

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

        A5.2 要求覆盖的每一类都必须在**解压之前**判定，否则恶意包已经落了一半：
          1. 格式有效性（含损坏/截断 zip）
          2. Zip Slip 路径越界（绝对路径、盘符、`.`/`..`）
          3. 符号链接（Symlink）拒绝
          4. 重复路径 / 文件与目录同名冲突（大小写不敏感 —— Windows 上 `A` 与 `a` 是同一个文件）
          5. 文件总数、总大小、压缩膨胀比上限（Zip Bomb）
          6. 磁盘空间预检
        """
        if not zipfile.is_zipfile(zip_path):
            raise PackageManagerError(ERR_ZIP_INVALID, "目标文件不是合法的 ZIP 包")

        abs_target_dir = os.path.abspath(target_dir)
        real_target_dir = os.path.realpath(abs_target_dir)
        total_uncompressed = 0
        extracted_files = []

        try:
            with zipfile.ZipFile(zip_path, "r") as zf:
                infolist = zf.infolist()
                if len(infolist) > cls.MAX_FILE_COUNT:
                    raise PackageManagerError(
                        ERR_ZIP_BOMB,
                        f"ZIP 内文件数 ({len(infolist)}) 超过安全限制 ({cls.MAX_FILE_COUNT})"
                    )

                # ① 名称审计（在读取任何字节之前完成）
                members = cls._audit_member_names(infolist)

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

                # 膨胀比检查
                ratio = total_uncompressed / compressed_size
                if total_uncompressed > 10 * 1024 * 1024 and ratio > cls.MAX_COMPRESSION_RATIO:
                    raise PackageManagerError(
                        ERR_ZIP_BOMB,
                        f"压缩膨胀比过高 ({ratio:.1f}x > {cls.MAX_COMPRESSION_RATIO}x)，疑为解压炸弹"
                    )

                # 磁盘预检
                cls.check_disk_space(abs_target_dir, total_uncompressed)

                # ② 执行解压（路径在审计阶段已归一化，这里再做一次 realpath 围栏兜底）
                os.makedirs(abs_target_dir, exist_ok=True)
                for info, rel in members:
                    dest_file = os.path.join(real_target_dir, *rel.split("/"))
                    dest_real = os.path.realpath(dest_file)
                    if dest_real != real_target_dir and not dest_real.startswith(real_target_dir + os.sep):
                        raise PackageManagerError(
                            ERR_ZIP_SLIP,
                            f"检测到路径穿越攻击 (Zip Slip): {info.filename}"
                        )
                    if info.is_dir():
                        os.makedirs(dest_file, exist_ok=True)
                    else:
                        os.makedirs(os.path.dirname(dest_file), exist_ok=True)
                        with zf.open(info) as source, open(dest_file, "wb") as target:
                            shutil.copyfileobj(source, target)
                        extracted_files.append(rel)
        except PackageManagerError:
            raise
        except (zipfile.BadZipFile, zlib.error, EOFError) as error:
            # 中央目录能读到、条目数据却坏了 = 文件损坏/被截断。
            raise PackageManagerError(ERR_ZIP_INVALID, f"技能包内容损坏: {error}")
        except OSError as error:
            raise cls._translate_os_error(error, "解压技能包失败")

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
    def _audit_member_names(cls, infolist) -> List[Tuple[zipfile.ZipInfo, str]]:
        """归一化并审计每个条目的相对路径。

        · 反斜杠统一成 `/`（ZIP 规范用 `/`，但攻击者会混用）
        · 绝对路径 / 盘符 / `..` / `.` ⇒ ZIP_SLIP
        · 控制字符、Windows 保留名、ADS(`:`)、结尾点或空格 ⇒ PATH_INVALID
        · 大小写不敏感地比较重复（Windows 上 `A.txt` 与 `a.txt` 是同一个文件）
        """
        seen_files = set()
        seen_dirs = set()
        members: List[Tuple[zipfile.ZipInfo, str]] = []

        for info in infolist:
            raw = info.filename
            if "\x00" in raw or any(ord(ch) < 32 for ch in raw):
                raise PackageManagerError(ERR_PATH_INVALID, f"ZIP 条目名含控制字符: {raw!r}")

            name = raw.replace("\\", "/")
            if name.startswith("/") or _DRIVE_PREFIX.match(name):
                raise PackageManagerError(ERR_ZIP_SLIP, f"ZIP 条目使用绝对路径: {raw}")

            parts = [p for p in name.split("/") if p != ""]
            if not parts:
                raise PackageManagerError(ERR_ZIP_INVALID, f"ZIP 条目名为空: {raw!r}")

            for part in parts:
                if part in (".", ".."):
                    raise PackageManagerError(ERR_ZIP_SLIP, f"检测到路径穿越攻击 (Zip Slip): {raw}")
                if part != part.strip(" ") or part.endswith("."):
                    raise PackageManagerError(ERR_PATH_INVALID, f"ZIP 条目名以点或空格结尾: {raw}")
                if ":" in part:
                    raise PackageManagerError(ERR_PATH_INVALID, f"ZIP 条目名含备用数据流符号: {raw}")
                if part.split(".")[0].upper() in _WINDOWS_RESERVED:
                    raise PackageManagerError(ERR_PATH_INVALID, f"ZIP 条目名是系统保留名: {raw}")

            rel = "/".join(parts)
            key = rel.casefold()
            is_dir = info.is_dir()

            if is_dir:
                if key in seen_files:
                    raise PackageManagerError(ERR_ZIP_DUPLICATE_PATH, f"ZIP 内目录与文件同名: {raw}")
                seen_dirs.add(key)
                members.append((info, rel))
                continue

            if key in seen_files or key in seen_dirs:
                raise PackageManagerError(ERR_ZIP_DUPLICATE_PATH, f"ZIP 内存在重复路径: {raw}")

            # 文件的祖先必须是目录，不能是另一个文件（`a` 与 `a/b` 同时出现会解压失败或互相覆盖）
            for depth in range(1, len(parts)):
                if "/".join(parts[:depth]).casefold() in seen_files:
                    raise PackageManagerError(ERR_ZIP_DUPLICATE_PATH, f"ZIP 内文件与目录冲突: {raw}")

            seen_files.add(key)
            members.append((info, rel))

        return members

    @classmethod
    def install_with_staging(
        cls,
        pkg_path: str,
        install_dir: str,
        skill_id: str,
        version: str
    ) -> Tuple[str, Dict[str, Any], List[str]]:
        """Staging 事务安装：
        先解压至技能根目录下的独立暂存目录 (.staging_*)，全部校验成功后再原子切换至版本目录 v<version>。
        若失败则清理 staging 目录，绝不污染既有已安装版本。
        返回 (final_version_dir, manifest, files)。
        """
        from zhjj_agent.install_transaction import InstallTransaction

        skill_key = str(skill_id)
        ver_str = str(version or "1.0.0")
        transaction = InstallTransaction(install_dir)
        skill_root = transaction.skill_root(skill_key, create=True)
        final_version_dir = transaction.version_dir(skill_key, ver_str)

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

            with transaction.lock(skill_key):
                final_version_dir = transaction.commit(staging_dir, skill_key, ver_str)

            return final_version_dir, manifest, files

        except PackageManagerError:
            if os.path.exists(staging_dir):
                shutil.rmtree(staging_dir, ignore_errors=True)
            raise
        except OSError as e:
            if os.path.exists(staging_dir):
                shutil.rmtree(staging_dir, ignore_errors=True)
            raise cls._translate_os_error(e, "Staging 安装事务失败")
        except Exception as e:
            if os.path.exists(staging_dir):
                shutil.rmtree(staging_dir, ignore_errors=True)
            raise PackageManagerError(ERR_STAGING_FAILED, f"Staging 安装事务失败: {e}")
