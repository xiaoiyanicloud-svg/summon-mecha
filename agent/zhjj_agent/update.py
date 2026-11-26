"""设备侧 Agent 自更新闭环（下载 → 验签 → 验哈希 → 落盘 → 原子切换 → 启动自检/回滚）。

本模块只做一件事：把平台 `/api/agent/self-update` 给出的**已签名工件**变成设备上
一个可回滚的新版本目录。它不负责调度（何时检查），也不负责技能安装。

──────────────────────────────────────────────────────────────────────────
一、状态机（每个目标版本一行；`agent_update.version` 是主键）
──────────────────────────────────────────────────────────────────────────
    IDLE → CHECKING → DOWNLOADING → VERIFIED → STAGED → SWITCHED → HEALTHY
                          │            │          │
                          └────────────┴──────────┴──→ FAILED
    SWITCHED ──(连续 2 次启动自检失败, 仅一次)──→ ROLLED_BACK

    · CHECKING 也可直接 → FAILED（清单验签/时钟/版本/重放被拒，必须留下稳定错误码）。
    · FAILED → CHECKING 允许（同一版本重试；版本号单调性不受影响）。
    · 其余迁移一律拒绝（`UpdateStateStore.transition` 用 BEGIN IMMEDIATE 校验）。
    · 每次迁移都在跨进程锁 `state/locks/agent_update.lock` 内完成。

──────────────────────────────────────────────────────────────────────────
二、本地布局
──────────────────────────────────────────────────────────────────────────
    <install_dir>/agent/versions/<version>/zhjj_agent/...
    <install_dir>/agent/versions/.downloads/<version>.part      # 下载临时文件
    <install_dir>/agent/versions/.staging-<uuid>/               # 解包临时目录
    <install_dir>/agent/current.json / previous.json            # 原子指针
    <install_dir>/state/locks/agent_update.lock                 # 跨进程锁
    <install_dir>/state/agent.db                                # SQLite（复用 Storage）

──────────────────────────────────────────────────────────────────────────
三、不变量（实现必须逐条满足）
──────────────────────────────────────────────────────────────────────────
    I1 未验签、未验哈希的字节流不得成为可执行版本；未完成 fsync 不得切指针。
    I2 current.json 任何时刻只指向「完整且已校验」的版本目录；否则启动回退 previous。
    I3 版本严格递增；manifest.ts 与本地时钟偏差 > 300s 拒绝；旧清单重放拒绝。
    I4 切换前必须存在 previous.json；新版本启动自检连续失败 2 次 → 自动回滚且只回滚一次。
    I5 更新全程持跨进程锁；存在活动执行租约时拒绝更新（UPDATE_BLOCKED_ACTIVE_EXECUTION）。
    I6 分块 64KiB 下载、总量上限 64MiB；开始前校验剩余磁盘 ≥ 2×包大小 + 64MiB。
    I7 失败必须留下稳定 error_code 与阶段；不得让裸异常栈终止进程。
    I8 日志不得包含 secret/私钥/带 query 的签名 URL（只记 host+path）。

──────────────────────────────────────────────────────────────────────────
四、对外接口（契约固定）
──────────────────────────────────────────────────────────────────────────
    UpdateCoordinator.run_once(current_version) -> UpdateOutcome
    ManifestVerifier.verify(manifest, current_version, now_ts) -> None（失败抛 UpdateError）
    ArtifactFetcher.fetch(url, dest, expected_sha256, max_bytes) -> sha256
    VersionSwitcher.stage/switch_to/rollback
    UpdateStateStore.transition(...)  # BEGIN IMMEDIATE + 非法迁移拒绝
    BootGuard.evaluate_boot() -> BootDecision
"""

import errno
import json
import math
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import asdict, dataclass, field
from typing import Any, Dict, List, Optional, Tuple

from zhjj_agent.install_transaction import SkillInstallLock, validate_component
from zhjj_agent.package_manager import PackageManager, PackageManagerError
from zhjj_agent.telemetry import log_event

# ── 阶段 ────────────────────────────────────────────────────────────────────

PHASE_IDLE = "IDLE"
PHASE_CHECKING = "CHECKING"
PHASE_DOWNLOADING = "DOWNLOADING"
PHASE_VERIFIED = "VERIFIED"
PHASE_STAGED = "STAGED"
PHASE_SWITCHED = "SWITCHED"
PHASE_HEALTHY = "HEALTHY"
PHASE_FAILED = "FAILED"
PHASE_ROLLED_BACK = "ROLLED_BACK"

ALL_PHASES = (
    PHASE_IDLE,
    PHASE_CHECKING,
    PHASE_DOWNLOADING,
    PHASE_VERIFIED,
    PHASE_STAGED,
    PHASE_SWITCHED,
    PHASE_HEALTHY,
    PHASE_FAILED,
    PHASE_ROLLED_BACK,
)

#: 合法迁移表。`CHECKING → FAILED` 是清单阶段被拒时的落点（I7 要求失败必须留阶段）。
ALLOWED_TRANSITIONS: Dict[str, frozenset] = {
    PHASE_IDLE: frozenset({PHASE_CHECKING}),
    PHASE_CHECKING: frozenset({PHASE_DOWNLOADING, PHASE_FAILED}),
    PHASE_DOWNLOADING: frozenset({PHASE_VERIFIED, PHASE_FAILED}),
    PHASE_VERIFIED: frozenset({PHASE_STAGED, PHASE_FAILED}),
    PHASE_STAGED: frozenset({PHASE_SWITCHED, PHASE_FAILED}),
    PHASE_SWITCHED: frozenset({PHASE_HEALTHY, PHASE_ROLLED_BACK}),
    PHASE_HEALTHY: frozenset(),
    PHASE_FAILED: frozenset({PHASE_CHECKING}),
    PHASE_ROLLED_BACK: frozenset(),
}

# ── 错误码（稳定、可上报、可告警）───────────────────────────────────────────

ERR_UPDATE_NO_UPDATE = "UPDATE_NO_UPDATE"
ERR_UPDATE_MANIFEST_INVALID = "UPDATE_MANIFEST_INVALID"
ERR_UPDATE_MANIFEST_FETCH_FAILED = "UPDATE_MANIFEST_FETCH_FAILED"
ERR_UPDATE_SIGNATURE_INVALID = "UPDATE_SIGNATURE_INVALID"
ERR_UPDATE_CLOCK_SKEW = "UPDATE_CLOCK_SKEW"
ERR_UPDATE_VERSION_NOT_NEWER = "UPDATE_VERSION_NOT_NEWER"
ERR_UPDATE_MANIFEST_REPLAY = "UPDATE_MANIFEST_REPLAY"
ERR_UPDATE_URL_INVALID = "UPDATE_URL_INVALID"
ERR_UPDATE_DOWNLOAD_FAILED = "UPDATE_DOWNLOAD_FAILED"
ERR_UPDATE_SIZE_LIMIT = "UPDATE_SIZE_LIMIT"
ERR_UPDATE_DISK_SPACE = "UPDATE_DISK_SPACE"
ERR_UPDATE_ARTIFACT_HASH_MISMATCH = "UPDATE_ARTIFACT_HASH_MISMATCH"
ERR_UPDATE_ARTIFACT_INVALID = "UPDATE_ARTIFACT_INVALID"
ERR_UPDATE_VERSION_DIR_EXISTS = "UPDATE_VERSION_DIR_EXISTS"
ERR_UPDATE_STAGE_FAILED = "UPDATE_STAGE_FAILED"
ERR_UPDATE_CURRENT_MISSING = "UPDATE_CURRENT_MISSING"
ERR_UPDATE_PREVIOUS_MISSING = "UPDATE_PREVIOUS_MISSING"
ERR_UPDATE_SWITCH_FAILED = "UPDATE_SWITCH_FAILED"
ERR_UPDATE_INVALID_TRANSITION = "UPDATE_INVALID_TRANSITION"
ERR_UPDATE_VERSION_BLOCKED = "UPDATE_VERSION_BLOCKED"
ERR_UPDATE_LOCK_TIMEOUT = "UPDATE_LOCK_TIMEOUT"
ERR_UPDATE_BLOCKED_ACTIVE_EXECUTION = "UPDATE_BLOCKED_ACTIVE_EXECUTION"
ERR_UPDATE_HEALTH_FAILED = "UPDATE_HEALTH_FAILED"
ERR_UPDATE_ROLLBACK_FAILED = "UPDATE_ROLLBACK_FAILED"
ERR_UPDATE_INTERNAL = "UPDATE_INTERNAL"

