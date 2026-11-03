"""机型安装适配器基类 (BaseInstallAdapter)"""

from abc import ABC, abstractmethod
from typing import Any, Dict, Optional


class BaseInstallAdapter(ABC):
    @property
    @abstractmethod
    def model_code(self) -> str:
        """支持的机型标识（如 mock, unitree_g1）。"""
        pass

    @property
    @abstractmethod
    def is_hardware_verified(self) -> bool:
        """是否已经在真实物理设备上实测通过。"""
        pass

    @abstractmethod
    def prepare(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        """准备与依赖校验。"""
        pass

    @abstractmethod
    def install(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        """执行安装与注册。"""
        pass

    @abstractmethod
    def observe(self, skill_id: str) -> Dict[str, Any]:
        """独立观测设备侧实际安装与运行状态。"""
        pass

    @abstractmethod
    def enable(self, skill_id: str) -> bool:
        """启用技能（仅允许调用，不能自动触发物理动作）。"""
        pass

    @abstractmethod
    def disable(self, skill_id: str) -> bool:
        """停用技能（冻结调用）。"""
        pass

    @abstractmethod
    def uninstall(self, skill_id: str, install_path: str) -> bool:
        """安全卸载与资源释放。"""
        pass

    @abstractmethod
    def rollback(self, skill_id: str, restore_version: str, restore_path: str) -> bool:
        """回滚激活目标版本。"""
        pass

    @abstractmethod
    def health(self) -> Dict[str, Any]:
        """设备健康检查探针。"""
        pass

    # ── 技能执行与取消接口（OP 7/8）─────────────────────────────────────────

    @abstractmethod
    def execute_skill(self, execution_id: str, skill_id: str, plan_digest: Optional[str], params: Dict[str, Any]) -> bool:
        """启动一次具体技能执行。"""
        pass

    @abstractmethod
    def cancel_execution(self, execution_id: str) -> bool:
        """请求取消一次具体技能执行。"""
        pass

    @abstractmethod
    def observe_execution(self, execution_id: str) -> Dict[str, Any]:
        """独立观测一次技能执行是否安全停止或完成。
        返回:
        {
            "status": "RUNNING" | "SUCCEEDED" | "FAILED" | "CANCELED" | "UNKNOWN",
            "is_stopped": bool,
            "error_msg": str or None
        }
        """
        pass
