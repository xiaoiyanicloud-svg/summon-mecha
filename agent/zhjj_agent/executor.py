"""技能执行监督器：执行、取消、物理观测与资源互斥协调"""

import os
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
from zhjj_agent.entitlement import EntitlementGuard, entitlement_check_required
from zhjj_agent.sandbox import SandboxRunner
from zhjj_agent.storage import Storage

#: T6 开关：要求执行路径必须持有沙箱执行器。**默认关闭**（关 = 行为与接线前一致）。
SANDBOX_REQUIRE_ENV = "ZHJJ_REQUIRE_SANDBOX"

#: 开关打开但没有 runner 时的错误码（fail-closed，不调用适配器、不占租约）。
SANDBOX_UNAVAILABLE = "SANDBOX_UNAVAILABLE"


def sandbox_required() -> bool:
    """读取 T6 开关（每次调用都读环境，便于运行期切换与测试）。"""
    value = (os.environ.get(SANDBOX_REQUIRE_ENV, "") or "").strip().lower()

    return value in ("1", "true", "yes", "on")


class SkillExecutor:
    def __init__(
        self,
        storage: Storage,
        adapter: BaseInstallAdapter,
        metrics=None,
        sandbox_runner: Optional[SandboxRunner] = None,
        entitlement_guard: Optional[EntitlementGuard] = None,
    ):
        self.storage = storage
        self.adapter = adapter
        # A3：租约冲突必须计数 —— 它是"两台逻辑执行同时想动同一台机器"的唯一证据，
        # 而这条路径只返回一句错误文案，不留任何痕迹。
        self.metrics = metrics
        # T6：沙箱执行器（可选）。未注入时默认行为与接线前完全一致；
        # 只有 `ZHJJ_REQUIRE_SANDBOX` 打开时才变成 fail-closed。
        self.sandbox_runner = sandbox_runner
        # A5：授权回源（可选）。只有 `ZHJJ_ENTITLEMENT_CHECK` 打开时才启用。
        self.entitlement_guard = entitlement_guard
        if sandbox_runner is not None and hasattr(adapter, "bind_sandbox_runner"):
            adapter.bind_sandbox_runner(sandbox_runner)

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
        1. （T6）若要求沙箱但没有 runner → **fail-closed**，不调用适配器、不占租约
        2. 检查技能已安装且处于启用态 (enabled)
        3. 获得本地独占资源租约 (防冲突动作并发)
        4. 调用适配器 execute_skill
        """
        # 0. T6 fail-closed：必须在任何副作用（查库、占租约、调适配器）之前判定。
        #    顺序反了就会出现"拒绝执行但租约已被占用"，下一台设备要干等过期。
        if sandbox_required() and self.sandbox_runner is None:
            return False, EXEC_FAILED, SANDBOX_UNAVAILABLE

        # 0.5 A5 fail-closed：授权回源必须在任何副作用之前。设备离线数天后仍按旧指令
        #     执行，是"试用回收被拔线绕过"的唯一入口。
        if entitlement_check_required():
            if self.entitlement_guard is None:
                return False, EXEC_FAILED, "ENTITLEMENT_GUARD_UNAVAILABLE"

            allowed, reason, _source = self.entitlement_guard.check(skill_id)
            if not allowed:
                return False, EXEC_FAILED, f"ENTITLEMENT_{reason}"

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
            if self.metrics is not None:
                self.metrics.inc("task_lease_conflict_total")
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
                return self._failed_or_unknown(execution_id, "底层机型适配器启动执行失败")
            return True, EXEC_RUNNING, None
        except Exception as e:
            return self._failed_or_unknown(execution_id, f"执行异常: {e}")

    def _failed_or_unknown(self, execution_id: str, message: str) -> Tuple[bool, str, Optional[str]]:
        """启动调用异常时先观察外部状态；未确认停止绝不能释放互斥租约。"""
        try:
            obs = self.adapter.observe_execution(execution_id)
        except Exception as observe_error:
            return False, EXEC_UNKNOWN, f"{message}; 独立观察失败: {observe_error}"
        if obs.get("is_stopped"):
            self.storage.release_execution_lease(execution_id)
            return False, EXEC_FAILED, message
        return False, EXEC_UNKNOWN, f"{message}; 未能确认安全停止"

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
