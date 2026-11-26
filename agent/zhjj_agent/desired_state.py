"""A4 · 声明式目标状态（设备侧视角）。

平台会算"期望 vs install_record"的 drift；设备端仍要**用自己的本地事实**再算一次 ——
`install_record` 可能落后于磁盘（事件丢了、进程崩了），设备不能拿平台的账本当自己的账本。

本模块只做三件事：
  · 拉平台 desired-state；
  · 用本地 `installed_skills` 算 missing / version_mismatch / extra；
  · 打一条结构化日志（不改变任务状态、不自动装/卸）。

自动收敛由平台侧开关控制（默认关闭）；设备侧不会擅自安装或卸载。
"""

from __future__ import annotations

import time
from typing import Any, Dict, Optional


class DesiredStateTracker:
    def __init__(self, client: Any, storage: Any, logger: Optional[Any] = None):
        self.client = client
        self.storage = storage
        self.logger = logger
        self.last: Optional[Dict[str, Any]] = None

    def refresh(self) -> Optional[Dict[str, Any]]:
        """拉一次目标状态并与本地实际状态比对。失败返回 None（不影响心跳/轮询）。"""
        ok, response = self.client.fetch_desired_state()

        if not ok:
            return None

        data = (response or {}).get("data") or {}
        desired = data.get("desired") or []

        if not isinstance(desired, list):
            return None

        snapshot = self._diff(desired)
        snapshot["generation"] = str(data.get("generation") or "")
        snapshot["checked_at"] = int(time.time())
        self.last = snapshot

        if self.logger is not None:
            try:
                self.logger(
                    "info",
                    "agent.desired_state.checked",
                    status="ok",
                    extra={
                        "generation": snapshot["generation"],
                        "missing_count": len(snapshot["missing"]),
                        "mismatch_count": len(snapshot["version_mismatch"]),
                        "extra_count": len(snapshot["extra"]),
                    },
                )
            except Exception:
                pass

        return snapshot

    def _diff(self, desired: list) -> Dict[str, Any]:
        installed = self.storage.get_all_installed_skills()
        desired_by_skill: Dict[str, Dict[str, Any]] = {}
        missing = []
        mismatch = []

        for item in desired:
            if not isinstance(item, dict):
                continue

            skill_id = str(item.get("skill_id", ""))
            if not skill_id:
                continue

            desired_by_skill[skill_id] = item
            local = installed.get(skill_id)

            if local is None or local.get("status") not in ("installed", "updated"):
                missing.append({
                    "skill_id": skill_id,
                    "version": item.get("version"),
                })
                continue

            if str(local.get("current_version")) != str(item.get("version")):
                mismatch.append({
                    "skill_id": skill_id,
                    "desired_version": item.get("version"),
                    "actual_version": local.get("current_version"),
                })

        extra = [
            {"skill_id": skill_id, "version": record.get("current_version")}
            for skill_id, record in installed.items()
            if skill_id not in desired_by_skill
        ]

        return {
            "missing": missing,
            "version_mismatch": mismatch,
            "extra": extra,
        }
