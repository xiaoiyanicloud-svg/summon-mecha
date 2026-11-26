"""沙箱接口与便携实现（WBS A6）。

这个模块只做一件事：把"跑一个动作进程"变成**有边界的、可观测的、可被杀死的**操作。
它不假装自己能做到操作系统级隔离。

诚实边界（必须写进任何引用本模块的文档）：

  · 已强制：独立进程、超时、stdout/stderr 字节上限、环境变量白名单、cwd 指定、进程树杀死。
  · **未强制**：内存上限、文件系统隔离、网络隔离、cgroup / seccomp / AppArmor / Windows Job Object。
    这些需要 OS 级加固；本机（Windows 11 + 受控测试）无法验证，一律标 NOT_RUN，
    不允许因为 `capabilities()` 里有对应字段就当成"已支持"。

A6.4 的硬约束在代码里落成属性：
  `SandboxResult.physical_stop_confirmed` **恒为 False**，`is_safety_evidence` **恒为 False**。
  子进程退出码 0 只说明"它自己退了"，不说明动作安全，更不说明物理上停了 ——
  真正的停止证据只能来自独立 StopObservation（见 `stop_observation.py`）。
"""

from __future__ import annotations

import os
import subprocess
import threading
import time
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Sequence


class SandboxUnavailableError(RuntimeError):
    """需要沙箱执行器但未注入。

    T6 的接线原则：**没有 runner 就拒绝起本地进程**，绝不静默降级到裸
    `subprocess.Popen` —— 后者绕过了超时、输出上限、环境白名单与进程树杀死，
    等于把"受控执行"悄悄换回"无边界执行"。
    """


@dataclass(frozen=True)
class SandboxLimits:
    """调用方声明的限制。声明 ≠ 强制；实际强制项见 `SandboxRunner.capabilities()`。"""

    timeout_seconds: float = 30.0
    max_stdout_bytes: int = 64 * 1024
    max_stderr_bytes: int = 64 * 1024
    max_memory_bytes: Optional[int] = None
    network: str = "deny"
    filesystem: str = "sandbox_dir"
    env: Dict[str, str] = field(default_factory=dict)


@dataclass
class SandboxResult:
    exit_code: Optional[int]
    timed_out: bool
    kill_reason: Optional[str]
    stdout: str
    stderr: str
    stdout_truncated: bool
    stderr_truncated: bool
    duration_seconds: float
    enforced: List[str] = field(default_factory=list)
    not_enforced: List[str] = field(default_factory=list)

    @property
    def physical_stop_confirmed(self) -> bool:
        """**恒为 False**。退出码不是物理停止证据（A6.4）。"""
        return False

    @property
    def is_safety_evidence(self) -> bool:
        """**恒为 False**。沙箱只能证明"进程被边界约束"，不能证明"动作安全"。"""
        return False

    def to_dict(self) -> Dict[str, Any]:
        return {
            "exit_code": self.exit_code,
            "timed_out": self.timed_out,
            "kill_reason": self.kill_reason,
            "stdout": self.stdout,
            "stderr": self.stderr,
            "stdout_truncated": self.stdout_truncated,
            "stderr_truncated": self.stderr_truncated,
            "duration_seconds": self.duration_seconds,
            "enforced": list(self.enforced),
            "not_enforced": list(self.not_enforced),
            "physical_stop_confirmed": self.physical_stop_confirmed,
            "is_safety_evidence": self.is_safety_evidence,
        }


class SandboxRunner(ABC):
    """沙箱执行器接口（A6.1）。实现方必须如实回答"哪些限制真的强制了"。"""

    @abstractmethod
    def capabilities(self) -> Dict[str, Any]:
        raise NotImplementedError

    @abstractmethod
    def run(self, argv: Sequence[str], cwd: str, limits: Optional[SandboxLimits] = None) -> SandboxResult:
        raise NotImplementedError