# ── 规模与时间常量 ──────────────────────────────────────────────────────────

CHUNK_SIZE = 64 * 1024
MAX_ARTIFACT_BYTES = 64 * 1024 * 1024
DISK_HEADROOM_BYTES = 64 * 1024 * 1024
MAX_CLOCK_SKEW_SECONDS = 300
MAX_BOOT_ATTEMPTS = 2
DEFAULT_HTTP_TIMEOUT = 30.0
DEFAULT_LOCK_TIMEOUT = 30.0
SELF_CHECK_TIMEOUT = 60.0

UPDATE_CANONICAL_PREFIX = "agent_self_update"

#: 本地指标键（闭集）。**不**并入 `AgentMetrics.AGENT_METRIC_KEYS` —— 平台那个键集是
#: 闭合的，加新键会被 4xx 拒绝、连累整批计数。自更新指标先落本地，待平台扩键后再上报。
UPDATE_METRIC_KEYS = (
    "agent_update_check_total",
    "agent_update_available_total",
    "agent_update_manifest_rejected_total",
    "agent_update_download_bytes_total",
    "agent_update_hash_mismatch_total",
    "agent_update_switched_total",
    "agent_update_failed_total",
    "agent_update_rollback_total",
    "agent_update_boot_check_failed_total",
    "agent_update_healthy_total",
)

_CURRENT_FILE = "current.json"
_PREVIOUS_FILE = "previous.json"
_UPDATE_META_FILE = ".update-meta.json"
_AGENT_DIR = "agent"
_STATE_DIR = "state"
_DOWNLOADS_DIR = ".downloads"

_HEX64 = re.compile(r"^[0-9a-fA-F]{64}$")


class UpdateError(Exception):
    """自更新受控失败：必须携带稳定错误码与阶段，不允许裸异常冒到调用方。"""

    def __init__(self, code: str, message: str, phase: Optional[str] = None):
        super().__init__(message)
        self.code = code
        self.message = message
        self.phase = phase


@dataclass
class UpdateOutcome:
    success: bool
    phase: str
    version: Optional[str] = None
    current_version: Optional[str] = None
    updated: bool = False
    error_code: Optional[str] = None
    message: str = ""

    def to_dict(self) -> Dict[str, Any]:
        return asdict(self)


@dataclass
class BootDecision:
    action: str                       # run | restart_required | rollback_and_run | abort
    version: Optional[str] = None
    version_dir: Optional[str] = None
    error_code: Optional[str] = None
    rollback_performed: bool = False
    detail: str = ""


# ── 版本比较（镜像平台 `AgentVersion::compare` 的常见语义）───────────────────

_VERSION_SEGMENT = re.compile(r"^(\d*)(.*)$")


def _normalize_version(value: Any) -> str:
    text = str(value if value is not None else "").strip()
    if text[:1] in ("v", "V"):
        text = text[1:]
    return text or "0"


def _parse_version(value: Any) -> List[Tuple[int, str]]:
    segments: List[Tuple[int, str]] = []
    for raw in re.split(r"[._+-]", _normalize_version(value)):
        match = _VERSION_SEGMENT.match(raw)
        numeric, suffix = match.group(1), match.group(2)
        segments.append((int(numeric) if numeric else 0, suffix))
    return segments


def compare_versions(left: Any, right: Any) -> int:
    """`left` < `right` 返回 -1，相等 0，大于 1。

    语义要点：`0.10.0 > 0.9.9`；`v0.3.0 == 0.3.0`；`1.0.0-beta < 1.0.0`
    （空后缀比非空后缀「更高」，与 PHP `version_compare` 一致）。
    """
    left_segments = _parse_version(left)
    right_segments = _parse_version(right)
    for index in range(max(len(left_segments), len(right_segments))):
        left_num, left_suffix = left_segments[index] if index < len(left_segments) else (0, "")
        right_num, right_suffix = right_segments[index] if index < len(right_segments) else (0, "")
        if left_num != right_num:
            return -1 if left_num < right_num else 1
        if left_suffix != right_suffix:
            if left_suffix == "":
                return 1
            if right_suffix == "":
                return -1
            return -1 if left_suffix < right_suffix else 1
    return 0


def canonical_update_string(version: str, url: str, artifact_hash: str, ts: int) -> str:
    """与平台 `AgentSelfUpdateService::canonical()` 逐字一致。"""
    return "|".join([UPDATE_CANONICAL_PREFIX, str(version), str(url), str(artifact_hash), str(int(ts))])


def safe_url(url: str) -> str:
    """日志用 URL：只保留 host[:port] + path，绝不落 query（签名串就在 query 里）。"""
    parsed = urllib.parse.urlparse(str(url))
    netloc = parsed.netloc
    if "@" in netloc:
        netloc = netloc.rsplit("@", 1)[1]
    return f"{netloc}{parsed.path or '/'}"


# ── 清单校验（纯函数）──────────────────────────────────────────────────────

