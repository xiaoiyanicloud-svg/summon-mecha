"""本地 SQLite 持久化存储：任务 Journal、检查点、已安装状态、Outbox 事件队列与租约"""

import contextlib
import json
import os
import sqlite3
import time
from typing import Any, Dict, List, Optional


class Storage:
    def __init__(self, install_dir: str):
        self.install_dir = os.path.abspath(install_dir)
        self.state_dir = os.path.join(self.install_dir, "state")
        os.makedirs(self.state_dir, exist_ok=True)
        self.db_path = os.path.join(self.state_dir, "agent.db")
        self.installed_json_path = os.path.join(self.state_dir, "installed.json")
        self._init_db()

    @contextlib.contextmanager
    def _connection(self):
        conn = sqlite3.connect(self.db_path, timeout=10.0)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL;")
        conn.execute("PRAGMA synchronous=NORMAL;")
        try:
            with conn:
                yield conn
        finally:
            conn.close()

    def _init_db(self):
        with self._connection() as conn:
            conn.executescript("""
            CREATE TABLE IF NOT EXISTS tasks (
                task_id TEXT PRIMARY KEY,
                command_seq INTEGER DEFAULT 0,
                did TEXT,
                operation INTEGER,
                skill_id TEXT,
                skill_version_id TEXT,
                version TEXT,
                status TEXT,
                signed_payload TEXT,
                raw_command TEXT,
                error_code TEXT,
                error_msg TEXT,
                created_at INTEGER,
                updated_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS checkpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id TEXT,
                stage TEXT,
                checkpoint_data TEXT,
                created_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS installed_skills (
                skill_id TEXT PRIMARY KEY,
                current_version TEXT,
                previous_version TEXT,
                status TEXT,
                enabled INTEGER DEFAULT 1,
                install_path TEXT,
                package_sha256 TEXT,
                manifest_json TEXT,
                files_json TEXT,
                updated_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS outbox (
                event_id TEXT PRIMARY KEY,
                task_id TEXT,
                event_seq INTEGER,
                phase TEXT,
                payload_json TEXT,
                acked INTEGER DEFAULT 0,
                ack_response_json TEXT,
                retry_count INTEGER DEFAULT 0,
                last_attempt_at INTEGER,
                created_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS revocations (
                scope_key TEXT PRIMARY KEY,
                epoch INTEGER,
                revoked_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS execution_leases (
                execution_id TEXT PRIMARY KEY,
                skill_id TEXT,
                resource_name TEXT,
                status TEXT,
                acquired_at INTEGER,
                expires_at INTEGER,
                released_at INTEGER
            );
            """)

    # ── 任务 Journal ─────────────────────────────────────────────────────────

    def record_task_durable(
        self,
        task_id: str,
        command_seq: int,
        did: str,
        operation: int,
        skill_id: str,
        skill_version_id: Optional[str],
        version: str,
        signed_payload: Optional[Dict[str, Any]],
        raw_command: Dict[str, Any],
        initial_status: str = "RECEIVED_DURABLE"
    ) -> bool:
        """任务持久化接收登记。若同 task_id 已存在则返回 False（表示已记录过）。"""
        now = int(time.time())
        with self._connection() as conn:
            existing = conn.execute("SELECT status FROM tasks WHERE task_id = ?", (task_id,)).fetchone()
            if existing:
                return False
            conn.execute(
                """
                INSERT INTO tasks (
                    task_id, command_seq, did, operation, skill_id, skill_version_id,
                    version, status, signed_payload, raw_command, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    task_id,
                    command_seq,
                    did,
                    operation,
                    str(skill_id or ""),
                    str(skill_version_id or ""),
                    str(version or ""),
                    initial_status,
                    json.dumps(signed_payload or {}, ensure_ascii=False),
                    json.dumps(raw_command or {}, ensure_ascii=False),
                    now,
                    now
                )
            )
            return True

    def update_task_status(
        self,
        task_id: str,
        status: str,
        error_code: Optional[str] = None,
        error_msg: Optional[str] = None
    ):
        now = int(time.time())
        with self._connection() as conn:
            conn.execute(
                """
                UPDATE tasks SET status = ?, error_code = ?, error_msg = ?, updated_at = ?
                WHERE task_id = ?
                """,
                (status, error_code, error_msg, now, task_id)
            )

    def get_task(self, task_id: str) -> Optional[Dict[str, Any]]:
        with self._connection() as conn:
            row = conn.execute("SELECT * FROM tasks WHERE task_id = ?", (task_id,)).fetchone()
            if not row:
                return None
            data = dict(row)
            try:
                data["signed_payload"] = json.loads(data["signed_payload"] or "{}")
            except Exception:
                data["signed_payload"] = {}
            try:
                data["raw_command"] = json.loads(data["raw_command"] or "{}")
            except Exception:
                data["raw_command"] = {}
            return data

    def get_uncompleted_tasks(self) -> List[Dict[str, Any]]:
        with self._connection() as conn:
            rows = conn.execute(
                "SELECT * FROM tasks WHERE status NOT IN ('COMMITTED', 'REPORTED_ACKED', 'FAILED') ORDER BY created_at ASC"
            ).fetchall()
            res = []
            for r in rows:
                item = dict(r)
                try:
                    item["signed_payload"] = json.loads(item["signed_payload"] or "{}")
                except Exception:
                    item["signed_payload"] = {}
                try:
                    item["raw_command"] = json.loads(item["raw_command"] or "{}")
                except Exception:
                    item["raw_command"] = {}
                res.append(item)
            return res

    def get_max_command_seq(self, did: str) -> int:
        with self._connection() as conn:
            row = conn.execute(
                "SELECT MAX(command_seq) as max_seq FROM tasks WHERE did = ?",
                (did,)
            ).fetchone()
            if row and row["max_seq"] is not None:
                return int(row["max_seq"])
            return 0

    # ── 检查点 Checkpoints ──────────────────────────────────────────────────

    def save_checkpoint(self, task_id: str, stage: str, data: Dict[str, Any]):
        now = int(time.time())
        with self._connection() as conn:
            conn.execute(
                "INSERT INTO checkpoints (task_id, stage, checkpoint_data, created_at) VALUES (?, ?, ?, ?)",
                (task_id, stage, json.dumps(data, ensure_ascii=False), now)
            )

    def get_latest_checkpoint(self, task_id: str) -> Optional[Dict[str, Any]]:
        with self._connection() as conn:
            row = conn.execute(
                "SELECT * FROM checkpoints WHERE task_id = ? ORDER BY id DESC LIMIT 1",
                (task_id,)
            ).fetchone()
            if not row:
                return None
            res = dict(row)
            try:
                res["checkpoint_data"] = json.loads(res["checkpoint_data"] or "{}")
            except Exception:
                res["checkpoint_data"] = {}
            return res

    # ── 已安装状态 Installed Skills ──────────────────────────────────────────

    def get_installed_skill(self, skill_id: str) -> Optional[Dict[str, Any]]:
        skill_key = str(skill_id)
        with self._connection() as conn:
            row = conn.execute("SELECT * FROM installed_skills WHERE skill_id = ?", (skill_key,)).fetchone()
            if not row:
                return None
            res = dict(row)
            res["enabled"] = bool(res["enabled"])
            try:
                res["manifest"] = json.loads(res["manifest_json"] or "{}")
            except Exception:
                res["manifest"] = {}
            try:
                res["files"] = json.loads(res["files_json"] or "[]")
            except Exception:
                res["files"] = []
            return res

    def get_all_installed_skills(self) -> Dict[str, Dict[str, Any]]:
        with self._connection() as conn:
            rows = conn.execute("SELECT * FROM installed_skills").fetchall()
            skills = {}
            for r in rows:
                item = dict(r)
                item["enabled"] = bool(item["enabled"])
                try:
                    item["manifest"] = json.loads(item["manifest_json"] or "{}")
                except Exception:
                    item["manifest"] = {}
                try:
                    item["files"] = json.loads(item["files_json"] or "[]")
                except Exception:
                    item["files"] = []
                skills[str(item["skill_id"])] = item
            return skills

    def save_installed_skill(
        self,
        skill_id: str,
        current_version: str,
        previous_version: Optional[str],
        status: str,
        enabled: bool,
        install_path: str,
        package_sha256: Optional[str] = None,
        manifest: Optional[Dict[str, Any]] = None,
        files: Optional[List[str]] = None
    ) -> Dict[str, Any]:
        skill_key = str(skill_id)
        now = int(time.time())
        with self._connection() as conn:
            conn.execute(
                """
                INSERT INTO installed_skills (
                    skill_id, current_version, previous_version, status, enabled,
                    install_path, package_sha256, manifest_json, files_json, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(skill_id) DO UPDATE SET
                    current_version = excluded.current_version,
                    previous_version = excluded.previous_version,
                    status = excluded.status,
                    enabled = excluded.enabled,
                    install_path = excluded.install_path,
                    package_sha256 = excluded.package_sha256,
                    manifest_json = excluded.manifest_json,
                    files_json = excluded.files_json,
                    updated_at = excluded.updated_at
                """,
                (
                    skill_key,
                    str(current_version),
                    str(previous_version) if previous_version else None,
                    status,
                    1 if enabled else 0,
                    install_path,
                    package_sha256,
                    json.dumps(manifest or {}, ensure_ascii=False),
                    json.dumps(files or [], ensure_ascii=False),
                    now
                )
            )

        # 触发兼容性状态导出到 installed.json
        self._sync_installed_json()
        return self.get_installed_skill(skill_key) # type: ignore

    def _sync_installed_json(self):
        """原子导出到 state/installed.json 供旧工具读取。"""
        all_skills = self.get_all_installed_skills()
        out = {"skills": all_skills}
        tmp_path = self.installed_json_path + ".tmp"
        with open(tmp_path, "w", encoding="utf-8") as f:
            json.dump(out, f, ensure_ascii=False, indent=2)
        os.replace(tmp_path, self.installed_json_path)

    # ── Outbox 待发事件 ──────────────────────────────────────────────────────

    def record_event_outbox(
        self,
        event_id: str,
        task_id: str,
        phase: str,
        payload: Dict[str, Any],
        event_seq: int = 0
    ):
        now = int(time.time())
        with self._connection() as conn:
            conn.execute(
                """
                INSERT INTO outbox (
                    event_id, task_id, event_seq, phase, payload_json,
                    acked, retry_count, last_attempt_at, created_at
                ) VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?)
                ON CONFLICT(event_id) DO NOTHING
                """,
                (
                    event_id,
                    task_id,
                    event_seq,
                    phase,
                    json.dumps(payload, ensure_ascii=False),
                    now
                )
            )

    def mark_event_acked(self, event_id: str, ack_response: Optional[Dict[str, Any]] = None):
        with self._connection() as conn:
            conn.execute(
                """
                UPDATE outbox SET acked = 1, ack_response_json = ?
                WHERE event_id = ?
                """,
                (json.dumps(ack_response or {}, ensure_ascii=False), event_id)
            )

    def mark_event_attempt(self, event_id: str):
        now = int(time.time())
        with self._connection() as conn:
            conn.execute(
                """
                UPDATE outbox SET retry_count = retry_count + 1, last_attempt_at = ?
                WHERE event_id = ?
                """,
                (now, event_id)
            )

    def get_unacked_events(self) -> List[Dict[str, Any]]:
        with self._connection() as conn:
            rows = conn.execute(
                "SELECT * FROM outbox WHERE acked = 0 ORDER BY created_at ASC"
            ).fetchall()
            res = []
            for r in rows:
                item = dict(r)
                try:
                    item["payload"] = json.loads(item["payload_json"] or "{}")
                except Exception:
                    item["payload"] = {}
                res.append(item)
            return res

    # ── 撤销代次 Revocations ─────────────────────────────────────────────────

    def set_revocation_epoch(self, scope_key: str, epoch: int):
        now = int(time.time())
        with self._connection() as conn:
            conn.execute(
                """
                INSERT INTO revocations (scope_key, epoch, revoked_at)
                VALUES (?, ?, ?)
                ON CONFLICT(scope_key) DO UPDATE SET
                    epoch = MAX(revocations.epoch, excluded.epoch),
                    revoked_at = excluded.revoked_at
                """,
                (scope_key, epoch, now)
            )

    def get_revocation_epoch(self, scope_key: str) -> int:
        with self._connection() as conn:
            row = conn.execute(
                "SELECT epoch FROM revocations WHERE scope_key = ?",
                (scope_key,)
            ).fetchone()
            return int(row["epoch"]) if row else 0

    # ── 执行租约与资源锁 Execution Leases ────────────────────────────────────

    def acquire_execution_lease(
        self,
        execution_id: str,
        skill_id: str,
        resource_name: str,
        duration_seconds: int = 60
    ) -> bool:
        """尝试获取资源租约。若已有冲突未释放租约，则返回 False（未知物理状态不能靠时间流逝抢占释放）。"""
        now = int(time.time())
        expires_at = now + duration_seconds
        with self._connection() as conn:
            # 检查同资源是否有未释放租约（无论是否超时，在未观测确认停止前均不可借时间流逝抢占）
            active = conn.execute(
                """
                SELECT execution_id, expires_at, status FROM execution_leases
                WHERE resource_name = ? AND status != 'RELEASED'
                """,
                (resource_name,)
            ).fetchone()
            if active and active["execution_id"] != execution_id:
                return False

            conn.execute(
                """
                INSERT INTO execution_leases (
                    execution_id, skill_id, resource_name, status, acquired_at, expires_at
                ) VALUES (?, ?, ?, 'ACTIVE', ?, ?)
                ON CONFLICT(execution_id) DO UPDATE SET
                    status = 'ACTIVE',
                    expires_at = excluded.expires_at
                """,
                (execution_id, skill_id, resource_name, now, expires_at)
            )
            return True

    def get_active_leases_for_skill(self, skill_id: str) -> List[Dict[str, Any]]:
        with self._connection() as conn:
            rows = conn.execute(
                "SELECT * FROM execution_leases WHERE skill_id = ? AND status != 'RELEASED'",
                (str(skill_id),)
            ).fetchall()
            return [dict(r) for r in rows]

    def release_execution_lease(self, execution_id: str):
        now = int(time.time())
        with self._connection() as conn:
            conn.execute(
                "UPDATE execution_leases SET status = 'RELEASED', released_at = ? WHERE execution_id = ?",
                (now, execution_id)
            )

    def get_execution_lease(self, execution_id: str) -> Optional[Dict[str, Any]]:
        with self._connection() as conn:
            row = conn.execute(
                "SELECT * FROM execution_leases WHERE execution_id = ?",
                (execution_id,)
            ).fetchone()
            return dict(row) if row else None
