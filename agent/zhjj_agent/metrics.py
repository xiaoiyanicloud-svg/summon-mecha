"""设备端指标计数器（A3）。

三条硬约束，与平台 `DeployMetricCounter` 一一对应：

1. **键集闭合**：只允许 `AGENT_METRIC_KEYS` 里的 6 个键。写别的键直接抛 `MetricKeyError`。
   没有动态键，就不可能把 device_id / task_id / skill_id 混成标签 —— 基数恒定为 9。
   （平台侧另有 3 个键由平台自己埋点，Agent **不得**上报。）

2. **增量按批发送**：`next_batch()` 返回"上次确认之后新产生的增量"，ack 之前反复调用
   返回的是**同一批**（同一 batch_id、同一载荷）。这样"发出去但没收到 200"不会丢，
   也不会在重试时把同一批算两遍（平台按 (did,batch_id) 去重）。

3. **只增不减**：增量必须为正；`cumulative` 单调递增，`reported` 是已被平台接收的部分，
   差值 `pending` 就是还要上报的量。

⚠ 与 `package_signature_failed_total` 的关系：Agent 侧**没有包签名校验**（包只校验 SHA-256，
   签名在指令信封上）。所以这里用 `command_signature_failed_total` —— 有真实生产者的那个。
   把不存在的生产者伪装成已埋点，比留空更糟。
"""

import json
import os
import sqlite3
import time
import uuid
from typing import Dict, Optional, Tuple


AGENT_METRIC_KEYS = (
    # WBS A3.2 字面项：设备真正收到一条指令（无论最终是否执行/被拒）。
    "command_received_total",
    # WBS A3.2 字面项：同 task_id 的重复投递被幂等挡住。
    "command_duplicate_total",
    "agent_restart_total",
    "staging_orphan_total",
    "command_signature_failed_total",
    "package_hash_mismatch_total",
    "task_lease_conflict_total",
    "outbox_flush_failed_total",
)

#: 平台侧自己埋点的键。Agent 不产生、也不得上报（上报会被平台 4xx 拒绝）。
PLATFORM_METRIC_KEYS = (
    "idempotency_hit_total",
    "reconcile_drift_total",
    "fact_auth_rejected_total",
    # WBS A3.2 字面项：平台 Inbox 同 event_id 异载荷（409）。
    "event_conflict_total",
)

ALL_METRIC_KEYS = AGENT_METRIC_KEYS + PLATFORM_METRIC_KEYS

#: 设备端上报的瞬时值（gauge）。与计数分开：心跳里走 `metrics.gauges`，平台覆盖而不是累加。
AGENT_GAUGE_KEYS = (
    # WBS A3.2 字面项：设备 outbox 最老未确认事件的年龄（秒）。
    "outbox_pending_age_seconds",
)


class MetricKeyError(ValueError):
    """指标键不在闭合键集内，或增量非法。"""


