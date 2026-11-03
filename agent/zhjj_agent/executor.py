"""技能执行监督器：执行、取消、物理观测与资源互斥协调"""

import time
from typing import Any, Dict, Optional, Tuple

from zhjj_agent.adapters.base import BaseInstallAdapter
from zhjj_agent.constants import (
    ERR_ADAPTER_ERROR,
    ERR_EXECUTION_FAILED,
    EXEC_ACCEPTED,
    EXEC_CANCELED,
    EXEC_CANCEL_REQUESTED,
    EXEC_FAILED,
    EXEC_RUNNING,
    EXEC_SUCCEEDED,
    EXEC_UNKNOWN,
)
from zhjj_agent.storage import Storage


class SkillExecutor:
    def __init__(self, storage: Storage, adapter: BaseInstallAdapter):
        self.storage = storage
        self.adapter = adapter

    def execute(
        self,
        execution_id: str,
        skill_id: str,
        plan_digest: Optional[str] = None,
        params: Optional[Dict[str, Any]] = None,
        resource_name: str = "motion_control",
        lease_duration: int = 60
    ) -> Tuple[bool, str, Optional[str]]:
        """启动技能执行。
        必须通过：
        1. 检查技能已安装且处于启用态 (enabled)
        2. 获得本地独占资源租约 (防冲突动作并发)
        3. 调用适配器 execute_skill
        """
        # 1. 检查技能安装与启用状态
        skill = self.storage.get_installed_skill(skill_id)
        if not skill or not skill.get("enabled"):
            return False, EXEC_FAILED, "技能未安装或处于禁用状态，禁止执行"

        # 2. 获取独占资源租约
        acquired = self.storage.acquire_execution_lease(
            execution_id=execution_id,
            skill_id=skill_id,
            resource_name=resource_name,
            duration_seconds=lease_duration
        )
        if not acquired:
            return False, EXEC_FAILED, f"资源 {resource_name} 已被其他执行占用，互斥阻塞"

        # 3. 调用适配器启动执行
        try:
            ok = self.adapter.execute_skill(
                execution_id=execution_id,
                skill_id=skill_id,
                plan_digest=plan_digest,
                params=params or {}
            )
            if not ok:
                self.storage.release_execution_lease(execution_id)
                return False, EXEC_FAILED, "底层机型适配器启动执行失败"
            return True, EXEC_RUNNING, None
        except Exception as e:
            self.storage.release_execution_lease(execution_id)
            return False, EXEC_FAILED, f"执行异常: {e}"

    def cancel(self, execution_id: str, timeout_seconds: float = 2.0) -> Tuple[bool, str, Optional[str]]:
        """取消技能执行。
        安全原则：
        1. 调用适配器 cancel_execution
        2. 必须等待独立 observe 证实物理停止后才释放资源租约；
           若尚未确认停止，严禁提前释放锁启动冲突动作！
        """
        try:
            self.adapter.cancel_execution(execution_id)
        except Exception as e:
            return False, EXEC_UNKNOWN, f"下发取消异常: {e}"

        start_t = time.time()
        while time.time() - start_t < timeout_seconds:
            obs = self.adapter.observe_execution(execution_id)
            if obs.get("is_stopped"):
                # 确认已停止，释放资源锁
                self.storage.release_execution_lease(execution_id)
                return True, EXEC_CANCELED, None
            time.sleep(0.01)

        # 超时仍未确认停止 -> 状态标为 UNKNOWN，保持资源锁定，严防启动下一个冲突动作
        return False, EXEC_UNKNOWN, "取消已请求但未在时限内确认安全停止，资源锁已锁定以保护安全"

    def observe(self, execution_id: str) -> Dict[str, Any]:
        """独立观测执行状态并管理租约释放。"""
        obs = self.adapter.observe_execution(execution_id)
        status = obs.get("status", EXEC_UNKNOWN)
        if status in (EXEC_SUCCEEDED, EXEC_FAILED, EXEC_CANCELED) and obs.get("is_stopped"):
            self.storage.release_execution_lease(execution_id)
        return obs
