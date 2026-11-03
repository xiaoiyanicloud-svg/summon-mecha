"""模拟器安装适配器 (MockAdapter) —— 用于本地联调、隔离测试与自动化验证"""

import shutil
import time
from typing import Any, Dict, Optional

from zhjj_agent.adapters.base import BaseInstallAdapter


class MockAdapter(BaseInstallAdapter):
    def __init__(self, app_dir: Optional[str] = None):
        self.app_dir = app_dir
        self._skills: Dict[str, Dict[str, Any]] = {}
        self._executions: Dict[str, Dict[str, Any]] = {}

    @property
    def model_code(self) -> str:
        return "mock"

    @property
    def is_hardware_verified(self) -> bool:
        return False  # 模拟适配器，非真机

    def prepare(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        return True

    def install(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        self._skills[str(skill_id)] = {
            "skill_id": str(skill_id),
            "version": str(version),
            "install_path": install_path,
            "manifest": manifest,
            "enabled": True,
            "installed_at": int(time.time()),
        }
        return True

    def observe(self, skill_id: str) -> Dict[str, Any]:
        info = self._skills.get(str(skill_id))
        if not info and self.app_dir:
            state_file = os.path.join(self.app_dir, "state", "installed.json")
            if os.path.isfile(state_file):
                try:
                    with open(state_file, "r", encoding="utf-8") as f:
                        data = json.load(f)
                    rec = data.get(str(skill_id))
                    if rec:
                        info = {
                            "skill_id": str(skill_id),
                            "version": rec.get("current_version", "1.0.0"),
                            "install_path": rec.get("install_path", ""),
                            "manifest": rec.get("manifest", {}),
                            "enabled": rec.get("enabled", True),
                            "installed_at": int(time.time()),
                        }
                        self._skills[str(skill_id)] = info
                except Exception:
                    pass

        if not info:
            return {"installed": False, "status": "not_installed"}
        return {
            "installed": True,
            "version": info["version"],
            "enabled": info["enabled"],
            "status": "active" if info["enabled"] else "disabled",
        }

    def enable(self, skill_id: str) -> bool:
        info = self._skills.get(str(skill_id))
        if info:
            info["enabled"] = True
            return True
        return False

    def disable(self, skill_id: str) -> bool:
        info = self._skills.get(str(skill_id))
        if info:
            info["enabled"] = False
            for eid, ex in list(self._executions.items()):
                if ex.get("skill_id") == str(skill_id) and not ex.get("is_stopped"):
                    self.cancel_execution(eid)
            return True
        return False

    def uninstall(self, skill_id: str, install_path: str) -> bool:
        self._skills.pop(str(skill_id), None)
        for eid, ex in list(self._executions.items()):
            if ex.get("skill_id") == str(skill_id) and not ex.get("is_stopped"):
                self.cancel_execution(eid)
        return True

    def rollback(self, skill_id: str, restore_version: str, restore_path: str) -> bool:
        info = self._skills.get(str(skill_id))
        if info:
            info["version"] = str(restore_version)
            info["install_path"] = restore_path
            info["enabled"] = True
            return True
        return False

    def health(self) -> Dict[str, Any]:
        return {
            "status": "healthy",
            "model_code": self.model_code,
            "installed_count": len(self._skills),
            "running_executions": sum(1 for e in self._executions.values() if not e.get("is_stopped")),
        }

    # ── 技能执行与取消 ───────────────────────────────────────────────────────

    def execute_skill(self, execution_id: str, skill_id: str, plan_digest: Optional[str], params: Dict[str, Any]) -> bool:
        info = self._skills.get(str(skill_id))
        if not info or not info.get("enabled"):
            return False

        self._executions[execution_id] = {
            "execution_id": execution_id,
            "skill_id": str(skill_id),
            "plan_digest": plan_digest,
            "params": params,
            "status": "RUNNING",
            "is_stopped": False,
            "start_time": time.time(),
        }
        return True

    def cancel_execution(self, execution_id: str) -> bool:
        exec_info = self._executions.get(execution_id)
        if not exec_info:
            return False

        # 受理取消并标记安全停止
        exec_info["status"] = "CANCELED"
        exec_info["is_stopped"] = True
        return True

    def observe_execution(self, execution_id: str) -> Dict[str, Any]:
        exec_info = self._executions.get(execution_id)
        if not exec_info:
            return {
                "status": "UNKNOWN",
                "is_stopped": False,
                "error_msg": "执行记录不存在",
            }

        # 模拟执行超时自动完成
        if exec_info["status"] == "RUNNING":
            if time.time() - exec_info["start_time"] > 0.05:  # 0.05s 后模拟动作完成
                exec_info["status"] = "SUCCEEDED"
                exec_info["is_stopped"] = True

        return {
            "status": exec_info["status"],
            "is_stopped": exec_info["is_stopped"],
            "error_msg": exec_info.get("error_msg"),
        }