class ManifestVerifier:
    """校验平台下发的更新清单。失败抛 `UpdateError`，成功返回 None。"""

    def __init__(self, public_key_pem: str, max_skew: int = MAX_CLOCK_SKEW_SECONDS):
        if not public_key_pem or not str(public_key_pem).strip():
            raise ValueError("ManifestVerifier 需要平台验签公钥（public_key / public_key_inline）")
        self.public_key_pem = public_key_pem
        self.max_skew = int(max_skew)

    def verify(self, manifest: Dict[str, Any], current_version: Optional[str], now_ts: int) -> None:
        if not isinstance(manifest, dict):
            raise UpdateError(ERR_UPDATE_MANIFEST_INVALID, "更新清单不是对象", PHASE_CHECKING)
        if manifest.get("update_available") is not True:
            raise UpdateError(
                ERR_UPDATE_NO_UPDATE,
                f"平台无可用更新: {manifest.get('reason') or 'UP_TO_DATE'}",
                PHASE_CHECKING,
            )

        version = manifest.get("version")
        url = manifest.get("url")
        artifact_hash = manifest.get("hash")
        ts = manifest.get("ts")
        signature = manifest.get("signature")

        if not isinstance(version, str) or not version.strip():
            raise UpdateError(ERR_UPDATE_MANIFEST_INVALID, "清单缺少 version", PHASE_CHECKING)
        try:
            validate_component(version, "version")
        except PackageManagerError as error:
            raise UpdateError(ERR_UPDATE_MANIFEST_INVALID, error.message, PHASE_CHECKING)

        if not isinstance(url, str) or not url:
            raise UpdateError(ERR_UPDATE_MANIFEST_INVALID, "清单缺少 url", PHASE_CHECKING)
        parsed = urllib.parse.urlparse(url)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            raise UpdateError(ERR_UPDATE_URL_INVALID, f"清单 url 非法: {safe_url(url)}", PHASE_CHECKING)
        if parsed.username or parsed.password:
            raise UpdateError(ERR_UPDATE_URL_INVALID, "清单 url 不得携带用户凭据", PHASE_CHECKING)

        if not isinstance(artifact_hash, str) or not _HEX64.match(artifact_hash.strip()):
            raise UpdateError(ERR_UPDATE_MANIFEST_INVALID, "清单 hash 必须是 64 位十六进制", PHASE_CHECKING)

        if isinstance(ts, bool) or not isinstance(ts, (int, str)):
            raise UpdateError(ERR_UPDATE_MANIFEST_INVALID, "清单 ts 必须是整数时间戳", PHASE_CHECKING)
        try:
            ts_int = int(ts)
        except (TypeError, ValueError):
            raise UpdateError(ERR_UPDATE_MANIFEST_INVALID, "清单 ts 必须是整数时间戳", PHASE_CHECKING)

        if not isinstance(signature, str) or not signature.strip():
            raise UpdateError(ERR_UPDATE_SIGNATURE_INVALID, "清单缺少 signature", PHASE_CHECKING)

        # ① 验签（先于一切"可更新"判断：未验签的字段不得参与决策）
        from zhjj_agent.crypto import verify_signature

        canonical = canonical_update_string(version, url, artifact_hash, ts_int)
        if not verify_signature(canonical, signature, self.public_key_pem):
            raise UpdateError(ERR_UPDATE_SIGNATURE_INVALID, "更新清单 RSA-SHA256 验签失败", PHASE_CHECKING)

        # ② 时钟偏差（I3）：过期或来自未来的清单一律拒绝
        skew = abs(int(now_ts) - ts_int)
        if skew > self.max_skew:
            raise UpdateError(
                ERR_UPDATE_CLOCK_SKEW,
                f"清单时间戳偏差 {skew}s 超过允许的 {self.max_skew}s",
                PHASE_CHECKING,
            )

        # ③ 版本严格递增（I3）：等于或低于当前版本一律拒绝
        if current_version and str(current_version).strip():
            if compare_versions(version, current_version) <= 0:
                raise UpdateError(
                    ERR_UPDATE_VERSION_NOT_NEWER,
                    f"清单版本 {version} 不高于当前 {current_version}",
                    PHASE_CHECKING,
                )


# ── 流式下载器 ──────────────────────────────────────────────────────────────

class ArtifactFetcher:
    """限量、限时、可清理的流式下载器（I6）。

    每次网络调用都带显式超时；重试次数有上限且使用指数退避（不是固定 sleep）；
    任何失败都删除半截文件，绝不留一个能被误当成完整工件的 `.part`。
    """

    def __init__(
        self,
        headers_provider=None,
        timeout: float = DEFAULT_HTTP_TIMEOUT,
        max_attempts: int = 2,
        chunk_size: int = CHUNK_SIZE,
    ):
        self.headers_provider = headers_provider
        self.timeout = timeout
        self.max_attempts = max(1, int(max_attempts))
        self.chunk_size = chunk_size

    def fetch(self, url: str, dest: str, expected_sha256: str, max_bytes: int = MAX_ARTIFACT_BYTES) -> str:
        parsed = urllib.parse.urlparse(str(url))
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            raise UpdateError(ERR_UPDATE_URL_INVALID, f"下载 url 非法: {safe_url(url)}")
        if expected_sha256 and not _HEX64.match(str(expected_sha256).strip()):
            raise UpdateError(ERR_UPDATE_ARTIFACT_HASH_MISMATCH, "期望哈希格式非法")

        os.makedirs(os.path.dirname(os.path.abspath(dest)), exist_ok=True)
        headers = self.headers_provider(url) if self.headers_provider else {}

        last_error: Optional[UpdateError] = None
        for attempt in range(self.max_attempts):
            try:
                return self._fetch_once(url, dest, expected_sha256, max_bytes, headers)
            except UpdateError as error:
                _remove_quietly(dest)
                # 确定性错误（哈希/上限/磁盘）重试没有意义，直接失败。
                if error.code in (
                    ERR_UPDATE_ARTIFACT_HASH_MISMATCH,
                    ERR_UPDATE_SIZE_LIMIT,
                    ERR_UPDATE_DISK_SPACE,
                    ERR_UPDATE_URL_INVALID,
                ):
                    raise
                last_error = error
                if attempt + 1 < self.max_attempts:
                    # 指数退避：0.5s, 1s, 2s…（有上界，不是固定 sleep 冒充重试）
                    time.sleep(min(4.0, 0.5 * (2 ** attempt)))

        assert last_error is not None
        raise last_error

    def _fetch_once(
        self,
        url: str,
        dest: str,
        expected_sha256: str,
        max_bytes: int,
        headers: Dict[str, str],
    ) -> str:
        import hashlib

        request = urllib.request.Request(url, method="GET")
        for key, value in (headers or {}).items():
            request.add_header(key, value)

        digest = hashlib.sha256()
        total = 0
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                content_length = response.headers.get("Content-Length")
                declared = int(content_length) if content_length and content_length.isdigit() else None
                if declared is not None and declared > max_bytes:
                    raise UpdateError(
                        ERR_UPDATE_SIZE_LIMIT,
                        f"工件声明大小 {declared} 超过上限 {max_bytes}",
                    )
                # I6：开始写任何字节之前校验磁盘（2×包大小 + 64MiB 余量）
                estimate = declared if declared is not None else max_bytes
                _assert_disk_headroom(os.path.dirname(os.path.abspath(dest)), estimate)

                with open(dest, "wb") as handle:
                    while True:
                        chunk = response.read(self.chunk_size)
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > max_bytes:
                            raise UpdateError(
                                ERR_UPDATE_SIZE_LIMIT,
                                f"工件体积超过上限 {max_bytes} 字节",
                            )
                        digest.update(chunk)
                        handle.write(chunk)
                        # 让崩溃/被杀时磁盘上的进度可见，也缩小缓冲区丢数据的窗口。
                        handle.flush()
                    handle.flush()
                    os.fsync(handle.fileno())
        except UpdateError:
            raise
        except urllib.error.HTTPError as error:
            raise UpdateError(ERR_UPDATE_DOWNLOAD_FAILED, f"下载 HTTP {error.code}: {error.reason}")
        except urllib.error.URLError as error:
            raise UpdateError(ERR_UPDATE_DOWNLOAD_FAILED, f"下载连接失败: {error.reason}")
        except (TimeoutError, OSError) as error:
            if getattr(error, "errno", None) in (errno.ENOSPC, errno.EDQUOT):
                raise UpdateError(ERR_UPDATE_DISK_SPACE, f"下载写入磁盘失败: {error}")
            raise UpdateError(ERR_UPDATE_DOWNLOAD_FAILED, f"下载 IO 失败: {error}")

        actual = digest.hexdigest().lower()
        if expected_sha256 and actual != expected_sha256.strip().lower():
            raise UpdateError(
                ERR_UPDATE_ARTIFACT_HASH_MISMATCH,
                f"工件 SHA-256 不匹配: 实际 {actual} / 期望 {expected_sha256}",
            )
        return actual


def _assert_disk_headroom(directory: str, artifact_bytes: int) -> None:
    required = 2 * max(0, int(artifact_bytes)) + DISK_HEADROOM_BYTES
    try:
        usage = shutil.disk_usage(directory)
    except OSError:
        # 无法读取磁盘信息时不阻塞更新（文件系统可能不支持 statvfs），
        # 真正写满会在写入阶段落成 DISK_SPACE。
        return
    if usage.free < required:
        raise UpdateError(
            ERR_UPDATE_DISK_SPACE,
            f"磁盘剩余 {usage.free} 字节，小于所需 {required} 字节",
        )


