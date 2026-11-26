"""Agent 结构化日志。禁止输出 secret/token/私钥/完整签名。"""

import json
import logging
import os
import sys
import time


LOGGER = logging.getLogger("zhjj_agent.telemetry")


def configure_telemetry() -> None:
    if LOGGER.handlers:
        return
    handler = logging.StreamHandler(sys.stderr)
    handler.setFormatter(logging.Formatter("%(message)s"))
    LOGGER.addHandler(handler)
    LOGGER.setLevel(logging.INFO)
    LOGGER.propagate = False


def log_event(level: str, event: str, extra: dict | None = None, **fields) -> None:
    """结构化日志。

    `extra` 是**附加**字段（自更新等新链路需要 version/phase/bytes 这类维度），
    合并在固定键之后写入；不传 extra 时输出与历史逐字一致。
    """
    configure_telemetry()
    payload = {
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "level": level.upper(),
        "service": "zhjj-agent",
        "env": os.environ.get("APP_ENV", "production"),
        "event": event,
        "correlation_id": fields.get("correlation_id") or fields.get("task_id"),
        "task_id": fields.get("task_id"),
        "execution_id": fields.get("execution_id"),
        "build_key": fields.get("build_key"),
        "did": fields.get("did"),
        "vendor": fields.get("vendor"),
        "model_code": fields.get("model_code"),
        "operation": fields.get("operation"),
        "status": fields.get("status"),
        "attempt": fields.get("attempt"),
        "duration_ms": fields.get("duration_ms"),
        "error_code": fields.get("error_code"),
    }
    if extra:
        payload.update(extra)
    LOGGER.log(getattr(logging, level.upper(), logging.INFO), json.dumps(payload, ensure_ascii=False, separators=(",", ":")))