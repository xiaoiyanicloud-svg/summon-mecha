"""安装事务：固定版本目录、跨进程串行、原子激活 / 原子回滚 / 原子卸载（WBS A5.1/A5.3/A5.5）。

三层事实必须分开，混在一起就是"看起来成功、实际半死"：

  1. **版本目录** `skills/<skill_id>/v<version>` —— 固定、可枚举、可校验内容的落点。
     版本号与技能号都要过白名单，`..`、分隔符、Windows 保留名一律拒绝。
  2. **激活指针** `skills/<skill_id>/.active.json` —— "现在生效的是哪个版本"。
     用 临时文件 + `os.replace` 写入；进程在写入瞬间被 kill 也只会看到旧值或新值，
     不会看到半个 JSON。它和数据库/installed.json 是两个真相，**对不上时必须 fail-closed**。
  3. **跨进程锁** `state/locks/<skill_id>.lock` —— 安装、回滚、卸载都拿同一把锁。
     没有它，两个并发安装会在 `rename` 上互相踩；这类缺陷只在压力下出现，平时看不见。

安全边界（A5.2）：
  · 技能根目录若是指向安装目录之外的符号链接 → `PATH_ESCAPE`，绝不跟过去；
  · 内容完整性用 **目录树摘要**（相对路径 + 每个文件 sha256，排序后聚合）记录，
    这样 zip 解出来的包也能在回滚时核对"磁盘上的字节还是不是当初装的那份"；
  · 任何 OSError 都被翻译成明确的错误码（磁盘满 / 权限不足 / 其它 IO），
    不允许裸 OSError 冒到状态机外面变成"未知异常"。
"""

import errno
import hashlib
import json
import os
import re
import shutil
import tempfile
import time
import uuid
from typing import Any, Dict, Optional

from zhjj_agent.constants import (
    ERR_DISK_SPACE,
    ERR_PATH_ESCAPE,
    ERR_PATH_INVALID,
    ERR_PERMISSION_DENIED,
    ERR_RESTORE_MISMATCH,
    ERR_STAGING_FAILED,
)
from zhjj_agent.package_manager import PackageManagerError

# 技能号 / 版本号：只允许单段安全字符。刻意不允许分隔符与前导点，
#   `..` / `.` / `v1/../../etc` 这类输入到这里就应该死，而不是等到 os.path.join 之后。
_SAFE_COMPONENT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$")

# Windows 保留设备名：即使在 NTFS 上 `CON` 也打不开，装进去等于把后续所有 IO 弄死。
_WINDOWS_RESERVED = {
    "CON", "PRN", "AUX", "NUL",
    *(f"COM{i}" for i in range(1, 10)),
    *(f"LPT{i}" for i in range(1, 10)),
}

ACTIVE_FILE = ".active.json"
INSTALL_META_FILE = ".install-meta.json"


def validate_component(value: Any, label: str) -> str:
    """校验单段路径组件（技能号 / 版本号），非法即抛 `PATH_INVALID`。"""
    text = str(value if value is not None else "")
    if not _SAFE_COMPONENT.match(text):
        raise PackageManagerError(ERR_PATH_INVALID, f"{label} 非法: {text!r}（只允许字母数字与 . _ + -，且不得以点开头）")
    if text.upper() in _WINDOWS_RESERVED or text.upper().split(".")[0] in _WINDOWS_RESERVED:
        raise PackageManagerError(ERR_PATH_INVALID, f"{label} 是系统保留名: {text!r}")
    if text.endswith(".") or text.endswith(" "):
        raise PackageManagerError(ERR_PATH_INVALID, f"{label} 不得以点或空格结尾: {text!r}")
    return text


def translate_os_error(error: OSError, context: str) -> PackageManagerError:
    """把 OSError 翻译成明确错误码 —— 磁盘满 ≠ 权限不足 ≠ 其它 IO。"""
    code = getattr(error, "errno", None)
    if code in (errno.ENOSPC, errno.EDQUOT):
        return PackageManagerError(ERR_DISK_SPACE, f"{context}: 磁盘空间不足 ({error})")
    if code in (errno.EACCES, errno.EPERM, errno.EROFS):
        return PackageManagerError(ERR_PERMISSION_DENIED, f"{context}: 权限不足 ({error})")
    return PackageManagerError(ERR_STAGING_FAILED, f"{context}: IO 失败 ({error})")


