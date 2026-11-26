"""Agent 启动器（自更新的执行端）。

职责边界刻意很窄：**它不认识业务，只认识版本目录和指针**。
launcher 本身留在原位不动，新版本只替换 `agent/versions/<version>/` 下的代码 ——
这样一次坏升级永远不会连"能启动并回滚"的那段代码一起弄丢。

启动流程（每一步都能被单独测试）：

    1. 解析 `agent/current.json`；不可信则回退 `previous.json`（I2）
    2. 如果指针指向的版本处于 SWITCHED：先记一次启动（在自检之前，崩溃也算数）
    3. 在版本目录里真实 import 一次 `zhjj_agent` 做自检
    4. 通过 → 标记 HEALTHY；失败累计到 2 次 → 原子回滚到 previous（仅一次）
    5. 启动选中的版本（子进程 `-m zhjj_agent.main`），退出码原样传回

退出码约定（供 supervisor 使用）：
    0/其它  子进程的退出码
    2       自检失败但还有重试机会，**需要 supervisor 再拉起一次**
    3       致命：无可用版本 / 已回滚目标也坏了 / 回滚失败
    4       参数错误
"""

import argparse
import os
import subprocess
import sys
from typing import List, Optional

from zhjj_agent.telemetry import log_event
from zhjj_agent.update import BootGuard, self_check_cli

EXIT_RESTART_REQUIRED = 2
EXIT_ABORT = 3
EXIT_USAGE = 4


def resolve_install_dir(config_path: Optional[str], install_dir: Optional[str]) -> str:
    if install_dir:
        return os.path.abspath(install_dir)
    from_env = os.environ.get("ZHJJ_AGENT_HOME")
    if from_env:
        return os.path.abspath(from_env)
    if config_path:
        return os.path.dirname(os.path.abspath(config_path))
    return os.getcwd()


def _launch_version(version_dir: str, version: str, config_path: str, install_dir: str) -> int:
    """在版本目录里启动 `zhjj_agent.main`。环境变量保证心跳上报的是真正在跑的版本。"""
    env = dict(os.environ)
    env["PYTHONPATH"] = version_dir + (os.pathsep + env["PYTHONPATH"] if env.get("PYTHONPATH") else "")
    env["ZHJJ_AGENT_VERSION"] = version
    env["ZHJJ_AGENT_HOME"] = install_dir
    log_event(
        "INFO",
        "agent_launch_version",
        status="RUNNING",
        extra={"version": version, "version_dir": version_dir},
    )
    try:
        proc = subprocess.run(
            [sys.executable, "-m", "zhjj_agent.main", "--config", config_path],
            cwd=version_dir,
            env=env,
        )
        return int(proc.returncode)
    except OSError as error:
        log_event(
            "ERROR",
            "agent_launch_failed",
            status="ABORT",
            extra={"version": version, "exception": type(error).__name__},
        )
        return EXIT_ABORT


def _launch_bundled(config_path: str) -> int:
    """没有受管版本目录时，跑当前这份代码（与自更新前的行为一致）。"""
    from zhjj_agent.main import run

    try:
        run(config_path)
        return 0
    except KeyboardInterrupt:
        return 0


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="召唤机甲 · Agent 启动器（自更新执行端）")
    parser.add_argument("--config", help="Agent 配置文件 config.json 路径")
    parser.add_argument("--install-dir", help="安装目录（默认取 --config 所在目录）")
    parser.add_argument("--self-check", action="store_true", help="只做启动自检并输出 JSON（无副作用）")
    args = parser.parse_args(argv)

    install_dir = resolve_install_dir(args.config, args.install_dir)

    if args.self_check:
        return self_check_cli(install_dir)

    if not args.config:
        parser.print_usage(sys.stderr)
        print("[zhjj-launcher] --config 是必需的（--self-check 除外）", file=sys.stderr)
        return EXIT_USAGE

    config_path = os.path.abspath(args.config)
    if not os.path.isfile(config_path):
        print(f"[zhjj-launcher] 配置文件不存在: {config_path}", file=sys.stderr)
        return EXIT_USAGE

    guard = BootGuard(install_dir)
    decision = guard.evaluate_boot()

    if decision.action == "abort":
        log_event(
            "ERROR",
            "agent_boot_aborted",
            status="ABORT",
            error_code=decision.error_code,
            extra={"version": decision.version, "detail": decision.detail},
        )
        print(f"[zhjj-launcher] 启动中止: {decision.error_code} {decision.detail}", file=sys.stderr)
        return EXIT_ABORT

    if decision.action == "restart_required":
        log_event(
            "WARN",
            "agent_boot_restart_required",
            status="RESTART",
            error_code=decision.error_code,
            extra={"version": decision.version, "detail": decision.detail},
        )
        print(
            f"[zhjj-launcher] 新版本自检失败（{decision.detail}），需要 supervisor 再次拉起 "
            f"以累计启动次数并触发回滚",
            file=sys.stderr,
        )
        return EXIT_RESTART_REQUIRED

    if decision.version_dir:
        return _launch_version(decision.version_dir, str(decision.version), config_path, install_dir)

    return _launch_bundled(config_path)


if __name__ == "__main__":
    sys.exit(main())
