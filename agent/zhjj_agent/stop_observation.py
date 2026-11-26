"""独立停止观察契约（WBS A2）。

平台侧已在 `StopObservation` 强类型中固定同一组字段；端侧在生成执行事实前
必须拿到这份结构化观察，不能再用 `now()` 伪造 `stopped_at`。

⚠ `synthetic_observation()` 只用于测试/模拟器，collector_version 明确标 synthetic-test-only，
   不得作为真机 HIL 证据。
"""

import hashlib
import time
from typing import Any, Dict, Optional

SOURCE_TYPES = (
    "hardware_estop",
    "motor_controller_feedback",
    "safety_plc",
    "independent_watchdog",
)

FORBIDDEN_SOURCE_TYPES = (
    "sdk_return",
    "sdk_true",
    "bt_halt",
    "cancel_requested",
    "cloud_ack",
    "script_assertion",
    "mock",
    "sil",
)

REQUIRED_FIELDS = (
    "source_type",
    "sensor_id",
    "observed_at",
    "actual_started",
    "actual_stopped",
    "quality",
    "raw_evidence_sha256",
    "collector_version",
)

QUALITY_LEVELS = ("good", "degraded", "bad")


def validate_observation(observation: Any) -> Optional[Dict[str, Any]]:
    """返回可用的观察字典；任何缺失/伪造/质量不合格都返回 None。"""
    if not isinstance(observation, dict):
        return None

    source_type = str(observation.get("source_type") or "").lower()

    if source_type in FORBIDDEN_SOURCE_TYPES:
        return None

    if source_type not in SOURCE_TYPES:
        return None

    if any(observation.get(key) in (None, "") for key in REQUIRED_FIELDS):
        return None

    if observation.get("actual_stopped") is not True:
        return None

    if str(observation.get("quality")) not in QUALITY_LEVELS or observation.get("quality") == "bad":
        return None

    try:
        observed_at = int(observation.get("observed_at"))
    except (TypeError, ValueError):
        return None

    if observed_at <= 0:
        return None

    raw_hash = str(observation.get("raw_evidence_sha256") or "")

    if len(raw_hash) != 64 or any(c not in "0123456789abcdefABCDEF" for c in raw_hash):
        return None

    return observation


def synthetic_observation(
    execution_id: str,
    *,
    actual_started: bool = True,
    actual_stopped: bool = True,
    source_type: str = "independent_watchdog",
    collector_version: str = "synthetic-test-only",
) -> Dict[str, Any]:
    """测试/模拟器专用；绝不代表真机 HIL。"""
    now = int(time.time())
    raw_hash = hashlib.sha256(f"{execution_id}|{now}|synthetic".encode("utf-8")).hexdigest()

    return {
        "execution_id": execution_id,
        "source_type": source_type,
        "sensor_id": "synthetic:test-observer",
        "observed_at": now,
        "actual_started": actual_started,
        "actual_stopped": actual_stopped,
        "velocity": 0.0,
        "current": 0.0,
        "contact": True,
        "brake": True,
        "power": True,
        "quality": "good",
        "raw_evidence_sha256": raw_hash,
        "collector_version": collector_version,
    }
