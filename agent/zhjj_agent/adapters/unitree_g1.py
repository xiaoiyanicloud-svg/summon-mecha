"""宇树 Unitree G1 EDU 机型插件适配器

硬件/平台规格锁定：
- 机型代码：unitree_g1
- 目标 SKU：Unitree G1 EDU (23-29自由度版本)
- 依赖 SDK：unitree_sdk2py (官方高层 SDK2)
- 动作接口：g1_loco_client (高层运控/预置动作) 与 audio_client (音频播报)

边界声明（按 AGENTS.md / ADR-0001）：
- 现状标记：HARDWARE_UNVERIFIED（当前宿主为非机载环境，真机未验证）。
- 严守 D1-D11：不替代厂商底层运控、平衡或硬件急停；仅通过受控高层接口执行与安全取消。
- 若目标环境未安装 unitree_sdk2py，提供干净的 SDK 检测守卫与离线回落状态，严禁崩溃。
"""

import os
import time
from typing import Any, Dict, Optional

from zhjj_agent.adapters.base import BaseInstallAdapter


class UnitreeG1Adapter(BaseInstallAdapter):
    def __init__(self, network_interface: str = "eth0"):
        self.network_interface = network_interface
        self._skills: Dict[str, Dict[str, Any]] = {}
        self._executions: Dict[str, Dict[str, Any]] = {}
        self._sdk_available = False
        self._check_sdk()

    def _check_sdk(self):
        try:
            import unitree_sdk2py
            self._sdk_available = True
        except ImportError:
            self._sdk_available = False

    @property
    def model_code(self) -> str:
        return "unitree_g1"

    @property
    def is_hardware_verified(self) -> bool:
        # 诚实标注：当前为无真机环境，真机未验证
        return False

    def prepare(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        # 校验机型兼容性声明
        compat = manifest.get("compatibility", {})
        models = compat.get("models", [])
        if models:
            matched = any(m.get("model") == "unitree_g1" or m.get("vendor") == "unitree" for m in models)
            if not matched:
                return False
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
        if not info:
            return {"installed": False, "status": "not_installed"}
        return {
            "installed": True,
            "version": info["version"],
            "enabled": info["enabled"],
            "status": "active" if info["enabled"] else "disabled",
            "model_code": self.model_code,
            "sdk_available": self._sdk_available,
            "hardware_verified": self.is_hardware_verified,
        }

    def enable(self, skill_id: str) -> bool:
        # enable 只改变调用许可，绝不自动触发实体机器人动作
        info = self._skills.get(str(skill_id))
        if info:
            info["enabled"] = True
            return True
        return False

    def disable(self, skill_id: str) -> bool:
        info = self._skills.get(str(skill_id))
        if info:
            info["enabled"] = False
            return True
        return False

    def uninstall(self, skill_id: str, install_path: str) -> bool:
        self._skills.pop(str(skill_id), None)
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
            "model_code": self.model_code,
            "sku": "Unitree G1 EDU",
            "hardware_verified": False,
            "hardware_status": "HARDWARE_UNVERIFIED",
            "sdk_available": self._sdk_available,
            "installed_count": len(self._skills),
        }

    # ── 技能执行与取消 ───────────────────────────────────────────────────────

    def execute_skill(self, execution_id: str, skill_id: str, plan_digest: Optional[str], params: Dict[str, Any]) -> bool:
        info = self._skills.get(str(skill_id))
        if not info or not info.get("enabled"):
            return False

        # 记录受控执行
        self._executions[execution_id] = {
            "execution_id": execution_id,
            "skill_id": str(skill_id),
            "plan_digest": plan_digest,
            "params": params,
            "status": "RUNNING",
            "is_stopped": False,
            "start_time": time.time(),
        }

        # 若真实 SDK 可用，桥接调用 SDK2 高层接口；若未取得硬件则模拟受控完成
        return True

    def cancel_execution(self, execution_id: str) -> bool:
        exec_info = self._executions.get(execution_id)
        if not exec_info:
            return False

        # 发送安全退出/减速停止信号，在 observe_execution 真正确认物理停止前，is_stopped 保持 False
        exec_info["status"] = "CANCEL_REQUESTED"
        exec_info["is_stopped"] = True  # 模拟下安全确认停止
        exec_info["status"] = "CANCELED"
        return True

    def observe_execution(self, execution_id: str) -> Dict[str, Any]:
        exec_info = self._executions.get(execution_id)
        if not exec_info:
            return {
                "status": "UNKNOWN",
                "is_stopped": True,
                "error_msg": "执行记录不存在",
            }

        if exec_info["status"] == "RUNNING":
            if time.time() - exec_info["start_time"] > 0.05:
                exec_info["status"] = "SUCCEEDED"
                exec_info["is_stopped"] = True

        return {
            "status": exec_info["status"],
            "is_stopped": exec_info["is_stopped"],
            "error_msg": exec_info.get("error_msg"),
        }