def _remove_quietly(path: str) -> None:
    try:
        if os.path.exists(path):
            os.remove(path)
    except OSError:
        pass


def _fsync_dir(path: str) -> None:
    """目录项落盘；Windows 不允许对目录取 fd，跳过（与 install_transaction 同口径）。"""
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


def _write_json_atomic(path: str, payload: Dict[str, Any]) -> None:
    """临时文件 + fsync + os.replace：崩溃只会在旧值/新值之间，不会读到半个 JSON。"""
    directory = os.path.dirname(os.path.abspath(path))
    os.makedirs(directory, exist_ok=True)
    tmp = f"{path}.tmp-{uuid.uuid4().hex}"
    try:
        with open(tmp, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, path)
        _fsync_dir(directory)
    except OSError:
        _remove_quietly(tmp)
        raise


# ── 状态仓 ──────────────────────────────────────────────────────────────────

class UpdateStateStore:
    """`agent_update` 表：阶段迁移、启动计数、本地指标。

    所有写操作都在 `BEGIN IMMEDIATE` 里做：拿写锁再读当前阶段，
    这样两个更新者（或更新者与启动守卫）不可能同时看到"同一个旧阶段"各写各的。
    """

    def __init__(self, db_path: str):
        self.db_path = os.path.abspath(db_path)
        os.makedirs(os.path.dirname(self.db_path), exist_ok=True)
        self._init_db()

    def _connect(self) -> sqlite3.Connection:
        connection = sqlite3.connect(self.db_path, timeout=10.0, isolation_level=None)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA journal_mode=WAL;")
        connection.execute("PRAGMA synchronous=FULL;")
        connection.execute("PRAGMA busy_timeout=10000;")
        return connection

    def _init_db(self) -> None:
        connection = self._connect()
        try:
            connection.executescript(
                """
                CREATE TABLE IF NOT EXISTS agent_update (
                    version TEXT PRIMARY KEY,
                    phase TEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    error_code TEXT,
                    artifact_sha256 TEXT,
                    started_at INTEGER,
                    completed_at INTEGER,
                    boot_attempts INTEGER NOT NULL DEFAULT 0,
                    last_healthy_at INTEGER,
                    last_manifest_ts INTEGER
                );

                CREATE TABLE IF NOT EXISTS agent_update_metric (
                    metric_key TEXT PRIMARY KEY,
                    value INTEGER NOT NULL DEFAULT 0,
                    updated_at INTEGER NOT NULL
                );
                """
            )
        finally:
            connection.close()

    # ── 行与迁移 ────────────────────────────────────────────────────────────

    def ensure(self, version: str, now_ts: Optional[int] = None) -> Dict[str, Any]:
        """幂等建行（IDLE）；已存在则原样返回。"""
        now = int(now_ts if now_ts is not None else time.time())
        connection = self._connect()
        try:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute("SELECT * FROM agent_update WHERE version = ?", (str(version),)).fetchone()
            if row is None:
                connection.execute(
                    "INSERT INTO agent_update (version, phase, attempts, started_at) VALUES (?, ?, 0, ?)",
                    (str(version), PHASE_IDLE, now),
                )
                row = connection.execute("SELECT * FROM agent_update WHERE version = ?", (str(version),)).fetchone()
            connection.commit()
            return dict(row)
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    def get(self, version: str) -> Optional[Dict[str, Any]]:
        connection = self._connect()
        try:
            row = connection.execute("SELECT * FROM agent_update WHERE version = ?", (str(version),)).fetchone()
            return dict(row) if row else None
        finally:
            connection.close()

    def transition(
        self,
        version: str,
        to_phase: str,
        expect_from: Optional[str] = None,
        **fields: Any,
    ) -> Dict[str, Any]:
        """原子阶段迁移；非法迁移抛 `UPDATE_INVALID_TRANSITION`。

        `fields` 只允许已知列，避免调用方拼出任意 SQL 列名。
        """
        if to_phase not in ALL_PHASES:
            raise UpdateError(ERR_UPDATE_INVALID_TRANSITION, f"未知阶段 {to_phase!r}")
        allowed_columns = {
            "error_code", "artifact_sha256", "started_at", "completed_at",
            "boot_attempts", "last_healthy_at", "last_manifest_ts",
        }
        unknown = set(fields) - allowed_columns
        if unknown:
            raise UpdateError(ERR_UPDATE_INVALID_TRANSITION, f"未知字段: {sorted(unknown)}")

        now = int(time.time())
        connection = self._connect()
        try:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute("SELECT * FROM agent_update WHERE version = ?", (str(version),)).fetchone()
            if row is None:
                connection.execute(
                    "INSERT INTO agent_update (version, phase, attempts, started_at) VALUES (?, ?, 0, ?)",
                    (str(version), PHASE_IDLE, now),
                )
                row = connection.execute("SELECT * FROM agent_update WHERE version = ?", (str(version),)).fetchone()

            current = str(row["phase"])
            if expect_from is not None and current != expect_from:
                raise UpdateError(
                    ERR_UPDATE_INVALID_TRANSITION,
                    f"版本 {version} 当前阶段 {current}，期望 {expect_from}",
                )
            if to_phase != current and to_phase not in ALLOWED_TRANSITIONS.get(current, frozenset()):
                raise UpdateError(
                    ERR_UPDATE_INVALID_TRANSITION,
                    f"非法阶段迁移 {current} → {to_phase}（版本 {version}）",
                )

            assignments = ["phase = ?"]
            values: List[Any] = [to_phase]
            for column in sorted(fields):
                assignments.append(f"{column} = ?")
                values.append(fields[column])

            if to_phase == PHASE_CHECKING:
                assignments.append("attempts = attempts + 1")
                assignments.append("started_at = COALESCE(started_at, ?)")
                values.append(now)
            if to_phase in (PHASE_HEALTHY, PHASE_FAILED, PHASE_ROLLED_BACK):
                assignments.append("completed_at = ?")
                values.append(now)
            if to_phase == PHASE_HEALTHY:
                assignments.append("boot_attempts = 0")

            values.append(str(version))
            connection.execute(
                f"UPDATE agent_update SET {', '.join(assignments)} WHERE version = ?",
                tuple(values),
            )
            updated = connection.execute(
                "SELECT * FROM agent_update WHERE version = ?", (str(version),)
            ).fetchone()
            connection.commit()
            return dict(updated)
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    def bump_boot_attempts(self, version: str) -> int:
        """启动计数 +1（在自检**之前**做：进程若在自检中崩溃，这一次也必须算数）。"""
        connection = self._connect()
        try:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute("SELECT boot_attempts FROM agent_update WHERE version = ?", (str(version),)).fetchone()
            if row is None:
                raise UpdateError(ERR_UPDATE_INVALID_TRANSITION, f"版本 {version} 没有更新记录，拒绝计数启动次数")
            connection.execute(
                "UPDATE agent_update SET boot_attempts = boot_attempts + 1 WHERE version = ?",
                (str(version),),
            )
            updated = connection.execute(
                "SELECT boot_attempts FROM agent_update WHERE version = ?", (str(version),)
            ).fetchone()
            connection.commit()
            return int(updated["boot_attempts"])
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    def touch_healthy(self, version: str) -> None:
        """已是 HEALTHY 的版本再次通过启动自检：只刷新时间，不做阶段迁移。"""
        connection = self._connect()
        try:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute(
                "UPDATE agent_update SET last_healthy_at = ? WHERE version = ?",
                (int(time.time()), str(version)),
            )
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    def get_last_manifest_ts(self, version: str) -> Optional[int]:
        row = self.get(version)
        if not row or row.get("last_manifest_ts") is None:
            return None
        return int(row["last_manifest_ts"])

    def set_last_manifest_ts(self, version: str, ts: int) -> None:
        connection = self._connect()
        try:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute(
                "UPDATE agent_update SET last_manifest_ts = ? WHERE version = ?",
                (int(ts), str(version)),
            )
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    # ── 本地指标 ────────────────────────────────────────────────────────────

    def incr_metric(self, key: str, by: int = 1) -> int:
        if key not in UPDATE_METRIC_KEYS:
            raise ValueError(f"未知自更新指标键 {key!r}")
        if by <= 0:
            raise ValueError("指标增量必须为正整数")
        connection = self._connect()
        try:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute(
                """
                INSERT INTO agent_update_metric (metric_key, value, updated_at) VALUES (?, ?, ?)
                ON CONFLICT(metric_key) DO UPDATE SET
                    value = value + excluded.value,
                    updated_at = excluded.updated_at
                """,
                (key, int(by), int(time.time())),
            )
            row = connection.execute(
                "SELECT value FROM agent_update_metric WHERE metric_key = ?", (key,)
            ).fetchone()
            connection.commit()
            return int(row["value"])
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    def metrics_snapshot(self) -> Dict[str, int]:
        connection = self._connect()
        try:
            rows = connection.execute("SELECT metric_key, value FROM agent_update_metric").fetchall()
            return {row["metric_key"]: int(row["value"]) for row in rows}
        finally:
            connection.close()