class AgentMetrics:
    def __init__(self, db_path: str):
        self.db_path = os.path.abspath(db_path)
        os.makedirs(os.path.dirname(self.db_path), exist_ok=True)
        self._init_db()

    # ── 基础设施 ────────────────────────────────────────────────────────────

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path, timeout=10.0)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL;")
        conn.execute("PRAGMA synchronous=NORMAL;")
        return conn

    def _init_db(self) -> None:
        conn = self._connect()
        try:
            with conn:
                conn.executescript(
                    """
                    CREATE TABLE IF NOT EXISTS metric_counters (
                        metric_key TEXT PRIMARY KEY,
                        cumulative INTEGER NOT NULL DEFAULT 0,
                        reported INTEGER NOT NULL DEFAULT 0,
                        updated_at INTEGER NOT NULL
                    );

                    CREATE TABLE IF NOT EXISTS metric_batches (
                        batch_id TEXT PRIMARY KEY,
                        payload_json TEXT NOT NULL,
                        created_at INTEGER NOT NULL,
                        acked INTEGER NOT NULL DEFAULT 0
                    );
                    """
                )
        finally:
            conn.close()

    # ── 计数 ────────────────────────────────────────────────────────────────

    def inc(self, key: str, by: int = 1) -> int:
        """累加一个设备端计数，返回累加后的累计值。"""
        if key not in AGENT_METRIC_KEYS:
            raise MetricKeyError(
                f"未知指标键 {key!r}：只允许闭合键集 {AGENT_METRIC_KEYS} 内的键（禁止动态键）"
            )
        if not isinstance(by, int) or isinstance(by, bool) or by <= 0:
            raise MetricKeyError("指标增量必须为正整数（计数器只增不减）")

        now = int(time.time())
        conn = self._connect()
        try:
            with conn:
                conn.execute(
                    """
                    INSERT INTO metric_counters (metric_key, cumulative, reported, updated_at)
                    VALUES (?, ?, 0, ?)
                    ON CONFLICT(metric_key) DO UPDATE SET
                        cumulative = cumulative + excluded.cumulative,
                        updated_at = excluded.updated_at
                    """,
                    (key, by, now),
                )
                row = conn.execute(
                    "SELECT cumulative FROM metric_counters WHERE metric_key = ?", (key,)
                ).fetchone()
                return int(row["cumulative"]) if row else by
        finally:
            conn.close()

    def cumulative(self, key: str) -> int:
        if key not in AGENT_METRIC_KEYS:
            raise MetricKeyError(f"未知指标键 {key!r}")
        conn = self._connect()
        try:
            row = conn.execute(
                "SELECT cumulative FROM metric_counters WHERE metric_key = ?", (key,)
            ).fetchone()
            return int(row["cumulative"]) if row else 0
        finally:
            conn.close()

    def pending_counters(self) -> Dict[str, int]:
        """尚未被平台确认的增量（cumulative - reported），只返回大于 0 的键。"""
        conn = self._connect()
        try:
            rows = conn.execute(
                "SELECT metric_key, cumulative - reported AS pending FROM metric_counters"
            ).fetchall()
        finally:
            conn.close()
        return {r["metric_key"]: int(r["pending"]) for r in rows if int(r["pending"]) > 0}

    # ── 批次 ────────────────────────────────────────────────────────────────

    def next_batch(self) -> Optional[Tuple[str, Dict[str, int]]]:
        """取一个待上报批次。

        - 有未确认批次 → 原样返回它（同一 batch_id 同一载荷，绝不换 id 重发）
        - 没有未确认批次且有增量 → 新建批次
        - 两者都没有 → None
        """
        conn = self._connect()
        try:
            with conn:
                unacked = conn.execute(
                    "SELECT batch_id, payload_json FROM metric_batches WHERE acked = 0 ORDER BY created_at LIMIT 1"
                ).fetchone()
                if unacked:
                    return unacked["batch_id"], json.loads(unacked["payload_json"])

                pending = self.pending_counters()
                if not pending:
                    return None

                batch_id = "mbatch_" + uuid.uuid4().hex[:16]
                conn.execute(
                    "INSERT INTO metric_batches (batch_id, payload_json, created_at, acked) VALUES (?, ?, ?, 0)",
                    (batch_id, json.dumps(pending, sort_keys=True, separators=(",", ":")), int(time.time())),
                )
                return batch_id, pending
        finally:
            conn.close()

    def ack_batch(self, batch_id: str) -> bool:
        """平台已接收：把该批的增量记进 reported，之后不再重复上报。"""
        conn = self._connect()
        try:
            with conn:
                row = conn.execute(
                    "SELECT payload_json, acked FROM metric_batches WHERE batch_id = ?", (batch_id,)
                ).fetchone()
                if not row or int(row["acked"]) == 1:
                    return False

                payload = json.loads(row["payload_json"])
                for key, delta in payload.items():
                    conn.execute(
                        "UPDATE metric_counters SET reported = reported + ? WHERE metric_key = ?",
                        (int(delta), key),
                    )
                conn.execute("UPDATE metric_batches SET acked = 1 WHERE batch_id = ?", (batch_id,))
                return True
        finally:
            conn.close()

    def drop_batch(self, batch_id: str) -> None:
        """平台明确拒绝（4xx）时丢弃该批：键写错了，重试一万次也一样错。"""
        conn = self._connect()
        try:
            with conn:
                conn.execute("DELETE FROM metric_batches WHERE batch_id = ?", (batch_id,))
        finally:
            conn.close()

    def snapshot(self) -> Dict[str, int]:
        return {key: self.cumulative(key) for key in AGENT_METRIC_KEYS}

    # ── Gauge（瞬时值，覆盖不累加） ─────────────────────────────────────────

    def outbox_pending_age_seconds(self) -> int:
        """设备 outbox 里最老未确认事件的年龄（秒）；没有待发事件 = 0。

        数据直接从同一个 SQLite 文件的 `outbox` 表读 —— Agent 的计数与业务表本来就同库，
        再去维护一份"影子状态"只会让两边漂移。表不存在（新库/未初始化）时返回 0。
        """
        conn = self._connect()
        try:
            table = conn.execute(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='outbox'"
            ).fetchone()
            if not table:
                return 0

            row = conn.execute(
                "SELECT MIN(created_at) AS oldest FROM outbox WHERE acked = 0"
            ).fetchone()
            oldest = row["oldest"] if row else None
            if oldest is None:
                return 0

            return max(0, int(time.time()) - int(oldest))
        finally:
            conn.close()

    def gauges(self) -> Dict[str, int]:
        return {"outbox_pending_age_seconds": self.outbox_pending_age_seconds()}