class SubprocessSandboxRunner(SandboxRunner):
    """当前真正可运行的实现：独立进程 + 看门狗 + 输出上限 + 环境白名单。

    这不是安全沙箱，是**边界与可观测性**。任何需要"阻止恶意代码"的场景，
    都必须等 OS 级加固（A6.2 列出的 cgroup/seccomp/AppArmor/Job Object）到位后再谈。
    """

    #: 无条件继承的环境变量（少了它们连 `python` 都可能起不来）
    BASE_ENV_ALLOWLIST = ("PATH", "PATHEXT", "SYSTEMROOT", "WINDIR", "COMSPEC", "LANG", "LC_ALL")

    def __init__(self, sandbox_root: Optional[str] = None):
        self.sandbox_root = sandbox_root

    # ── 能力声明 ─────────────────────────────────────────────────────────────

    def capabilities(self) -> Dict[str, Any]:
        return {
            "process_isolation": True,
            "timeout": True,
            "stdout_limit_bytes": True,
            "stderr_limit_bytes": True,
            "env_allowlist": True,
            "cwd": "指定但不是强制围栏",
            "memory_limit": False,
            "filesystem_isolation": False,
            "network_isolation": False,
            "os_level": {
                "cgroup": False,
                "seccomp": False,
                "apparmor": False,
                "job_object": False,
            },
            "os_level_hardening": "NOT_RUN",
            "physical_stop_evidence": False,
        }

    # ── 执行 ─────────────────────────────────────────────────────────────────

    def run(self, argv: Sequence[str], cwd: str, limits: Optional[SandboxLimits] = None) -> SandboxResult:
        limits = limits or SandboxLimits()
        if not argv:
            raise ValueError("argv 不能为空")

        root = os.path.abspath(cwd or self.sandbox_root or os.getcwd())
        os.makedirs(root, exist_ok=True)
        tmp_dir = os.path.join(root, "tmp")
        os.makedirs(tmp_dir, exist_ok=True)

        env = self._build_env(limits, tmp_dir)

        popen_kwargs: Dict[str, Any] = {
            "cwd": root,
            "env": env,
            "stdin": subprocess.DEVNULL,
            "stdout": subprocess.PIPE,
            "stderr": subprocess.PIPE,
        }
        if os.name == "nt":
            popen_kwargs["creationflags"] = getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0)
        else:
            popen_kwargs["start_new_session"] = True

        started = time.monotonic()
        process = subprocess.Popen(list(argv), **popen_kwargs)

        state: Dict[str, Any] = {
            "kill_reason": None,
            "stdout": bytearray(),
            "stderr": bytearray(),
            "stdout_truncated": False,
            "stderr_truncated": False,
        }
        lock = threading.Lock()
        stop_event = threading.Event()

        def pump(stream, key: str, truncated_key: str, limit_bytes: int) -> None:
            try:
                while True:
                    chunk = stream.read(4096)
                    if not chunk:
                        break
                    with lock:
                        if len(state[key]) < limit_bytes:
                            remaining = limit_bytes - len(state[key])
                            state[key].extend(chunk[:remaining])
                            if len(chunk) > remaining:
                                state[truncated_key] = True
                                if state["kill_reason"] is None:
                                    state["kill_reason"] = f"{key}_limit"
                                stop_event.set()
                        else:
                            state[truncated_key] = True
                            if state["kill_reason"] is None:
                                state["kill_reason"] = f"{key}_limit"
                            stop_event.set()
            except (OSError, ValueError):
                pass

        readers = [
            threading.Thread(target=pump, args=(process.stdout, "stdout", "stdout_truncated", int(limits.max_stdout_bytes)), daemon=True),
            threading.Thread(target=pump, args=(process.stderr, "stderr", "stderr_truncated", int(limits.max_stderr_bytes)), daemon=True),
        ]
        for thread in readers:
            thread.start()

        deadline = started + float(limits.timeout_seconds)
        timed_out = False
        while process.poll() is None:
            if stop_event.is_set():
                break
            if time.monotonic() >= deadline:
                timed_out = True
                break
            time.sleep(0.02)

        if process.poll() is None:
            with lock:
                reason = state["kill_reason"] or "timeout"
            self._kill_tree(process)
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
        else:
            reason = state["kill_reason"]

        # 进程树已被杀，管道应到达 EOF；给读线程一个有限等待，避免测试卡死。
        for thread in readers:
            thread.join(timeout=2)
        for stream in (process.stdout, process.stderr):
            try:
                if stream is not None:
                    stream.close()
            except OSError:
                pass

        duration = time.monotonic() - started

        return SandboxResult(
            exit_code=process.returncode,
            timed_out=timed_out,
            kill_reason=reason,
            stdout=bytes(state["stdout"]).decode("utf-8", errors="replace"),
            stderr=bytes(state["stderr"]).decode("utf-8", errors="replace"),
            stdout_truncated=bool(state["stdout_truncated"]),
            stderr_truncated=bool(state["stderr_truncated"]),
            duration_seconds=round(duration, 3),
            enforced=[
                "process_isolation",
                "timeout",
                "stdout_limit_bytes",
                "stderr_limit_bytes",
                "env_allowlist",
                "process_tree_kill",
            ],
            not_enforced=[
                "memory_limit",
                "filesystem_isolation",
                "network_isolation",
                "cgroup",
                "seccomp",
                "apparmor",
                "job_object",
            ],
        )

    # ── 内部 ─────────────────────────────────────────────────────────────────

    def _build_env(self, limits: SandboxLimits, tmp_dir: str) -> Dict[str, str]:
        """最小环境：白名单继承 + 显式追加；TMP/HOME 指向沙箱目录。"""
        env: Dict[str, str] = {}
        for name in self.BASE_ENV_ALLOWLIST:
            value = os.environ.get(name)
            if value is not None:
                env[name] = value

        env["TMP"] = tmp_dir
        env["TEMP"] = tmp_dir
        env["HOME"] = tmp_dir
        env["USERPROFILE"] = tmp_dir

        for key, value in (limits.env or {}).items():
            if not isinstance(key, str) or not isinstance(value, str):
                raise ValueError("沙箱环境变量必须是字符串键值")
            env[key] = value

        return env

    @staticmethod
    def _kill_tree(process: "subprocess.Popen[Any]") -> None:
        """杀**整棵进程树**：只杀父进程会让它派生的动作进程变成孤儿继续跑。"""
        if os.name == "nt":
            try:
                subprocess.run(
                    ["taskkill", "/F", "/T", "/PID", str(process.pid)],
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.DEVNULL,
                    timeout=5,
                    check=False,
                )
                return
            except (OSError, subprocess.SubprocessError):
                pass
            try:
                process.kill()
            except OSError:
                pass
            return

        try:
            import signal

            os.killpg(os.getpgid(process.pid), signal.SIGKILL)
        except (OSError, ProcessLookupError):
            try:
                process.kill()
            except OSError:
                pass
