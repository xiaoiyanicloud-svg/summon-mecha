"""RF01-RF08 语义验证器 (Semantic RF Gates Validator)
依据第一阶段第二轮返工任务书：
- 探针退出码为0不能直接算通过。
- 必须编写语义断言/验证器据 RF 门槛给出非零失败退出码。
- 在当前基线未修复时，此验证器必须失败（Exit code != 0）。
"""
import json
import os
import pathlib
import sys

def validate_rf_gates(agent_counterexamples_path: str, http_counterexamples_path: str, customer_counterexamples_path: str) -> dict:
    with open(agent_counterexamples_path, "r", encoding="utf-8") as f:
        agent_data = json.load(f)

    http_data = {}
    if os.path.exists(http_counterexamples_path):
        with open(http_counterexamples_path, "r", encoding="utf-8") as f:
            http_data = json.load(f)

    cust_data = {}
    if os.path.exists(customer_counterexamples_path):
        with open(customer_counterexamples_path, "r", encoding="utf-8") as f:
            cust_data = json.load(f)

    rf_results = {}

    # RF01: 真实平台包下载需设备认证，且真实安装成功
    # 门槛：authenticated_download_control.hash_matches == True
    # real_http_install.accepted == True 且 journal_status == 'COMMITTED' 且 installed_count > 0
    # 当前基线：real_http_install.accepted == False (401 Unauthorized), installed_count == 0
    r_http = http_data.get("real_http_install", {})
    rf01_pass = (
        http_data.get("authenticated_download_control", {}).get("hash_matches") is True
        and r_http.get("accepted") is True
        and r_http.get("installed_count", 0) > 0
        and r_http.get("journal_status") in ("COMMITTED", "REPORTED_ACKED")
    )
    rf_results["RF01"] = {
        "pass": bool(rf01_pass),
        "detail": f"accepted={r_http.get('accepted')}, journal_status={r_http.get('journal_status')}, installed_count={r_http.get('installed_count')}, error={r_http.get('error_msg')}"
    }

    # RF02: 尚未停止就释放租约和删除技能
    # 门槛：当外部 stub 报告 is_stopped=False 时，不得 release 租约、不得删除 payload、不得标 expired / done
    # 当前基线：accepted=True, observed.is_stopped=False, lease=RELEASED, payload_directory_exists=False, last_event.phase=done
    r_reclaim = agent_data.get("reclaim_before_stop_confirmation", {})
    rf02_fail_conditions = (
        r_reclaim.get("observed", {}).get("is_stopped") is False
        and (
            r_reclaim.get("lease") == "RELEASED"
            or r_reclaim.get("payload_directory_exists") is False
            or r_reclaim.get("last_event", {}).get("phase") == "done"
            or r_reclaim.get("local_status") in ("expired", "uninstalled")
        )
    )
    rf_results["RF02"] = {
        "pass": not rf02_fail_conditions and ("reclaim_before_stop_confirmation" in agent_data),
        "detail": f"observed_stopped={r_reclaim.get('observed', {}).get('is_stopped')}, lease={r_reclaim.get('lease')}, payload_exists={r_reclaim.get('payload_directory_exists')}, last_phase={r_reclaim.get('last_event', {}).get('phase')}"
    }

    # RF03: 同一 execution_id 跨重启再次启动
    # 门槛：相同 execution_id 跨进程重启后重投，外部适配器累计启动次数始终为 1 (不能再次调用 execute_skill)
    # 当前基线：second_accepted=True, new_adapter_start_calls=1 (总计 2 次启动)
    r_restart = agent_data.get("same_execution_after_restart", {})
    rf03_pass = (
        r_restart.get("new_adapter_start_calls") == 0
    )
    rf_results["RF03"] = {
        "pass": bool(rf03_pass),
        "detail": f"new_adapter_start_calls={r_restart.get('new_adapter_start_calls')}, second_accepted={r_restart.get('second_accepted')}"
    }

    # RF04: 恢复和对账接入正常入口 (poll_once / main.py)
    # 门槛：poll_once 必须执行 recovery，未完成的 DOWNLOADING 任务状态必须被收敛，不能停在 DOWNLOADING
    # 当前基线：recovery_calls=0, journal_status="DOWNLOADING"
    r_rec = agent_data.get("recovery_not_connected_to_poll", {})
    rf04_pass = (
        r_rec.get("recovery_calls", 0) > 0
        and r_rec.get("journal_status") != "DOWNLOADING"
    )
    rf_results["RF04"] = {
        "pass": bool(rf04_pass),
        "detail": f"recovery_calls={r_rec.get('recovery_calls')}, journal_status={r_rec.get('journal_status')}"
    }

    # RF05: 未签参数、有效期、安装身份及请求幂等
    # 门槛：
    # 1. unsigned_params_fallback: 传外层未签参数时必须被拒绝，或者适配器收到的参数不能包含外层篡改的参数
    # 2. missing_required_signed_fields: 缺少必需字段的 signed 必须被拒绝 (accepted=False)
    # 3. 客户侧：未安装/授权过期不能返回 202 (http_status != 202)，同 nonce 重试必须返回相同 execution_id
    r_params = agent_data.get("unsigned_params_fallback", {})
    adapter_params = r_params.get("adapter_params") or {}
    params_protected = (
        r_params.get("accepted") is False
        or ("speed" not in adapter_params and "sdk_method" not in adapter_params)
    )

    r_sparse = agent_data.get("missing_required_signed_fields", {})
    sparse_rejected = (r_sparse.get("accepted") is False)

    cust_exp = cust_data.get("expired_authorization_and_no_install", {})
    cust_not_installed_rejected = (
        cust_exp.get("http_status") in (403, 404, 409, 422)
    )

    cust_nonce = cust_data.get("nonempty_params_same_nonce", [])
    nonce_deduped = (
        len(cust_nonce) >= 2
        and cust_nonce[0].get("execution_id") is not None
        and cust_nonce[0].get("execution_id") == cust_nonce[1].get("execution_id")
    )

    rf05_pass = params_protected and sparse_rejected and cust_not_installed_rejected and nonce_deduped
    rf_results["RF05"] = {
        "pass": bool(rf05_pass),
        "detail": f"params_protected={params_protected}, sparse_rejected={sparse_rejected}, cust_rejected={cust_not_installed_rejected}, nonce_deduped={nonce_deduped}"
    }

    # RF06: G1 忽略结果、入口越界及运行隔离
    # 门槛：
    # 1. 入口返回 FAILED 时 observe 不能返回 SUCCEEDED (status != 'SUCCEEDED')
    # 2. entrypoint 穿越 (../outside.py) 必须在 prepare 阶段被拒绝 (prepare_accepted=False, outside_entry_executed=False)
    r_g1_fail = agent_data.get("g1_ignores_entrypoint_failure_result", {})
    g1_handles_fail = (r_g1_fail.get("status") != "SUCCEEDED")

    r_escape = agent_data.get("entrypoint_escapes_package", {})
    escape_blocked = (
        r_escape.get("prepare_accepted") is False
        and r_escape.get("outside_entry_executed") is False
    )

    rf06_pass = g1_handles_fail and escape_blocked
    rf_results["RF06"] = {
        "pass": bool(rf06_pass),
        "detail": f"g1_handles_fail={g1_handles_fail} (observed {r_g1_fail.get('status')}), escape_blocked={escape_blocked} (executed={r_escape.get('outside_entry_executed')})"
    }

    # RF07: 回滚恢复目标未验证字节且过期任务仍可回滚
    # 门槛：已过任务期或 restore_sha256 与文件不符时，必须拒绝 (accepted=False)，不得激活篡改目录
    r_rb = agent_data.get("expired_rollback_to_wrong_bytes", {})
    rf07_pass = (
        r_rb.get("accepted") is False
        or r_rb.get("actual_payload") != "tampered-not-good"
    )
    rf_results["RF07"] = {
        "pass": bool(rf07_pass),
        "detail": f"accepted={r_rb.get('accepted')}, payload={r_rb.get('actual_payload')}"
    }

    # RF08: 投递 ACK、事件收据及迟到事实收敛
    # 门槛：commands 响应丢失（设备未持久 ACK）时，第二次轮询必须能重新取到指令 (second_command_count > 0)
    r_lost = http_data.get("delivered_response_lost_no_ack", {})
    rf08_pass = (
        r_lost.get("first_command_count", 0) > 0
        and r_lost.get("second_command_count_without_durable_ack", 0) > 0
    )
    rf_results["RF08"] = {
        "pass": bool(rf08_pass),
        "detail": f"first_count={r_lost.get('first_command_count')}, second_without_ack={r_lost.get('second_command_count_without_durable_ack')}"
    }

    # JCS 门槛: 1e-6 应为 0.000001
    jcs = agent_data.get("jcs_examples", {})
    jcs_1e6 = jcs.get("1e-06", "")
    rf_results["JCS_1e6"] = {
        "pass": jcs_1e6 == '{"x":0.000001}',
        "detail": f"jcs_1e6={jcs_1e6}, expected={{\"x\":0.000001}}"
    }

    return rf_results

if __name__ == "__main__":
    base_dir = pathlib.Path(__file__).resolve().parent
    agent_json = sys.argv[1] if len(sys.argv) > 1 else str(base_dir / "agent-counterexamples.json")
    http_json = sys.argv[2] if len(sys.argv) > 2 else str(base_dir / "http-counterexamples.json")
    cust_json = sys.argv[3] if len(sys.argv) > 3 else str(base_dir / "customer-counterexamples.json")

    results = validate_rf_gates(agent_json, http_json, cust_json)
    print(json.dumps(results, ensure_ascii=False, indent=2))

    all_passed = all(v["pass"] for v in results.values())
    if all_passed:
        print("\n>>> ALL RF GATES PASSED <<<")
        sys.exit(0)
    else:
        failed = [k for k, v in results.items() if not v["pass"]]
        print(f"\n>>> FAILED GATES: {failed} <<<")
        sys.exit(1)