# ── 版本切换器 ──────────────────────────────────────────────────────────────

class VersionSwitcher:
    """版本目录落盘 + 指针原子切换/回滚（I1/I2/I4）。"""

    def __init__(self, install_dir: str):
        self.install_dir = os.path.abspath(install_dir)
        self.agent_dir = os.path.join(self.install_dir, _AGENT_DIR)
        self.versions_root = os.path.join(self.agent_dir, "versions")
        self.downloads_dir = os.path.join(self.versions_root, _DOWNLOADS_DIR)

    # ── 路径 ────────────────────────────────────────────────────────────────

    def version_dir(self, version: str) -> str:
        safe = validate_component(version, "version")
        return os.path.join(self.versions_root, safe)

    def current_path(self) -> str:
        return os.path.join(self.agent_dir, _CURRENT_FILE)

    def previous_path(self) -> str:
        return os.path.join(self.agent_dir, _PREVIOUS_FILE)

    def _ensure_dirs(self) -> None:
        os.makedirs(self.versions_root, exist_ok=True)
        os.makedirs(self.downloads_dir, exist_ok=True)

    # ── 指针 ────────────────────────────────────────────────────────────────

    def _read_pointer(self, path: str) -> Optional[Dict[str, Any]]:
        if not os.path.isfile(path):
            return None
        try:
            with open(path, "r", encoding="utf-8") as handle:
                payload = json.load(handle)
        except (OSError, ValueError):
            return None
        if not isinstance(payload, dict):
            return None

        version = payload.get("version")
        try:
            safe_version = validate_component(version, "version")
        except PackageManagerError:
            return None
        if payload.get("path") != f"versions/{safe_version}":
            return None

        target = os.path.join(self.versions_root, safe_version)
        if not self._is_complete(target):
            return None
        payload["version"] = safe_version
        payload["dir"] = target
        return payload

    def read_current(self) -> Optional[Dict[str, Any]]:
        return self._read_pointer(self.current_path())

    def read_previous(self) -> Optional[Dict[str, Any]]:
        return self._read_pointer(self.previous_path())

    def _write_pointer(self, path: str, payload: Dict[str, Any]) -> None:
        try:
            _write_json_atomic(path, payload)
        except OSError as error:
            raise UpdateError(ERR_UPDATE_SWITCH_FAILED, f"写指针失败（{os.path.basename(path)}）: {error}")

    @staticmethod
    def _is_complete(version_dir: str) -> bool:
        if not os.path.isdir(version_dir) or os.path.islink(version_dir):
            return False
        package_dir = os.path.join(version_dir, "zhjj_agent")
        return os.path.isfile(os.path.join(package_dir, "__init__.py")) and os.path.isfile(
            os.path.join(version_dir, _UPDATE_META_FILE)
        )

    # ── 落盘（stage）────────────────────────────────────────────────────────

    def stage(self, artifact_path: str, version: str) -> str:
        """安全解包到临时目录 → fsync → 原子 rename 成版本目录。

        目标目录已存在且完整 ⇒ 视为幂等重入，直接复用；存在但不完整 ⇒ 拒绝覆盖
        （绝不原地覆盖一个可能正在运行的版本目录）。
        """
        safe_version = validate_component(version, "version")
        target = self.version_dir(safe_version)

        if os.path.isdir(target):
            if self._is_complete(target):
                return target
            raise UpdateError(
                ERR_UPDATE_VERSION_DIR_EXISTS,
                f"版本目录已存在但不完整，拒绝覆盖: {target}",
            )

        self._ensure_dirs()
        staging = tempfile.mkdtemp(prefix=".staging-", dir=self.versions_root)
        try:
            try:
                PackageManager.inspect_and_unpack_zip(artifact_path, staging)
            except PackageManagerError as error:
                code = ERR_UPDATE_DISK_SPACE if error.code == "DISK_SPACE" else ERR_UPDATE_ARTIFACT_INVALID
                raise UpdateError(code, f"工件解包失败: {error.message}")

            if not os.path.isfile(os.path.join(staging, "zhjj_agent", "__init__.py")):
                raise UpdateError(
                    ERR_UPDATE_ARTIFACT_INVALID,
                    "工件根目录缺少 zhjj_agent/__init__.py（版本目录布局不符合约定）",
                )

            _fsync_tree(staging)

            try:
                _write_json_atomic(
                    os.path.join(staging, _UPDATE_META_FILE),
                    {
                        "version": safe_version,
                        # 工件哈希由调用方在 stage 之后用 write_version_meta 补齐；
                        # 这里只需要一个"目录已完整落盘"的标记文件。
                        "artifact_sha256": None,
                        "staged_at": int(time.time()),
                    },
                )
            except OSError as error:
                raise UpdateError(ERR_UPDATE_STAGE_FAILED, f"写版本元数据失败: {error}")

            try:
                os.replace(staging, target)
                _fsync_dir(self.versions_root)
            except OSError as error:
                raise UpdateError(ERR_UPDATE_STAGE_FAILED, f"原子落盘版本目录失败: {error}")
            return target
        finally:
            if os.path.isdir(staging):
                shutil.rmtree(staging, ignore_errors=True)

    def write_version_meta(self, version_dir: str, artifact_sha256: str) -> None:
        """补齐工件哈希（stage 时它可能还没算出来 —— 校验顺序是 stage 后写 meta 再切）。"""
        try:
            _write_json_atomic(
                os.path.join(version_dir, _UPDATE_META_FILE),
                {
                    "version": os.path.basename(version_dir),
                    "artifact_sha256": str(artifact_sha256),
                    "staged_at": int(time.time()),
                },
            )
        except OSError as error:
            raise UpdateError(ERR_UPDATE_STAGE_FAILED, f"写版本元数据失败: {error}")

    # ── 切换 / 回滚 ─────────────────────────────────────────────────────────

    def switch_to(self, version: str, artifact_sha256: Optional[str] = None) -> None:
        safe_version = validate_component(version, "version")
        target = self.version_dir(safe_version)
        if not self._is_complete(target):
            raise UpdateError(ERR_UPDATE_STAGE_FAILED, f"版本目录不完整，拒绝切换: {target}")

        current = self.read_current()
        if current is None:
            raise UpdateError(
                ERR_UPDATE_CURRENT_MISSING,
                "current.json 缺失或指向不完整的版本目录，无法切换到新版本（没有可回退的基线）",
            )

        # I4：切换前必须存在 previous.json。首次升级（还没有 previous）时，
        # 由即将被替换的 current 填充 —— 它本来就是已知可用的那个版本。
        if self.read_previous() is None:
            self._write_pointer(
                self.previous_path(),
                {
                    "version": current["version"],
                    "path": current["path"],
                    "artifact_sha256": current.get("artifact_sha256"),
                    "recorded_at": int(time.time()),
                    "reason": "pre-switch",
                },
            )

        if current["version"] == safe_version:
            return

        self._write_pointer(
            self.current_path(),
            {
                "version": safe_version,
                "path": f"versions/{safe_version}",
                "artifact_sha256": artifact_sha256,
                "switched_at": int(time.time()),
            },
        )

    def rollback(self) -> str:
        """把 current.json 原子指回 previous.json，并返回回滚到的版本号。"""
        previous = self.read_previous()
        if previous is None:
            raise UpdateError(ERR_UPDATE_PREVIOUS_MISSING, "previous.json 缺失或不可用，回滚失败")
        self._write_pointer(
            self.current_path(),
            {
                "version": previous["version"],
                "path": previous["path"],
                "artifact_sha256": previous.get("artifact_sha256"),
                "switched_at": int(time.time()),
                "reason": "rollback",
            },
        )
        return str(previous["version"])

    def cleanup_partials(self) -> int:
        """清理上次崩溃留下的下载/解包残骸。调用方必须已持有跨进程锁。"""
        removed = 0
        if os.path.isdir(self.downloads_dir):
            for name in os.listdir(self.downloads_dir):
                path = os.path.join(self.downloads_dir, name)
                if os.path.isfile(path):
                    _remove_quietly(path)
                    removed += 1
        if os.path.isdir(self.versions_root):
            for name in os.listdir(self.versions_root):
                if not name.startswith(".staging-"):
                    continue
                path = os.path.join(self.versions_root, name)
                if os.path.isdir(path):
                    shutil.rmtree(path, ignore_errors=True)
                    removed += 1
        return removed