def tree_digest(root: str) -> str:
    """目录内容摘要：对排序后的 (相对路径, 文件 sha256) 聚合。

    回滚时用它回答"磁盘上的文件还是当初装的那份吗"。跳过本模块自己的元数据文件，
    否则"写入摘要"这个动作本身会改变摘要（鸡生蛋）。
    """
    entries = []
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames.sort()
        for name in sorted(filenames):
            if name in (ACTIVE_FILE, INSTALL_META_FILE):
                continue
            full = os.path.join(dirpath, name)
            if os.path.islink(full):
                raise PackageManagerError(ERR_PATH_ESCAPE, f"版本目录内含符号链接，拒绝计算摘要: {full}")
            rel = os.path.relpath(full, root).replace(os.sep, "/")
            digest = hashlib.sha256()
            with open(full, "rb") as handle:
                for chunk in iter(lambda: handle.read(65536), b""):
                    digest.update(chunk)
            entries.append((rel, digest.hexdigest()))

    outer = hashlib.sha256()
    for rel, digest in entries:
        outer.update(rel.encode("utf-8"))
        outer.update(b"\0")
        outer.update(digest.encode("ascii"))
        outer.update(b"\n")
    return outer.hexdigest()


class SkillInstallLock:
    """跨进程安装锁（POSIX `flock` / Windows `msvcrt.locking`），超时即失败，不无限等待。

    为什么锁文件放在 `state/locks/` 而不是技能目录里：技能目录会被整体 rename / 删除，
    锁文件跟着被搬走就失去了互斥意义；锁必须待在一个**不参与安装事务**的路径下。
    """

    def __init__(self, path: str, timeout: float = 30.0, poll: float = 0.05):
        self.path = path
        self.timeout = timeout
        self.poll = poll
        self._handle = None

    def __enter__(self):
        try:
            os.makedirs(os.path.dirname(self.path), exist_ok=True)
            self._handle = open(self.path, "a+b")
        except OSError as error:
            # T4：安装目录不可写时，锁的失败也必须是受控的 PackageManagerError。
            self._handle = None
            raise translate_os_error(error, "获取安装锁失败")
        # 保证锁文件至少 1 字节：Windows 的字节锁不能锁在文件末尾之外。
        self._handle.seek(0, os.SEEK_END)
        if self._handle.tell() == 0:
            self._handle.write(b"\0")
            self._handle.flush()

        deadline = time.time() + self.timeout
        while True:
            try:
                self._try_lock()
                return self
            except OSError:
                if time.time() >= deadline:
                    self._handle.close()
                    self._handle = None
                    raise PackageManagerError(
                        ERR_STAGING_FAILED,
                        f"等待安装锁超时（{self.timeout}s）：另一个安装/卸载/回滚仍在进行",
                    )
                time.sleep(self.poll)

    def __exit__(self, exc_type, exc, tb):
        if self._handle is None:
            return False
        try:
            self._unlock()
        finally:
            self._handle.close()
            self._handle = None
        return False

    def _try_lock(self):
        assert self._handle is not None
        if os.name == "nt":
            import msvcrt

            self._handle.seek(0)
            msvcrt.locking(self._handle.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl

            fcntl.flock(self._handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)

    def _unlock(self):
        assert self._handle is not None
        if os.name == "nt":
            import msvcrt

            self._handle.seek(0)
            msvcrt.locking(self._handle.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl

            fcntl.flock(self._handle.fileno(), fcntl.LOCK_UN)


class InstallTransaction:
    """固定版本目录 + 原子激活/回滚/卸载。所有写操作都在技能级跨进程锁内完成。"""

    def __init__(self, install_dir: str):
        self.install_dir = os.path.abspath(install_dir)

    # ── 路径与围栏 ───────────────────────────────────────────────────────────

    def skills_root(self) -> str:
        return os.path.join(self.install_dir, "skills")

    def skill_root(self, skill_id: str, create: bool = False) -> str:
        skill_id = validate_component(skill_id, "skill_id")
        base = os.path.realpath(self.skills_root())
        root = os.path.join(self.skills_root(), skill_id)

        if create:
            try:
                os.makedirs(base, exist_ok=True)
            except OSError as error:
                raise translate_os_error(error, "创建技能根目录失败")

        if os.path.islink(root):
            raise PackageManagerError(ERR_PATH_ESCAPE, f"技能根目录是符号链接，拒绝进入: {root}")

        real = os.path.realpath(root)
        if real != os.path.join(base, skill_id):
            raise PackageManagerError(ERR_PATH_ESCAPE, f"技能根目录逃逸出安装目录: {root} -> {real}")

        if create:
            # T4：真实 EACCES / ENOSPC 必须变成明确错误码，不能裸 OSError 冒到状态机外。
            try:
                os.makedirs(root, exist_ok=True)
            except OSError as error:
                raise translate_os_error(error, "创建技能根目录失败")
        return root

    def version_dir(self, skill_id: str, version: str) -> str:
        version = validate_component(version, "version")
        return os.path.join(self.skill_root(skill_id), "v" + version)

    def lock_path(self, skill_id: str) -> str:
        return os.path.join(self.install_dir, "state", "locks", f"{validate_component(skill_id, 'skill_id')}.lock")

    def lock(self, skill_id: str, timeout: float = 30.0) -> SkillInstallLock:
        return SkillInstallLock(self.lock_path(skill_id), timeout=timeout)

    # ── 激活指针（原子） ─────────────────────────────────────────────────────

    def read_active(self, skill_id: str) -> Optional[Dict[str, Any]]:
        path = os.path.join(self.skill_root(skill_id), ACTIVE_FILE)
        if not os.path.isfile(path):
            return None
        try:
            with open(path, "r", encoding="utf-8") as handle:
                data = json.load(handle)
        except (OSError, ValueError):
            # 指针损坏 = 事实不可信。返回 None 让调用方按 installed.json / 目录回退，
            # 而不是拿半截 JSON 去决定"现在生效的是哪一版"。
            return None
        return data if isinstance(data, dict) else None

    def activate(self, skill_id: str, version: str, **extra: Any) -> Dict[str, Any]:
        """原子写入激活指针。先写临时文件 + fsync，再 `os.replace`。"""
        version = validate_component(version, "version")
        root = self.skill_root(skill_id, create=True)
        payload = {
            "skill_id": str(skill_id),
            "version": version,
            "activated_at": int(time.time()),
        }
        payload.update({k: v for k, v in extra.items() if v is not None})

        target = os.path.join(root, ACTIVE_FILE)
        tmp = target + f".tmp-{uuid.uuid4().hex}"
        try:
            with open(tmp, "w", encoding="utf-8") as handle:
                json.dump(payload, handle, ensure_ascii=False, sort_keys=True)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(tmp, target)
            self._fsync_dir(root)
        except OSError as error:
            if os.path.exists(tmp):
                try:
                    os.remove(tmp)
                except OSError:
                    pass
            raise translate_os_error(error, "写激活指针失败")
        return payload

    def deactivate(self, skill_id: str) -> None:
        """删除激活指针（回滚失败且没有"上一版指针"时用，避免指针与文件状态不一致）。"""
        path = os.path.join(self.skill_root(skill_id), ACTIVE_FILE)
        try:
            if os.path.exists(path):
                os.remove(path)
        except OSError as error:
            raise translate_os_error(error, "删除激活指针失败")

    # ── 版本目录 ─────────────────────────────────────────────────────────────

    def new_staging(self, skill_id: str) -> str:
        """在技能根目录下建 staging —— 与最终目录同一文件系统，`rename` 才是原子的。"""
        root = self.skill_root(skill_id, create=True)
        try:
            return tempfile.mkdtemp(prefix=".staging_", dir=root)
        except OSError as error:
            # T4：目录不可写（EACCES）或盘满（ENOSPC）都在这里落地成受控错误码。
            raise translate_os_error(error, "创建 staging 目录失败")

    def commit(self, staging_dir: str, skill_id: str, version: str, meta: Optional[Dict[str, Any]] = None) -> str:
        """把 staging 原子切换成版本目录，并立即清掉旧目录备份（"一步到位"版本）。

        需要"装失败就把旧版本放回去"的调用方，用 `begin_commit` / `finish_commit` / `abort_commit`。
        """
        target, backup = self.begin_commit(staging_dir, skill_id, version, meta)
        self.finish_commit(backup)
        return target

    def begin_commit(self, staging_dir: str, skill_id: str, version: str, meta: Optional[Dict[str, Any]] = None) -> tuple:
        """两阶段提交的第一步：staging 就位，**旧版本目录保留为 backup**，等健康检查通过再删。

        为什么必须两阶段：`rename` 成功只证明"文件搬过去了"，不证明"它能用"。
        适配器安装/健康探针失败时，磁盘上必须能回到旧版本，否则一次坏升级会把设备留在
        一个既非旧版也非可用新版的中间态 —— 而设备通常没有人在旁边手动修。

        调用方必须已持有该技能的锁（本方法不重复加锁，避免同进程二次加锁死等）。
        """
        target = self.version_dir(skill_id, version)
        os.makedirs(os.path.dirname(target), exist_ok=True)

        staging_real = os.path.realpath(staging_dir)
        if not staging_real.startswith(os.path.realpath(os.path.dirname(target)) + os.sep):
            raise PackageManagerError(ERR_PATH_ESCAPE, f"staging 不在技能根目录内: {staging_dir}")

        backup = None
        try:
            if os.path.exists(target):
                backup = target + f".old-{uuid.uuid4().hex}"
                os.replace(target, backup)

            os.replace(staging_dir, target)
        except OSError as error:
            if backup and os.path.exists(backup) and not os.path.exists(target):
                try:
                    os.replace(backup, target)
                except OSError:
                    pass
            raise translate_os_error(error, "原子切换版本目录失败")

        self._write_install_meta(target, skill_id, version, meta or {})
        return target, backup

    def finish_commit(self, backup: Optional[str]) -> None:
        if backup and os.path.exists(backup):
            shutil.rmtree(backup, ignore_errors=True)

    def abort_commit(self, target: str, backup: Optional[str]) -> None:
        """两阶段提交的失败分支：删掉半成品，把旧版本目录放回原位。"""
        if os.path.isdir(target):
            shutil.rmtree(target, ignore_errors=True)
        if backup and os.path.exists(backup):
            os.replace(backup, target)

    def rollback_to(self, skill_id: str, version: str) -> str:
        """原子回滚：只切换激活指针，不搬文件（旧版本目录本来就在）。调用方持锁。"""
        target = self.version_dir(skill_id, version)
        if not os.path.isdir(target) or os.path.islink(target):
            raise PackageManagerError(ERR_PATH_INVALID, f"回滚目标版本目录不存在: {target}")

        meta = self.read_install_meta(target)
        self.activate(
            skill_id,
            version,
            package_sha256=(meta or {}).get("package_sha256"),
            tree_sha256=(meta or {}).get("tree_sha256"),
            reason="rollback",
        )
        return target

    def uninstall(self, skill_id: str) -> bool:
        """原子卸载：先整体 rename 走（对外瞬间消失），再异步式的删除。调用方持锁。"""
        root = self.skill_root(skill_id)
        if not os.path.isdir(root):
            return False

        trash = os.path.join(self.skills_root(), f".removed_{validate_component(skill_id, 'skill_id')}_{uuid.uuid4().hex}")
        try:
            os.replace(root, trash)
        except OSError as error:
            raise translate_os_error(error, "原子卸载失败")

        shutil.rmtree(trash, ignore_errors=True)
        return True

    # ── 元数据与完整性 ───────────────────────────────────────────────────────

    def write_meta(self, version_dir: str, meta: Dict[str, Any]) -> None:
        root = os.path.dirname(version_dir)
        payload = dict(meta)
        payload["tree_sha256"] = tree_digest(version_dir)
        target = os.path.join(version_dir, INSTALL_META_FILE)
        tmp = target + ".tmp"
        try:
            with open(tmp, "w", encoding="utf-8") as handle:
                json.dump(payload, handle, ensure_ascii=False, sort_keys=True)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(tmp, target)
            self._fsync_dir(root)
        except OSError as error:
            raise translate_os_error(error, "写安装元数据失败")

    def read_install_meta(self, version_dir: str) -> Optional[Dict[str, Any]]:
        path = os.path.join(version_dir, INSTALL_META_FILE)
        if not os.path.isfile(path):
            return None
        try:
            with open(path, "r", encoding="utf-8") as handle:
                data = json.load(handle)
        except (OSError, ValueError):
            return None
        return data if isinstance(data, dict) else None

    def verify_tree(self, version_dir: str, expected_tree_sha256: Optional[str] = None) -> str:
        """重算目录摘要并与记录值比对；不一致即 `RESTORE_MISMATCH`。返回实际摘要。"""
        actual = tree_digest(version_dir)
        if expected_tree_sha256 and actual != expected_tree_sha256.lower():
            raise PackageManagerError(
                ERR_RESTORE_MISMATCH,
                f"版本目录内容摘要与安装时记录不符: 实际 {actual} / 记录 {expected_tree_sha256}",
            )
        return actual

    def _write_install_meta(self, version_dir: str, skill_id: str, version: str, meta: Dict[str, Any]) -> None:
        payload = {
            "skill_id": str(skill_id),
            "version": str(version),
            "package_sha256": (meta.get("package_sha256") or None),
            "installed_at": int(time.time()),
        }
        payload.update({k: v for k, v in meta.items() if v is not None})
        self.write_meta(version_dir, payload)

    @staticmethod
    def _fsync_dir(path: str) -> None:
        """目录项落盘（POSIX 才有效；Windows 不支持对目录 fsync，静默跳过）。"""
        if os.name == "nt":
            return
        try:
            fd = os.open(path, os.O_RDONLY)
        except OSError:
            return
        try:
            os.fsync(fd)
        except OSError:
            pass
        finally:
            os.close(fd)
