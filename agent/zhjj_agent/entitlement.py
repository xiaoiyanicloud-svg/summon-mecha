"""设备侧授权回源校验（WBS A5）。

为什么不是"指令里带没带授权"：指令是**下发那一刻**的快照。设备离线数天后仍按旧
指令执行，就绕过了试用回收/退款/吊销。这里在执行前独立问平台一次，并把结论落
SQLite 缓存。

三条策略边界（写死在代码里，不做"可跳过"开关）：

  · `ttl_seconds` 内直接用缓存，不打平台（避免每次执行都加一跳）。
  · 平台不可达时，缓存只要还在 `max_stale_seconds` 内就继续用它 —— 这是**可用性**
    与**安全性**的折中；超过窗口一律 fail-closed（`ENTITLEMENT_UNVERIFIED`）。
  · 平台明确返回 `allowed=false` 时**立即**按拒绝处理，不看旧缓存。

开关：`ZHJJ_ENTITLEMENT_CHECK` 默认关闭（项目纪律：新开关默认关）。打开后
`SkillExecutor.execute()` 在查库/占租约/调适配器之前先过这一关。
"""

from __future__ import annotations

import os
import time
from typing import Any, Dict, Optional, Tuple

ENTITLEMENT_CHECK_ENV = "ZHJJ_ENTITLEMENT_CHECK"

#: 平台不可达且缓存超过该窗口 → 拒绝执行（fail-closed）。
DEFAULT_MAX_STALE_SECONDS = 900


def entitlement_check_required() -> bool:
    value = (os.environ.get(ENTITLEMENT_CHECK_ENV, "") or "").strip().lower()

    return value in ("1", "true", "yes", "on")


class EntitlementGuard:
    def __init__(
        self,
        client: Any,
        storage: Any,
        ttl_seconds: int = 300,
        max_stale_seconds: int = DEFAULT_MAX_STALE_SECONDS,
    ):
        self.client = client
        self.storage = storage
        self.ttl_seconds = max(0, int(ttl_seconds))
        self.max_stale_seconds = max(self.ttl_seconds, int(max_stale_seconds))

    def check(self, skill_id: str, skill_version_id: Optional[int] = None) -> Tuple[bool, str, str]:
        """返回 (allowed, reason, source)。

        source: `cache` / `platform` / `stale_cache` / `unverified`
        """
        cached = self.storage.get_entitlement_cache(skill_id)
        now = int(time.time())

        if cached is not None and now - int(cached["checked_at"]) <= self.ttl_seconds:
            return bool(cached["allowed"]), str(cached["reason"] or ""), "cache"

        ok, response = self.client.check_entitlement(skill_id, skill_version_id)

        if ok:
            data = (response or {}).get("data") or {}
            allowed = bool(data.get("allowed"))
            reason = str(data.get("reason") or ("OK" if allowed else "DENIED"))

            # 平台给的 expires_at 也可以直接收窄缓存寿命：授权比缓存先过期时，
            # 不应再拿"还没到 TTL"当继续放行的理由。
            checked_at = now
            expires_at = data.get("expires_at")
            if allowed and expires_at is not None:
                ttl_cap = max(0, int(expires_at) - now)
                checked_at = now - max(0, self.ttl_seconds - ttl_cap)

            self.storage.save_entitlement_cache(
                skill_id=skill_id,
                allowed=allowed,
                reason=reason,
                entitlement_id=data.get("entitlement_id"),
                expires_at=expires_at,
                checked_at=checked_at,
            )

            return allowed, reason, "platform"

        # 平台不可达/5xx：只允许在最大陈旧窗口内使用缓存
        if cached is not None and now - int(cached["checked_at"]) <= self.max_stale_seconds:
            return bool(cached["allowed"]), str(cached["reason"] or "STALE_CACHE"), "stale_cache"

        return False, "ENTITLEMENT_UNVERIFIED", "unverified"