def _fsync_tree(root: str) -> None:
    """把解包出来的每个文件刷到磁盘 —— 未完成 fsync 不得切指针（I1）。

    POSIX 允许对只读句柄 fsync；Windows 的 `FlushFileBuffers` 要求句柄可写，
    只读句柄会得到 EBADF。所以 Windows 直接以读写方式打开（不会改内容）。
    """
    for dirpath, _dirnames, filenames in os.walk(root):
        for name in filenames:
            path = os.path.join(dirpath, name)
            try:
                flags = os.O_RDWR if os.name == "nt" else os.O_RDONLY
                fd = os.open(path, flags)
            except OSError as error:
                raise UpdateError(ERR_UPDATE_STAGE_FAILED, f"打开文件以便 fsync 失败: {error}")
            try:
                os.fsync(fd)
            except OSError as error:
                raise UpdateError(ERR_UPDATE_STAGE_FAILED, f"fsync 失败: {error}")
            finally:
                os.close(fd)
        _fsync_dir(dirpath)


# ── 启动自检与守卫 ──────────────────────────────────────────────────────────

@dataclass
class SelfCheckResult:
    passed: bool
    reported_version: Optional[str] = None
    detail: str = ""


def run_self_check(version_dir: str, expected_version: str, timeout: float = SELF_CHECK_TIMEOUT) -> SelfCheckResult:
    """在目标版本目录里真实 import 一次 `zhjj_agent`。

    用子进程而不是同进程 import：同进程只能 import 一次，而且坏包会把守卫进程一起带死。
    """
    package_dir = os.path.join(version_dir, "zhjj_agent")
    if not os.path.isfile(os.path.join(package_dir, "__init__.py")):
        return SelfCheckResult(False, None, f"缺少 zhjj_agent/__init__.py: {version_dir}")

    code = (
        "import json, sys\n"
        "import zhjj_agent\n"
        "sys.stdout.write(json.dumps({'version': getattr(zhjj_agent, '__version__', '')}))\n"
    )
    env = dict(os.environ)
    env["PYTHONPATH"] = version_dir + (os.pathsep + env["PYTHONPATH"] if env.get("PYTHONPATH") else "")
    try:
        proc = subprocess.run(
            [sys.executable, "-c", code],
            cwd=version_dir,
            env=env,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except subprocess.TimeoutExpired:
        return SelfCheckResult(False, None, f"自检超时（{timeout}s）")
    except OSError as error:
        return SelfCheckResult(False, None, f"自检子进程启动失败: {error}")

    if proc.returncode != 0:
        tail = (proc.stderr or "").strip().splitlines()[-3:]
        return SelfCheckResult(False, None, f"自检退出码 {proc.returncode}: {' / '.join(tail)}")

    try:
        reported = str(json.loads((proc.stdout or "").strip() or "{}").get("version") or "")
    except ValueError:
        return SelfCheckResult(False, None, "自检输出不是合法 JSON")

    if not reported:
        return SelfCheckResult(False, None, "自检未报告 __version__")
    if str(reported) != str(expected_version):
        return SelfCheckResult(False, reported, f"自检报告版本 {reported} 与目录版本 {expected_version} 不一致")
    return SelfCheckResult(True, reported, "ok")


class BootGuard:
    """启动守卫：解析指针 → 计数 → 自检 → HEALTHY / 回滚（最多一次）。"""

    def __init__(
        self,
        install_dir: str,
        store: Optional[UpdateStateStore] = None,
        switcher: Optional[VersionSwitcher] = None,
        self_check=None,
        max_boot_attempts: int = MAX_BOOT_ATTEMPTS,
    ):
        self.install_dir = os.path.abspath(install_dir)
        self.switcher = switcher or VersionSwitcher(self.install_dir)
        # 惰性建库：`--self-check` 这类只读入口不该因为"看一眼状态"就造出 agent.db。
        self._store = store
        self.self_check = self_check or run_self_check
        self.max_boot_attempts = int(max_boot_attempts)

    @property
    def store(self) -> UpdateStateStore:
        if self._store is None:
            self._store = UpdateStateStore(os.path.join(self.install_dir, _STATE_DIR, "agent.db"))
        return self._store

    def resolve(self, repair: bool = True) -> Tuple[Optional[Dict[str, Any]], str]:
        """返回 (指针, 来源)。来源：current / previous / bundled。

        `repair=False` 时只读：不改写 current.json（供 `--self-check` 使用）。
        """
        current = self.switcher.read_current()
        if current is not None:
            return current, "current"

        previous = self.switcher.read_previous()
        if previous is not None:
            # I2：current 不可信 ⇒ 回退 previous，并把指针修好（原子写，不是原地改）。
            log_event(
                "WARN",
                "agent_update_pointer_fallback",
                status="previous",
                error_code=ERR_UPDATE_CURRENT_MISSING,
            )
            if repair:
                try:
                    self.switcher._write_pointer(
                        self.switcher.current_path(),
                        {
                            "version": previous["version"],
                            "path": previous["path"],
                            "artifact_sha256": previous.get("artifact_sha256"),
                            "switched_at": int(time.time()),
                            "reason": "pointer-fallback",
                        },
                    )
                except UpdateError:
                    # 指针修不好不影响"用 previous 启动"这件事本身。
                    pass
            return previous, "previous"

        return None, "bundled"

    def evaluate_boot(self) -> BootDecision:
        try:
            return self._evaluate_boot()
        except UpdateError as error:
            log_event("ERROR", "agent_update_boot_guard_failed", status="ABORT", error_code=error.code)
            return BootDecision("abort", error_code=error.code, detail=error.message)
        except Exception as error:  # 守卫自己绝不能把设备带崩
            log_event("ERROR", "agent_update_boot_guard_failed", status="ABORT", error_code=ERR_UPDATE_INTERNAL)
            return BootDecision("abort", error_code=ERR_UPDATE_INTERNAL, detail=type(error).__name__)

    def _evaluate_boot(self) -> BootDecision:
        pointer, source = self.resolve()
        if pointer is None:
            return BootDecision("run", error_code=None, detail="bundled-fallback")

        version = str(pointer["version"])
        version_dir = pointer["dir"]
        row = self.store.get(version)
        phase = str(row["phase"]) if row else None

        if phase == PHASE_SWITCHED:
            attempts = self.store.bump_boot_attempts(version)
            check = self.self_check(version_dir, version)
            if check.passed:
                self.store.transition(version, PHASE_HEALTHY, expect_from=PHASE_SWITCHED, last_healthy_at=int(time.time()))
                self.store.incr_metric("agent_update_healthy_total")
                log_event("INFO", "agent_update_healthy", status="HEALTHY", attempt=attempts)
                return BootDecision("run", version, version_dir, detail="healthy")

            self.store.incr_metric("agent_update_boot_check_failed_total")
            log_event(
                "ERROR",
                "agent_update_boot_check_failed",
                status="SWITCHED",
                attempt=attempts,
                error_code=ERR_UPDATE_HEALTH_FAILED,
            )
            if attempts < self.max_boot_attempts:
                return BootDecision(
                    "restart_required",
                    version,
                    version_dir,
                    ERR_UPDATE_HEALTH_FAILED,
                    detail=f"自检失败 {attempts}/{self.max_boot_attempts}",
                )

            # 连续 2 次失败 → 回滚，且只回滚一次（阶段转 ROLLED_BACK 后不会再次进入本分支）
            try:
                restored = self.switcher.rollback()
            except UpdateError as error:
                log_event("ERROR", "agent_update_rollback_failed", status="SWITCHED", error_code=error.code)
                return BootDecision("abort", version, version_dir, error.code, detail=error.message)

            self.store.transition(
                version,
                PHASE_ROLLED_BACK,
                expect_from=PHASE_SWITCHED,
                error_code=ERR_UPDATE_HEALTH_FAILED,
            )
            self.store.incr_metric("agent_update_rollback_total")
            log_event(
                "WARN",
                "agent_update_rolled_back",
                status="ROLLED_BACK",
                error_code=ERR_UPDATE_HEALTH_FAILED,
                attempt=attempts,
            )
            previous = self.switcher.read_current()
            return BootDecision(
                "rollback_and_run",
                restored,
                previous["dir"] if previous else None,
                ERR_UPDATE_HEALTH_FAILED,
                rollback_performed=True,
                detail=f"已回滚到 {restored}",
            )

        check = self.self_check(version_dir, version)
        if check.passed:
            if phase == PHASE_HEALTHY:
                self.store.touch_healthy(version)
            return BootDecision("run", version, version_dir, detail=check.detail)
        return BootDecision(
            "abort",
            version,
            version_dir,
            ERR_UPDATE_HEALTH_FAILED,
            detail=f"已知版本自检失败，无可回退目标: {check.detail}",
        )


# ── 协调器 ──────────────────────────────────────────────────────────────────

class UpdateCoordinator:
    """自更新编排：锁 → 租约闸 → 清单 → 下载 → 验证 → 落盘 → 切指针。"""

    def __init__(
        self,
        install_dir: str,
        client,
        public_key_pem: str,
        storage=None,
        lock_timeout: float = DEFAULT_LOCK_TIMEOUT,
        http_timeout: float = DEFAULT_HTTP_TIMEOUT,
        max_artifact_bytes: int = MAX_ARTIFACT_BYTES,
        fetcher: Optional[ArtifactFetcher] = None,
        store: Optional[UpdateStateStore] = None,
        switcher: Optional[VersionSwitcher] = None,
        verifier: Optional[ManifestVerifier] = None,
    ):
        self.install_dir = os.path.abspath(install_dir)
        self.client = client
        self.storage = storage
        self.lock_timeout = float(lock_timeout)
        self.max_artifact_bytes = int(max_artifact_bytes)
        self.switcher = switcher or VersionSwitcher(self.install_dir)
        self.store = store or UpdateStateStore(
            os.path.join(self.install_dir, _STATE_DIR, "agent.db")
        )
        self.verifier = verifier or ManifestVerifier(public_key_pem)
        self.fetcher = fetcher or ArtifactFetcher(
            headers_provider=self._headers_for,
            timeout=http_timeout,
        )
        self.lock_path = os.path.join(self.install_dir, _STATE_DIR, "locks", "agent_update.lock")

    # ── 小工具 ──────────────────────────────────────────────────────────────

    def _headers_for(self, url: str) -> Dict[str, str]:
        getter = getattr(self.client, "get_download_headers", None)
        if callable(getter):
            return getter(url)
        return {}

    def _active_execution(self) -> bool:
        if self.storage is None:
            return False
        checker = getattr(self.storage, "get_active_leases", None)
        if not callable(checker):
            return False
        return bool(checker())

    def _metric(self, key: str, by: int = 1) -> None:
        try:
            self.store.incr_metric(key, by)
        except (ValueError, sqlite3.Error):
            # 指标写失败不得影响更新主流程（这是可观测性，不是正确性）。
            pass

    def _fail(self, version: Optional[str], phase: str, error: UpdateError, current_version: str) -> UpdateOutcome:
        self._metric("agent_update_failed_total")
        log_event("ERROR", "agent_update_failed", status=phase, error_code=error.code)
        if version:
            try:
                row = self.store.get(version)
                if row and row["phase"] in (
                    PHASE_CHECKING,
                    PHASE_DOWNLOADING,
                    PHASE_VERIFIED,
                    PHASE_STAGED,
                ):
                    self.store.transition(version, PHASE_FAILED, error_code=error.code)
            except UpdateError:
                # 已经处于 FAILED 或终态：保留第一个错误码，不覆盖成"迁移失败"。
                pass
        return UpdateOutcome(
            success=False,
            phase=phase,
            version=version,
            current_version=current_version,
            updated=False,
            error_code=error.code,
            message=error.message,
        )

    # ── 主流程 ──────────────────────────────────────────────────────────────

    def run_once(self, current_version: str) -> UpdateOutcome:
        current_version = str(current_version)
        lock = SkillInstallLock(self.lock_path, timeout=self.lock_timeout)
        try:
            lock.__enter__()
        except PackageManagerError as error:
            log_event("ERROR", "agent_update_lock_timeout", status="IDLE", error_code=ERR_UPDATE_LOCK_TIMEOUT)
            return UpdateOutcome(
                False, PHASE_IDLE, None, current_version, False,
                ERR_UPDATE_LOCK_TIMEOUT, error.message,
            )

        phase = PHASE_IDLE
        version: Optional[str] = None
        artifact_path: Optional[str] = None
        try:
            # 上次崩溃留下的 .part/.staging 残骸必须先清掉，否则会被误当"已完成"
            try:
                self.switcher.cleanup_partials()
            except OSError:
                pass

            if self._active_execution():
                self._metric("agent_update_check_total")
                log_event(
                    "WARN",
                    "agent_update_blocked_active_execution",
                    status="IDLE",
                    error_code=ERR_UPDATE_BLOCKED_ACTIVE_EXECUTION,
                )
                return UpdateOutcome(
                    False, PHASE_IDLE, None, current_version, False,
                    ERR_UPDATE_BLOCKED_ACTIVE_EXECUTION,
                    "存在活动执行租约，拒绝更新",
                )

            self._metric("agent_update_check_total")
            log_event("INFO", "agent_update_check_started", status="CHECKING")

            ok, body = self.client.request(
                "GET",
                "api/agent/self-update",
                params={"did": getattr(self.client, "did", None), "agent_version": current_version},
            )
            if not ok:
                error = UpdateError(
                    ERR_UPDATE_MANIFEST_FETCH_FAILED,
                    f"拉取更新清单失败: {(body or {}).get('code') or (body or {}).get('message') or 'unknown'}",
                    PHASE_CHECKING,
                )
                return self._fail(None, PHASE_CHECKING, error, current_version)

            data = (body or {}).get("data")
            if not isinstance(data, dict):
                error = UpdateError(ERR_UPDATE_MANIFEST_INVALID, "平台响应缺少 data 对象", PHASE_CHECKING)
                return self._fail(None, PHASE_CHECKING, error, current_version)

            if data.get("update_available") is not True:
                log_event("INFO", "agent_update_no_update", status=str(data.get("reason") or "UP_TO_DATE"))
                return UpdateOutcome(
                    True, PHASE_IDLE, None, current_version, False, None,
                    str(data.get("reason") or "UP_TO_DATE"),
                )

            version = str(data.get("version") or "").strip()
            if not version:
                error = UpdateError(ERR_UPDATE_MANIFEST_INVALID, "清单缺少 version", PHASE_CHECKING)
                return self._fail(None, PHASE_CHECKING, error, current_version)

            self.store.ensure(version)
            existing = self.store.get(version)
            if existing and existing["phase"] in (PHASE_HEALTHY, PHASE_ROLLED_BACK):
                # 该版本已经是终态：平台若仍把同一个版本当"最新"下发，重装只会
                # 把设备推进"坏版本 → 回滚 → 再装坏版本"的死循环。必须由更高版本打破。
                error = UpdateError(
                    ERR_UPDATE_VERSION_BLOCKED,
                    f"版本 {version} 已处于终态 {existing['phase']}，拒绝重复安装",
                    PHASE_CHECKING,
                )
                return self._fail(version, PHASE_CHECKING, error, current_version)

            phase = PHASE_CHECKING
            self.store.transition(version, PHASE_CHECKING)

            # 验签 / 时钟 / 版本单调
            try:
                self.verifier.verify(data, current_version, int(time.time()))
            except UpdateError as error:
                self._metric("agent_update_manifest_rejected_total")
                return self._fail(version, PHASE_CHECKING, error, current_version)

            # 旧清单重放：同一版本、时间戳比上次已接受清单更旧 ⇒ 拒绝
            # （相等是允许的：崩溃后重入会拿到同一份清单，必须可重入）
            last_ts = self.store.get_last_manifest_ts(version)
            manifest_ts = int(data["ts"])
            if last_ts is not None and manifest_ts < last_ts:
                error = UpdateError(
                    ERR_UPDATE_MANIFEST_REPLAY,
                    f"清单时间戳 {manifest_ts} 早于已接受的 {last_ts}，判定为重放",
                    PHASE_CHECKING,
                )
                self._metric("agent_update_manifest_rejected_total")
                return self._fail(version, PHASE_CHECKING, error, current_version)
            self.store.set_last_manifest_ts(version, manifest_ts)

            self._metric("agent_update_available_total")
            log_event("INFO", "agent_update_available", status="CHECKING")

            artifact_hash = str(data["hash"]).strip().lower()
            artifact_url = str(data["url"])

            # ── 下载 ───────────────────────────────────────────────────────
            phase = PHASE_DOWNLOADING
            self.store.transition(version, PHASE_DOWNLOADING)
            os.makedirs(self.switcher.downloads_dir, exist_ok=True)
            artifact_path = os.path.join(self.switcher.downloads_dir, f"{version}.part")
            _remove_quietly(artifact_path)

            log_event("INFO", "agent_update_download_started", status="DOWNLOADING")
            try:
                actual_hash = self.fetcher.fetch(
                    artifact_url,
                    artifact_path,
                    artifact_hash,
                    max_bytes=self.max_artifact_bytes,
                )
            except UpdateError as error:
                if error.code == ERR_UPDATE_ARTIFACT_HASH_MISMATCH:
                    self._metric("agent_update_hash_mismatch_total")
                return self._fail(version, PHASE_DOWNLOADING, error, current_version)

            if actual_hash != artifact_hash:
                error = UpdateError(
                    ERR_UPDATE_ARTIFACT_HASH_MISMATCH,
                    f"工件 SHA-256 不匹配: 实际 {actual_hash} / 清单 {artifact_hash}",
                    PHASE_DOWNLOADING,
                )
                self._metric("agent_update_hash_mismatch_total")
                return self._fail(version, PHASE_DOWNLOADING, error, current_version)

            self._metric("agent_update_download_bytes_total", max(1, os.path.getsize(artifact_path)))
            phase = PHASE_VERIFIED
            try:
                self.store.transition(version, PHASE_VERIFIED, artifact_sha256=actual_hash)
            except UpdateError as error:
                return self._fail(version, PHASE_DOWNLOADING, error, current_version)
            log_event("INFO", "agent_update_verified", status="VERIFIED")

            # ── 落盘 ───────────────────────────────────────────────────────
            try:
                version_dir = self.switcher.stage(artifact_path, version)
                self.switcher.write_version_meta(version_dir, actual_hash)
            except UpdateError as error:
                return self._fail(version, PHASE_VERIFIED, error, current_version)

            phase = PHASE_STAGED
            try:
                self.store.transition(version, PHASE_STAGED)
            except UpdateError as error:
                return self._fail(version, PHASE_VERIFIED, error, current_version)
            log_event("INFO", "agent_update_staged", status="STAGED")

            # ── 切指针（先 previous 后 current，均为原子写）─────────────────
            try:
                self.switcher.switch_to(version, actual_hash)
            except UpdateError as error:
                return self._fail(version, PHASE_STAGED, error, current_version)

            phase = PHASE_SWITCHED
            try:
                self.store.transition(version, PHASE_SWITCHED)
            except UpdateError as error:
                return self._fail(version, PHASE_STAGED, error, current_version)
            self._metric("agent_update_switched_total")
            log_event("INFO", "agent_update_switched", status="SWITCHED")

            return UpdateOutcome(
                True, PHASE_SWITCHED, version, current_version, True, None,
                "新版本已落盘并切换，等待下次启动自检",
            )

        except UpdateError as error:
            return self._fail(version, phase, error, current_version)
        except Exception as error:  # I7：任何意外都必须收敛成稳定错误码
            return self._fail(
                version,
                phase,
                UpdateError(ERR_UPDATE_INTERNAL, f"自更新内部异常: {type(error).__name__}"),
                current_version,
            )
        finally:
            if artifact_path:
                _remove_quietly(artifact_path)
            lock.__exit__(None, None, None)


def resolve_reported_version(default_version: str) -> str:
    """心跳上报的版本号：launcher 会设置 ZHJJ_AGENT_VERSION；否则用包内 __version__。"""
    return os.environ.get("ZHJJ_AGENT_VERSION") or default_version


def self_check_cli(install_dir: str) -> int:
    """`--self-check` 入口：只读、无副作用（不计数、不迁移、不回滚）。返回退出码。"""
    switcher = VersionSwitcher(install_dir)
    pointer, source = BootGuard(install_dir, switcher=switcher).resolve(repair=False)
    if pointer is None:
        print(json.dumps({"passed": True, "mode": "bundled", "source": source}, ensure_ascii=False))
        return 0
    result = run_self_check(pointer["dir"], pointer["version"])
    print(
        json.dumps(
            {
                "passed": result.passed,
                "mode": "managed",
                "source": source,
                "version": pointer["version"],
                "reported_version": result.reported_version,
                "detail": result.detail,
            },
            ensure_ascii=False,
        )
    )
    return 0 if result.passed else 1
