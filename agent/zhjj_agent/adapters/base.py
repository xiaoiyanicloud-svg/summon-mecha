"""机型安装适配器基类 (BaseInstallAdapter)"""

from abc import ABC, abstractmethod
from typing import Any, Dict, Optional, Sequence

from zhjj_agent.sandbox import (
    SandboxLimits,
    SandboxResult,
    SandboxRunner,
    SandboxUnavailableError,
)


class BaseInstallAdapter(ABC):
    """所有机型适配器的基类。

    ── T6：本地进程只能走 `run_local_process()` ─────────────────────────────
    适配器自己 `subprocess.Popen(...)` 会绕过沙箱的超时 / 输出上限 / 环境白名单 /
    进程树杀死。本类把"起本地进程"收成一个受保护方法：**没有注入 runner 就抛异常**，
    而不是悄悄退化成裸 subprocess。当前存量的适配器还没有本地进程调用，
    这条约束是给未来堵路用的 —— 接线本身就是价值。
    """

    #: 沙箱执行器。由 `SkillExecutor` / 上层注入；未注入时为 None。
    #: 用类属性做默认值，兼容既不调 `super().__init__()` 也不定义该属性的存量适配器。
    _sandbox_runner: Optional[SandboxRunner] = None

    def bind_sandbox_runner(self, runner: Optional[SandboxRunner]) -> None:
        """注入（或清空）沙箱执行器。"""
        self._sandbox_runner = runner

    def capabilities(self) -> Dict[str, Any]:
        """如实报告"实际强制了什么"。

        ⚠ 不要把 `SubprocessSandboxRunner` 说成 OS 级隔离：它只是**受控子进程**
          （独立进程 / 超时 / 输出上限 / 环境白名单 / cwd / 进程树杀死），
          没有 seccomp / cgroup / namespace / Job Object。
        """
        runner = getattr(self, "_sandbox_runner", None)

        if runner is None:
            return {
                "sandbox_runner": None,
                "process_execution": "BLOCKED_NO_RUNNER",
                "enforced": [],
                "not_enforced": [
                    "process_isolation",
                    "timeout",
                    "stdout_limit_bytes",
                    "stderr_limit_bytes",
                    "env_allowlist",
                    "memory_limit",
                    "filesystem_isolation",
                    "network_isolation",
                    "cgroup",
                    "seccomp",
                    "apparmor",
                    "job_object",
                ],
                "os_level_hardening": "NOT_RUN",
                "physical_stop_evidence": False,
                "note": "未注入 SandboxRunner：任何本地进程启动都会被拒绝（fail-closed）。",
            }

        caps = dict(runner.capabilities())
        caps["sandbox_runner"] = type(runner).__name__
        caps["process_execution"] = "SANDBOX_RUNNER"
        # 诚实边界：这里的"process_isolation"只表示**独立子进程**，不是 OS 级隔离。
        caps["isolation_level"] = "PROCESS_BOUNDARY_ONLY"
        caps["note"] = (
            "独立进程/超时/输出上限/环境白名单/进程树杀死；"
            "非 OS 级隔离（无 cgroup/seccomp/namespace/Job Object），"
            "也不能作为物理停止证据。"
        )

        return caps

    def run_local_process(
        self,
        argv: Sequence[str],
        cwd: str,
        limits: Optional[SandboxLimits] = None,
    ) -> SandboxResult:
        """在沙箱里启动一个本地进程。

        这是适配器启动本地进程的**唯一入口**。没有 runner 时抛
        `SandboxUnavailableError`，绝不静默降级成裸 `subprocess`。
        """
        runner = getattr(self, "_sandbox_runner", None)

        if runner is None:
            raise SandboxUnavailableError(
                f"适配器 {type(self).__name__} 未注入 SandboxRunner，拒绝启动本地进程: {list(argv)!r}"
            )

        return runner.run(argv, cwd, limits)

    @property
    @abstractmethod
    def model_code(self) -> str:
        """支持的机型标识（如 mock, unitree_g1）。"""
        raise NotImplementedError

    @property
    @abstractmethod
    def is_hardware_verified(self) -> bool:
        """是否已经在真实物理设备上实测通过。"""
        raise NotImplementedError

    @abstractmethod
    def prepare(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        """准备与依赖校验。"""
        raise NotImplementedError

    @abstractmethod
    def install(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        """执行安装与注册。"""
        raise NotImplementedError

    @abstractmethod
    def observe(self, skill_id: str) -> Dict[str, Any]:
        """独立观测设备侧实际安装与运行状态。"""
        raise NotImplementedError

    @abstractmethod
    def enable(self, skill_id: str) -> bool:
        """启用技能（仅允许调用，不能自动触发物理动作）。"""
        raise NotImplementedError

    @abstractmethod
    def disable(self, skill_id: str) -> bool:
        """停用技能（冻结调用）。"""
        raise NotImplementedError

    @abstractmethod
    def uninstall(self, skill_id: str, install_path: str) -> bool:
        """安全卸载与资源释放。"""
        raise NotImplementedError

    @abstractmethod
    def rollback(self, skill_id: str, restore_version: str, restore_path: str) -> bool:
        """回滚激活目标版本。"""
        raise NotImplementedError

    @abstractmethod
    def health(self) -> Dict[str, Any]:
        """设备健康检查探针。"""
        raise NotImplementedError

    # ── 技能执行与取消接口（OP 7/8）─────────────────────────────────────────

    @abstractmethod
    def execute_skill(self, execution_id: str, skill_id: str, plan_digest: Optional[str], params: Dict[str, Any]) -> bool:
        """启动一次具体技能执行。"""
        raise NotImplementedError

    @abstractmethod
    def cancel_execution(self, execution_id: str) -> bool:
        """请求取消一次具体技能执行。"""
        raise NotImplementedError

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
        raise NotImplementedError
