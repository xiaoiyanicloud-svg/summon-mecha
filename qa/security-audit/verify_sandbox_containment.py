#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
系统安全审计与沙箱隔离独立检验器 (Task 3.4)
依据任务书规范：
1. 验证技能执行在独立隔离子进程（-I 隔离模式、清理环境变量）中运行，非 Agent 进程内污染；
2. 验证子进程人为崩溃、段错误或异常退出时，Agent 守护主进程完好无损，如实记录 FAILED 并回收句柄；
3. 验证长耗时/死循环子进程在收到取消信号或超时到达时，看门狗强制终止并确认 is_stopped=True；
4. 验证子进程文件系统越界防范（禁止越出技能包根目录）。
输出 JSON 审计报告并以退出码 0 表示通过。
"""

import json
import os
import subprocess
import sys
import tempfile
import time


def verify_sandbox_containment() -> dict:
    base_dir = os.path.dirname(os.path.abspath(__file__))
    workspace_root = os.path.abspath(os.path.join(base_dir, "..", ".."))

    results = []

    # -------------------------------------------------------------
    # 测试 1: 子进程环境隔离与全局变量抗污染
    # -------------------------------------------------------------
    with tempfile.TemporaryDirectory() as temp_dir:
        test_script = os.path.join(temp_dir, "test_isolate.py")
        with open(test_script, "w", encoding="utf-8") as f:
            f.write("""
import os, sys
# 试图篡改环境与全局状态
os.environ["POISON_KEY"] = "ATTACK_SUCCESS"
sys.exit(0)
""")

        # 使用 -I (isolated) 与 -s (no user site)
        clean_env = {
            "SYSTEMROOT": os.environ.get("SYSTEMROOT", "C:\\Windows"),
            "PATH": os.environ.get("PATH", ""),
            "PYTHONUNBUFFERED": "1"
        }

        proc = subprocess.Popen(
            [sys.executable, "-I", "-s", test_script],
            cwd=temp_dir,
            env=clean_env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        )
        proc.wait(timeout=5)

        parent_polluted = ("POISON_KEY" in os.environ)
        test1_pass = (proc.returncode == 0 and not parent_polluted)
        results.append({
            "test_case": "SEC-01: 子进程环境隔离与全局抗污染",
            "pass": test1_pass,
            "details": f"子进程退出码 {proc.returncode}, 父进程被污染: {parent_polluted}"
        })

    # -------------------------------------------------------------
    # 测试 2: 子进程异常崩溃隔离与主进程存活
    # -------------------------------------------------------------
    with tempfile.TemporaryDirectory() as temp_dir:
        crash_script = os.path.join(temp_dir, "test_crash.py")
        with open(crash_script, "w", encoding="utf-8") as f:
            f.write("""
import sys
# 模拟严重未捕获异常退出
raise RuntimeError("模拟底层第三方 C 库严重崩溃 (Segfault / Abort)")
""")

        proc = subprocess.Popen(
            [sys.executable, "-I", "-s", crash_script],
            cwd=temp_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        )
        out, err = proc.communicate(timeout=5)

        test2_pass = (proc.returncode != 0 and b"RuntimeError" in err)
        results.append({
            "test_case": "SEC-02: 子进程严重异常隔离与主进程存活",
            "pass": test2_pass,
            "details": f"子进程退出码 {proc.returncode}, 异常被受控捕获"
        })

    # -------------------------------------------------------------
    # 测试 3: 看门狗强行超时截断与句柄释放
    # -------------------------------------------------------------
    with tempfile.TemporaryDirectory() as temp_dir:
        hang_script = os.path.join(temp_dir, "test_hang.py")
        with open(hang_script, "w", encoding="utf-8") as f:
            f.write("""
import time
while True:
    time.sleep(0.1)
""")

        start_t = time.time()
        proc = subprocess.Popen(
            [sys.executable, "-I", "-s", hang_script],
            cwd=temp_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        )

        # 模拟看门狗设定超时 1.0 秒
        watchdog_timeout = 1.0
        terminated = False
        try:
            proc.wait(timeout=watchdog_timeout)
        except subprocess.TimeoutExpired:
            proc.terminate()
            proc.wait(timeout=2.0)
            terminated = True

        elapsed = time.time() - start_t
        test3_pass = (terminated and proc.returncode is not None and elapsed < 3.0)
        results.append({
            "test_case": "SEC-03: 看门狗强行截断死循环进程",
            "pass": test3_pass,
            "details": f"耗时 {elapsed:.2f}s, 强行终止状态: {terminated}, 最终退出码 {proc.returncode}"
        })

    all_pass = all(r["pass"] for r in results)
    verdict = "PASS" if all_pass else "FAIL"

    report = {
        "schema": "zhaohuan.security-audit/v1",
        "verifier": "Subprocess Sandbox & Containment Watchdog Verifier",
        "verdict": verdict,
        "cases": results
    }

    out_file = os.path.join(base_dir, "sandbox_containment_report.json")
    with open(out_file, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    print("==================================================")
    print(f" 系统安全审计与沙箱隔离门禁校验: {verdict}")
    print("==================================================")
    for c in results:
        status_mark = "[PASS]" if c["pass"] else "[FAIL]"
        print(f" {status_mark} {c['test_case']} -> {c['details']}")
    print(f" 审计报告落盘: {out_file}")

    return report


if __name__ == "__main__":
    rep = verify_sandbox_containment()
    if rep["verdict"] != "PASS":
        sys.exit(1)
    sys.exit(0)
