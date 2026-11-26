"""任务管理器：生命周期状态机、验签、Staging 安装、执行监督与 Outbox 可靠对账"""

import hashlib
import json
import os
import shutil
import tempfile
import time
from typing import Any, Dict, List, Optional

from zhjj_agent.adapters.base import BaseInstallAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.constants import (
    ERR_AUTH_EXPIRED,
    ERR_DOWNLOAD_FAILED,
    ERR_EXECUTION_FAILED,
    ERR_PKG_HASH_MISMATCH,
    ERR_PKG_MISSING,
    ERR_RESTORE_MISMATCH,
    ERR_REVOKED,
    ERR_ROLLBACK_BLOCKED,
    ERR_SIG_INVALID,
    ERR_TASK_EXPIRED,
    EXEC_UNKNOWN,
    OP_CANCEL_EXECUTION,
    OP_DISABLE,
    OP_ENABLE,
    OP_EXECUTE,
    OP_INSTALL,
    OP_RECLAIM,
    OP_ROLLBACK,
    OP_UNINSTALL,
    PHASE_DONE,
    PHASE_DOWNLOAD_FAILED,
    PHASE_DOWNLOADING,
    PHASE_FAILED,
    PHASE_INSTALLING,
    PROTOCOL_V1,
    PROTOCOL_V2,
    STATE_COMMITTED,
    STATE_DOWNLOADING,
    STATE_FAILED,
    STATE_HEALTH_CHECKING,
    STATE_INSTALLING,
    STATE_RECEIVED_DURABLE,
    STATE_REPORTED_ACKED,
    STATE_STAGED,
    STATE_VERIFIED,
)
from zhjj_agent.crypto import canonical_v1, canonical_v2, verify_signature
from zhjj_agent.executor import SkillExecutor
from zhjj_agent.failpoints import hit as failpoint
from zhjj_agent.install_transaction import InstallTransaction
from zhjj_agent.metrics import AgentMetrics
from zhjj_agent.package_manager import PackageManager, PackageManagerError
from zhjj_agent.sandbox import SandboxRunner
from zhjj_agent.storage import Storage
from zhjj_agent.stop_observation import validate_observation
from zhjj_agent.telemetry import log_event


class TaskManager:
    def __init__(
        self,
        install_dir: str,
        storage: Storage,
        client: AgentClient,
        adapter: BaseInstallAdapter,
        pubkey_pem: str,
        metrics: Optional["AgentMetrics"] = None,
        sandbox_runner: Optional[SandboxRunner] = None,
        entitlement_guard: Optional["EntitlementGuard"] = None,
    ):
        self.install_dir = os.path.abspath(install_dir)
        self.storage = storage
        self.client = client
        self.adapter = adapter
        # A3：设备端计数器。不传就自建一个（同一个 agent.db），
        # 这样既不改变既有调用方，也不会出现"两个进程各算一份"的隐性分裂。
        self.metrics = metrics if metrics is not None else AgentMetrics(storage.db_path)
        # T6：沙箱执行器透传给执行监督器；不传时行为与接线前完全一致。
        # A5：授权回源同样透传；只有开关打开时才在 execute() 里生效。
        self.executor = SkillExecutor(
            storage,
            adapter,
            metrics=self.metrics,
            sandbox_runner=sandbox_runner,
            entitlement_guard=entitlement_guard,
        )
        self.pubkey_pem = pubkey_pem.strip()

    # ── 启动自愈与 Outbox 补偿 ───────────────────────────────────────────────

    def mark_agent_start(self) -> Dict[str, int]:
        """进程启动自愈（A4）：计重启次数、清理上次崩溃留下的 staging 残骸。

        为什么必须在这里做：staging 目录是 `tempfile.mkdtemp(prefix=".staging_")` 建的，
        进程被 kill -9 时没有任何机会执行 `finally: shutil.rmtree`。残骸留在技能根目录下
        会被下一次 `os.walk` 扫进"已安装文件"，也会白占磁盘。启动即清是唯一可靠的清理点。
        """
        self.metrics.inc("agent_restart_total", 1)

        cleaned = self._sweep_staging_orphans()
        if cleaned > 0:
            self.metrics.inc("staging_orphan_total", cleaned)

        # A4.1：重启自愈完成后 —— 再崩一次，下一轮启动必须仍然收敛到同一状态。
        failpoint("after_restart")

        return {"staging_orphans_cleaned": cleaned}

    def _sweep_staging_orphans(self) -> int:
        """清理 `skills/*/.staging_*` 残留目录，返回清理数量。

        A4.5：**先归档、再删除**。崩溃残骸是审计证据（哪个包、解到哪一步、文件是什么），
        直接 `rmtree` 等于把现场抹掉。归档失败时**保留现场**（不删），下次启动再试。
        """
        skills_root = os.path.join(self.install_dir, "skills")
        if not os.path.isdir(skills_root):
            return 0

        cleaned = 0
        for skill_entry in os.listdir(skills_root):
            skill_dir = os.path.join(skills_root, skill_entry)

            # A5：卸载是"先 rename 到 .removed_*，再 rmtree"。进程若死在两步之间，
            #     残骸会留在 skills 根目录下（不是某个技能目录里），启动时必须一并清掉。
            if skill_entry.startswith(".removed_"):
                shutil.rmtree(skill_dir, ignore_errors=True)
                if not os.path.exists(skill_dir):
                    cleaned += 1
                continue

            if not os.path.isdir(skill_dir):
                continue
            for name in os.listdir(skill_dir):
                if not name.startswith(".staging_"):
                    continue
                target = os.path.join(skill_dir, name)
                if not os.path.isdir(target):
                    continue

                try:
                    self._archive_staging_orphan(skill_entry, name, target)
                except OSError as archive_error:
                    log_event(
                        "warning",
                        "agent.staging_orphan_archive_failed",
                        task_id="",
                        did=self.client.did,
                        model_code=self.adapter.model_code,
                        error_code="STAGING_ARCHIVE_FAILED",
                        status=f"{target}: {archive_error}",
                    )
                    continue  # 归档失败不删，保留证据

                shutil.rmtree(target, ignore_errors=True)
                if not os.path.exists(target):
                    cleaned += 1

        return cleaned

    def _archive_staging_orphan(self, skill_entry: str, staging_name: str, path: str) -> None:
        """把残骸清单（相对路径 / 大小 / sha256）追加到 `state/audit/staging_orphans.jsonl`。

        单条记录最多 500 个文件，超出标 `truncated` —— 启动路径不能因为某个巨大的残骸
        扫描几分钟；但"截断"必须写在记录里，不能给人一种"全都记下来了"的错觉。
        """
        files = []
        truncated = False
        for root, _, filenames in os.walk(path):
            for filename in sorted(filenames):
                if len(files) >= 500:
                    truncated = True
                    break
                full = os.path.join(root, filename)
                rel = os.path.relpath(full, path).replace(os.sep, "/")
                try:
                    size = os.path.getsize(full)
                    digest = hashlib.sha256()
                    with open(full, "rb") as handle:
                        for chunk in iter(lambda: handle.read(65536), b""):
                            digest.update(chunk)
                    sha = digest.hexdigest()
                except OSError:
                    size = -1
                    sha = None
                files.append({"path": rel, "size": size, "sha256": sha})
            if truncated:
                break

        record = {
            "archived_at": int(time.time()),
            "skill_id": skill_entry,
            "staging_dir": staging_name,
            "files": files,
            "truncated": truncated,
        }

        audit_dir = os.path.join(self.install_dir, "state", "audit")
        os.makedirs(audit_dir, exist_ok=True)
        with open(os.path.join(audit_dir, "staging_orphans.jsonl"), "a", encoding="utf-8") as handle:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")

    def flush_outbox(self):
        """重试未收到平台 ACK 的待发事件，实现幂等回报。"""
        unacked = self.storage.get_unacked_events()
        for ev in unacked:
            event_id = ev["event_id"]
            task_id = ev["task_id"]
            phase = ev["phase"]
            payload = ev["payload"]
            self.storage.mark_event_attempt(event_id)

            ok, resp = self.client.send_event(
                task_no=task_id,
                phase=phase,
                error_code=payload.get("error_code"),
                error_msg=payload.get("error_msg"),
                event_id=event_id,
                event_seq=ev.get("event_seq"),
                extra=payload,
            )
            # 只有取得平台确认才标记已确认，严禁因 TASK_TRANSITION_INVALID / TASK_NOT_FOUND 无条件丢弃待发事件
            if ok:
                # A4.1：补发成功、ACK 未落库 —— 崩溃后重启必须重投同一条事件（同 event_id）。
                failpoint("after_send_before_ack")
                self.storage.mark_event_acked(event_id, resp)
            else:
                # A3：补发失败要计数 —— 这是"设备在，但事实送不上去"的唯一信号，
                # 平台侧看不到未确认的 outbox，只能靠设备自己说。
                self.metrics.inc("outbox_flush_failed_total")

    def resume_recovery(self):
        """断网/重启后的崩溃恢复与事实对账。按操作类型与检查点精细化对账，严禁将旧版错报为新安装完成。"""
        self.flush_outbox()

        uncompleted = self.storage.get_uncompleted_tasks()
        for task in uncompleted:
            task_id = task["task_id"]
            op = task["operation"]
            skill_id = task["skill_id"]
            target_version = task.get("version")
            status = task.get("status")

            # 独立观测设备侧实际状态
            obs = self.adapter.observe(skill_id)

            if op == OP_INSTALL:
                # 只有当此前确实进入过 staging/installing 阶段，且适配器实际版本与目标一致时才视为安装完成
                if status in (STATE_STAGED, STATE_INSTALLING) and obs.get("installed") and obs.get("version") == target_version:
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(task_id, PHASE_DONE)
                else:
                    self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "进程重启后未能确认外部安装状态")
                    self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "进程重启后未能确认外部安装状态")

            elif op == OP_ENABLE:
                if obs.get("installed") and obs.get("enabled"):
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(task_id, PHASE_DONE)
                else:
                    self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "未能确认启用状态")
                    self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "未能确认启用状态")

            elif op == OP_DISABLE:
                if not obs.get("enabled") or obs.get("status") == "disabled":
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(task_id, PHASE_DONE)
                else:
                    self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "未能确认停用状态")
                    self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "未能确认停用状态")

            elif op in (OP_UNINSTALL, OP_RECLAIM):
                if not obs.get("installed") or obs.get("status") in ("uninstalled", "not_installed"):
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(task_id, PHASE_DONE)
                else:
                    self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "未能确认卸载状态")
                    self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "未能确认卸载状态")

            elif op == OP_EXECUTE:
                # A4.4：execution_id 来自**签名指令**（或本地执行表），不是 f"exec_{task_id}"。
                #   用错 id 会让 update 落空，真正的执行永远停在 ACCEPTED —— 等于没恢复。
                exec_id = (task.get("signed_payload") or {}).get("execution_id") or task.get("execution_id")
                if not exec_id:
                    rows = self.storage.get_executions_for_task(task_id)
                    exec_id = rows[0]["execution_id"] if rows else f"exec_{task_id}"
                exec_obs = self.executor.observe(exec_id)
                existing = self.storage.get_execution(exec_id) or {}
                started = bool(existing.get("started_at"))
                if exec_obs.get("is_stopped") and exec_obs.get("status") == "SUCCEEDED":
                    self.storage.update_execution_status(exec_id, "SUCCEEDED")
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(
                        task_id, PHASE_DONE,
                        execution_fact=self._execution_fact(exec_id, "SUCCEEDED", started, True),
                    )
                elif exec_obs.get("is_stopped"):
                    if exec_obs.get("status") == "CANCELED":
                        self.storage.update_execution_status(exec_id, "CANCELED")
                        self.storage.update_task_status(task_id, STATE_COMMITTED)
                        self.emit_event(
                            task_id, "canceled",
                            execution_fact=self._execution_fact(exec_id, "CANCELED", started, True),
                        )
                    else:
                        self.storage.update_execution_status(exec_id, "FAILED", "EXECUTION_FAILED", exec_obs.get("error_msg"))
                        self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_FAILED", exec_obs.get("error_msg"))
                        self.emit_event(
                            task_id, PHASE_FAILED, "EXECUTION_FAILED", exec_obs.get("error_msg"),
                            execution_fact=self._execution_fact(exec_id, "FAILED", started, True, "EXECUTION_FAILED"),
                        )
                else:
                    # 无法独立确认停止：平台执行事实必须是 UNKNOWN，资源租约继续锁定。
                    self.storage.update_execution_status(exec_id, "UNKNOWN", "RECOVERY_UNKNOWN", "执行状态未知")
                    self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_UNKNOWN", "执行状态未知")
                    self.emit_event(
                        task_id, "unknown", "RECOVERY_UNKNOWN", "执行状态未知",
                        execution_fact=self._execution_fact(exec_id, "UNKNOWN", started, False, "RECOVERY_UNKNOWN"),
                    )

            elif op == OP_CANCEL_EXECUTION:
                exec_id = task.get("execution_id") or f"exec_{task_id}"
                exec_obs = self.executor.observe(exec_id)
                existing = self.storage.get_execution(exec_id) or {}
                started = bool(existing.get("started_at"))
                if exec_obs.get("is_stopped"):
                    self.storage.update_execution_status(exec_id, "CANCELED")
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(
                        task_id, "canceled",
                        execution_fact=self._execution_fact(exec_id, "CANCELED", started, True),
                    )
                else:
                    self.storage.update_execution_status(exec_id, "UNKNOWN", "STOP_UNCONFIRMED", "取消后未能观察安全停止")
                    self.storage.update_task_status(task_id, STATE_FAILED, "STOP_UNCONFIRMED", "取消后未能观察安全停止")
                    self.emit_event(
                        task_id, "unknown", "STOP_UNCONFIRMED", "取消后未能观察安全停止",
                        execution_fact=self._execution_fact(exec_id, "UNKNOWN", started, False, "STOP_UNCONFIRMED"),
                    )
            else:
                self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "未识别的在途任务操作")
                self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "未识别的在途任务操作")

        self._sync_reconcile()

    def _sync_reconcile(self):
        """把设备的安装、在途任务和执行快照按代次上报；相同事实不重复请求。"""
        installed = []
        for skill_id, item in self.storage.get_all_installed_skills().items():
            manifest = item.get("manifest") or {}
            raw_version_id = manifest.get("skill_version_id") or item.get("skill_version_id")
            try:
                version_id = int(raw_version_id or skill_id)
            except (TypeError, ValueError):
                continue
            installed.append({
                "skill_version_id": version_id,
                "version": item.get("current_version"),
                "status": "enabled" if item.get("enabled") else (item.get("status") or "installed"),
                "package_sha256": item.get("package_sha256"),
            })

        tasks = [
            {"task_no": task["task_id"], "status": task["status"]}
            for task in self.storage.get_uncompleted_tasks()
        ]
        active_executions = [
            {"execution_id": row["execution_id"], "status": row["status"]}
            for row in self.storage.get_all_executions()
            if row.get("status") in ("ACCEPTED", "RUNNING", "CANCEL_REQUESTED", "UNKNOWN")
        ]
        generation = self.storage.get_max_command_seq(self.client.did)
        content = {
            "skills": installed,
            "tasks": tasks,
            "active_executions": active_executions,
            "generation": generation,
        }
        digest = hashlib.sha256(
            json.dumps(content, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        ).hexdigest()
        if digest == getattr(self, "_last_reconcile_digest", None):
            return
        try:
            ok, response = self.client.reconcile(
                skills=installed,
                tasks=tasks,
                model_code=self.adapter.model_code,
                observed_generation=generation,
                active_executions=active_executions,
                outbox_cursor=None,
                reported_at=int(time.time()),
            )
        except Exception as exc:
            log_event("error", "agent.reconcile_failed", did=self.client.did, model_code=self.adapter.model_code, error_code="RECONCILE_EXCEPTION", status=str(exc))
            return
        if ok:
            self._last_reconcile_digest = digest
        else:
            log_event("error", "agent.reconcile_failed", did=self.client.did, model_code=self.adapter.model_code, error_code=response.get("code"), status="REJECTED")

    # ── 事件发射封装 ─────────────────────────────────────────────────────────

    def emit_event(
        self,
        task_id: str,
        phase: str,
        error_code: Optional[str] = None,
        error_msg: Optional[str] = None,
        execution_fact: Optional[Dict[str, Any]] = None,
    ):
        # event_id 生成一次、先持久化、后续网络重试始终复用它。不能用“当前时间”在
        # flush_outbox 中重新造号，否则 ACK 丢失后的重投会变成第二条平台事实。
        seed = f"{task_id}|{phase}|{time.time_ns()}|{execution_fact or ''}"
        event_id = "evt_" + hashlib.sha256(seed.encode("utf-8")).hexdigest()[:32]

        # A3：指令签名/信封被拒的次数。放在这里是因为**所有**验签拒绝最终都会走到
        # emit_event(PHASE_FAILED, ERR_SIG_INVALID, ...) —— 这一个点是唯一不会漏的。
        if error_code == ERR_SIG_INVALID:
            self.metrics.inc("command_signature_failed_total")

        payload: Dict[str, Any] = {
            "task_no": task_id,
            "did": self.client.did,
            "phase": phase,
            "error_code": error_code,
            "error_msg": error_msg,
            "event_id": event_id,
            "reported_at": int(time.time()),
        }
        if execution_fact:
            payload.update(execution_fact)

        # WBS A1：把正文信封一起持久化进 outbox；重投复用同一签名，
        # 不会因为“当前时间”重新造号/重签变成第二条平台事实。
        build_envelope = getattr(self.client, "build_fact_envelope", None)
        if callable(build_envelope):
            envelope = build_envelope(payload, execution_fact)
            if envelope:
                payload["fact_envelope"] = envelope

        log_event(
            "error" if error_code else "info",
            "agent.execution_fact",
            task_id=task_id,
            did=self.client.did,
            model_code=self.adapter.model_code,
            operation=None,
            status=phase,
            error_code=error_code,
            execution_id=payload.get("execution_id"),
        )

        # A4.1：事件落 outbox 之前 —— 此时崩溃 = 事实彻底丢失（最危险的一类）。
        failpoint("before_outbox")
        self.storage.record_event_outbox(event_id, task_id, phase, payload)
        ok, resp = self.client.send_event(
            task_no=task_id,
            phase=phase,
            error_code=error_code,
            error_msg=error_msg,
            event_id=event_id,
            extra=payload,
        )
        if ok:
            # A4.1：发送成功、ACK 落库前 —— 崩溃必须让事件保持"未确认"，重启后重投。
            failpoint("after_send_before_ack")
            self.storage.mark_event_acked(event_id, resp)

    def _execution_fact(
        self,
        execution_id: str,
        observed_status: str,
        actual_started: bool,
        actual_stopped: bool,
        error_code: Optional[str] = None,
        observation: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        execution = self.storage.get_execution(execution_id) or {}
        now_ts = int(time.time())

        if actual_stopped and observation is None:
            observation = self._stop_observation(execution_id)

        # 没有可验证的独立观察 → 绝不能声称已停；终态一律降级 UNKNOWN。
        if actual_stopped and observation is None:
            actual_stopped = False
            if observed_status in ("SUCCEEDED", "FAILED", "CANCELED"):
                observed_status = EXEC_UNKNOWN
            error_code = error_code or "STOP_OBSERVATION_MISSING"

        started_at = execution.get("started_at")
        if started_at is None and actual_started:
            started_at = now_ts

        # stopped_at 只能来自观察器的 observed_at，禁止用 now() 伪造。
        stopped_at = int(observation["observed_at"]) if (actual_stopped and observation) else None

        evidence = {
            "observer": "device_adapter_observe",
            "model_code": self.adapter.model_code,
            "observed_status": observed_status,
            "hardware_verified": bool(getattr(self.adapter, "is_hardware_verified", False)),
            "observed_at": int(observation["observed_at"]) if observation else now_ts,
        }

        if observation:
            evidence["stop_observation"] = observation

        return {
            "execution_id": execution_id,
            "observed_status": observed_status,
            "actual_started": bool(actual_started),
            "actual_stopped": bool(actual_stopped),
            "started_at": int(started_at) if started_at else None,
            "stopped_at": stopped_at,
            "error_code": error_code,
            # 平台 A2 契约读顶层 stop_observation；同时保留在 evidence 里供审计。
            "stop_observation": observation,
            "evidence": evidence,
            "reported_at": now_ts,
        }

    def _stop_observation(self, execution_id: str) -> Optional[Dict[str, Any]]:
        """从适配器取结构化停止观察；不可验证返回 None。"""
        try:
            obs = self.adapter.observe_execution(execution_id)
        except Exception:
            return None

        if not isinstance(obs, dict) or obs.get("is_stopped") is not True:
            return None

        return validate_observation(obs.get("observation"))
    # ── 指令处理核心流水线 ───────────────────────────────────────────────────

    def process_command(self, cmd: Dict[str, Any]) -> bool:
        raw_op = cmd.get("operation")
        protocol = cmd.get("protocol_version") or cmd.get("signed", {}).get("protocol_version")
        log_event(
            "info",
            "agent.command_received",
            task_id=cmd.get("task_id"),
            did=self.client.did,
            model_code=self.adapter.model_code,
            operation=raw_op,
            status="RECEIVED",
        )

        # A3.2：收到指令即计数（无论后续验签/过期/机型是否通过）。
        # 这是"设备到底有没有收到平台下发"的唯一设备侧证据。
        self.metrics.inc("command_received_total")

        # 拒绝未知协议或缺少操作码（严禁将缺操作码默认成安装）
        if raw_op is None and (protocol != PROTOCOL_V2 or "operation" not in cmd.get("signed", {})):
            self.emit_event(cmd.get("task_id", "unknown"), PHASE_FAILED, "MISSING_OPERATION", "指令缺少必需的操作码")
            return False

        # ── 1. 验签与防重放 ───────────────────────────────────────────────────
        if protocol == PROTOCOL_V2 and "signed" in cmd:
            signed = cmd["signed"]
            if not isinstance(signed, dict):
                self.emit_event(cmd.get("task_id", "unknown"), PHASE_FAILED, ERR_SIG_INVALID, "v2 signed 格式非法")
                return False

            task_id = signed.get("task_id")
            operation = signed.get("operation")
            skill_id = str(signed.get("skill_id") or "")
            version = str(signed.get("version") or "1.0.0")
            command_seq = int(signed.get("command_seq") or 1)

            # 严格必需字段检查（F05）
            required_v2_fields = [
                "protocol_version", "task_id", "command_seq", "did",
                "operation", "task_expires_at"
            ]
            missing_fields = [f for f in required_v2_fields if f not in signed or signed[f] is None]
            if missing_fields:
                self.emit_event(task_id or "unknown", PHASE_FAILED, ERR_SIG_INVALID, f"v2 signed 缺少必需字段: {', '.join(missing_fields)}")
                return False

            operation = int(operation)

            # 外层与内层重复字段强制一致性校验（防外层篡改或内层绕过）
            if cmd.get("operation") is not None and int(cmd["operation"]) != operation:
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令外层 operation 与签名内层不一致")
                return False
            if cmd.get("task_id") and cmd["task_id"] != task_id:
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令外层 task_id 与签名内层不一致")
                return False
            if cmd.get("did") and cmd["did"] != signed.get("did"):
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令外层 did 与签名内层不一致")
                return False
            if cmd.get("skill_id") and str(cmd["skill_id"]) != skill_id:
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令外层 skill_id 与签名内层不一致")
                return False
            if cmd.get("version") and str(cmd["version"]) != version:
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令外层 version 与签名内层不一致")
                return False
            if cmd.get("package_sha256") and cmd["package_sha256"].lower() != (signed.get("package_sha256") or "").lower():
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令外层 package_sha256 与签名内层不一致")
                return False

            # 外层执行参数与内层一致性防篡改检查（F05）
            if "execution_params" in cmd and cmd["execution_params"] != signed.get("execution_params", {}):
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令外层 execution_params 与签名内层不一致")
                return False

            # 核对 signed.did 与当前设备 Client 真实身份
            signed_did = signed.get("did")
            if signed_did != self.client.did:
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, f"签名目标设备 ({signed_did}) 与当前设备 ({self.client.did}) 不匹配")
                return False

            # RSA-SHA256 签名检验
            sig_b64 = cmd.get("signature") or cmd.get("signature_v2")
            if not verify_signature(canonical_v2(signed), sig_b64, self.pubkey_pem):
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "v2 签名校验失败")
                return False

            # 新 envelope v2：完整绑定设备、机型、Profile、时间和 nonce。
            if signed.get("protocol") == PROTOCOL_V2:
                strict_required = [
                    "key_id", "model_code", "target_profile_digest", "nonce",
                    "issued_at", "expires_at",
                ]
                missing_strict = [f for f in strict_required if signed.get(f) in (None, "")]
                if missing_strict:
                    self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, f"v2 envelope 缺少字段: {', '.join(missing_strict)}")
                    return False

                expected_key_id = hashlib.sha256(self.pubkey_pem.encode("utf-8")).hexdigest()
                if str(signed.get("key_id")) != expected_key_id:
                    self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "v2 envelope key_id 与本地信任根不匹配")
                    return False

                if str(signed.get("model_code")) != self.adapter.model_code:
                    self.emit_event(task_id, PHASE_FAILED, "MODEL_MISMATCH", "命令机型与 Agent Adapter 不一致")
                    return False

                profile_digest = str(signed.get("target_profile_digest") or "")
                if not profile_digest.startswith("sha256:"):
                    self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "v2 envelope target_profile_digest 非法")
                    return False

                now_ts = int(time.time())
                issued_at = int(signed.get("issued_at") or 0)
                expires_at = int(signed.get("expires_at") or 0)
                if issued_at > now_ts + 300 or now_ts - issued_at > 900:
                    self.emit_event(task_id, PHASE_FAILED, ERR_TASK_EXPIRED, "v2 envelope issued_at 超出允许时钟窗口")
                    return False
                if expires_at <= now_ts:
                    self.emit_event(task_id, PHASE_FAILED, ERR_TASK_EXPIRED, "v2 envelope 已过期")
                    return False

                nonce_state = self.storage.claim_command_nonce(str(signed.get("nonce")), str(task_id))
                if nonce_state == "replay":
                    self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "nonce 重放：同一 nonce 已绑定其他任务")
                    return False

            # 任务期限检查（覆盖安装、启用、执行及回滚 F07）
            task_expires_at = signed.get("task_expires_at")
            if task_expires_at and int(time.time()) > int(task_expires_at):
                if operation in (OP_INSTALL, OP_ENABLE, OP_EXECUTE, OP_ROLLBACK):
                    self.emit_event(task_id, PHASE_FAILED, ERR_TASK_EXPIRED, "指令已过任务有效期")
                    return False

            # 授权窗口检查 (authorization_expires_at / authorization_not_before)
            auth_expires_at = signed.get("authorization_expires_at")
            if auth_expires_at and int(time.time()) > int(auth_expires_at):
                if operation in (OP_INSTALL, OP_ENABLE, OP_EXECUTE):
                    self.emit_event(task_id, PHASE_FAILED, ERR_REVOKED, "技能使用授权已过期")
                    return False

            auth_not_before = signed.get("authorization_not_before")
            if auth_not_before and int(time.time()) < int(auth_not_before):
                if operation in (OP_INSTALL, OP_ENABLE, OP_EXECUTE):
                    self.emit_event(task_id, PHASE_FAILED, ERR_REVOKED, "技能使用授权尚未生效")
                    return False

            # 撤销代次检查
            revocation_epoch = int(signed.get("revocation_epoch") or 0)
            known_epoch = self.storage.get_revocation_epoch(f"skill:{skill_id}")
            if revocation_epoch < known_epoch and operation in (OP_INSTALL, OP_ENABLE, OP_EXECUTE):
                self.emit_event(task_id, PHASE_FAILED, ERR_REVOKED, "该技能已被更高代次撤销，拒绝启用或安装")
                return False

        elif protocol == PROTOCOL_V1 or (protocol is None and "signature_v1" in cmd):
            task_id = cmd.get("task_id")
            operation = int(cmd.get("operation") or 0)
            skill_id = str(cmd.get("skill_id") or "")
            version = str(cmd.get("version") or "1.0.0")
            command_seq = int(cmd.get("command_seq") or 1)

            # v1 协议绝不接收 OP 7/8
            if operation in (OP_EXECUTE, OP_CANCEL_EXECUTION):
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "旧版协议禁止执行技能动作")
                return False

            sig_b64 = cmd.get("signature") or cmd.get("signature_v1")
            if not verify_signature(canonical_v1(cmd), sig_b64, self.pubkey_pem):
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "v1 签名校验未通过")
                return False
        else:
            self.emit_event(cmd.get("task_id", "unknown"), PHASE_FAILED, ERR_SIG_INVALID, f"未知协议版本: {protocol}")
            return False

        # 若是停用、卸载或回收处置类操作：原子持久冻结准入、提升撤销代次，并取消在途执行
        if operation in (OP_DISABLE, OP_UNINSTALL, OP_RECLAIM):
            in_epoch = cmd.get("signed", {}).get("revocation_epoch") if protocol == PROTOCOL_V2 else cmd.get("revocation_epoch")
            if in_epoch is not None and int(in_epoch) > 0:
                self.storage.set_revocation_epoch(f"skill:{skill_id}", int(in_epoch))

            # 取消活动执行
            active_leases = self.storage.get_active_leases_for_skill(skill_id)
            for l in active_leases:
                self.executor.cancel(l["execution_id"])

        # ── 2. 先持久接收落 Journal ──────────────────────────────────────────
        # A4.1：写意图前是可注入的崩溃点 —— 此时磁盘上不该有任何任务痕迹。
        failpoint("before_intent")
        is_new = self.storage.record_task_durable(
            task_id=task_id,
            command_seq=command_seq,
            did=self.client.did,
            operation=operation,
            skill_id=skill_id,
            skill_version_id=str(cmd.get("skill_version_id") or ""),
            version=version,
            signed_payload=cmd.get("signed"),
            raw_command=cmd,
            initial_status=STATE_RECEIVED_DURABLE
        )
        if not is_new:
            # 重复任务到达：已持久记录，直接幂等返回，不重复安装或动作
            # A3.2：重复投递被挡住 = 平台重试/乱序的真实信号，必须计数。
            self.metrics.inc("command_duplicate_total")
            existing_task = self.storage.get_task(task_id)
            if existing_task and existing_task.get("status") == STATE_COMMITTED:
                self.emit_event(task_id, PHASE_DONE)
            return True

        # 发送持久接收 ACK
        self.client.send_ack(task_id, f"ack_recv_{task_id}", STATE_RECEIVED_DURABLE)

        # ── 3. 按操作分支执行（全部读取可信 signed 字段） ───────────────────────
        if operation == OP_INSTALL:
            return self._handle_install(task_id, skill_id, version, cmd)
        elif operation == OP_ROLLBACK:
            return self._handle_rollback(task_id, skill_id, version, cmd)
        elif operation in (OP_ENABLE, OP_DISABLE):
            return self._handle_toggle(task_id, skill_id, version, operation)
        elif operation in (OP_UNINSTALL, OP_RECLAIM):
            return self._handle_uninstall_or_reclaim(task_id, skill_id, operation)
        elif operation == OP_EXECUTE:
            return self._handle_execute(task_id, skill_id, cmd)
        elif operation == OP_CANCEL_EXECUTION:
            return self._handle_cancel_execution(task_id, cmd)
        else:
            self.emit_event(task_id, PHASE_FAILED, "UNKNOWN_OPERATION", f"未知操作码: {operation}")
            return False

    def _handle_install(self, task_id: str, skill_id: str, version: str, cmd: Dict[str, Any]) -> bool:
        signed = cmd.get("signed") or {}
        # 严格使用已签名的 package_sha256
        expected_sha = (signed.get("package_sha256") or cmd.get("package_sha256") or "").lower()
        package_url = cmd.get("package_url")

        # 检查是否为 Dry Install（无包安装）
        if not package_url and not expected_sha:
            self.emit_event(task_id, PHASE_DONE, ERR_PKG_MISSING, "无包任务 (dry install)")
            self.storage.update_task_status(task_id, STATE_COMMITTED)
            return True

        if not package_url:
            self.emit_event(task_id, PHASE_DOWNLOAD_FAILED, ERR_PKG_MISSING, "任务声明有包但 package_url 为空")
            self.storage.update_task_status(task_id, STATE_FAILED, ERR_PKG_MISSING, "缺少下载地址")
            return False

        # 阶段 A: 下载
        self.storage.update_task_status(task_id, STATE_DOWNLOADING)
        self.emit_event(task_id, PHASE_DOWNLOADING)

        transaction = InstallTransaction(self.install_dir)
        pkg_mgr = PackageManager(self.install_dir)
        download_dest = os.path.join(self.install_dir, "downloads", f"{skill_id}_{version}.skill")

        staging_dir: Optional[str] = None
        committed_dir: Optional[str] = None
        backup_dir: Optional[str] = None

        try:
            # F01: 传递受控设备凭据下载请求头
            download_headers = self.client.get_download_headers(package_url)

            try:
                got_sha = pkg_mgr.download_and_verify(package_url, download_dest, expected_sha, headers=download_headers)
            except PackageManagerError as e:
                if e.code == ERR_PKG_HASH_MISMATCH:
                    # A3：包哈希不符 = 传输损坏或被人替换，是"供应链"层面唯一的设备侧信号
                    self.metrics.inc("package_hash_mismatch_total")
                self.storage.update_task_status(task_id, STATE_FAILED, e.code, e.message)
                self.emit_event(task_id, PHASE_DOWNLOAD_FAILED, e.code, e.message)
                return False

            failpoint("after_download")

            # 阶段 B: 解压与 Staging（**在技能根目录内**建 staging：
            #   与最终目录同一文件系统，rename 才是原子的；也给 A4 的启动清理留了固定扫描位置）
            try:
                transaction.skill_root(skill_id, create=True)
                staging_dir = transaction.new_staging(skill_id)
                pkg_mgr.unpack_to_staging(download_dest, staging_dir)
                manifest = pkg_mgr.read_and_validate_manifest(staging_dir)
            except PackageManagerError as e:
                self.storage.update_task_status(task_id, STATE_FAILED, e.code, e.message)
                self.emit_event(task_id, PHASE_FAILED, e.code, e.message)
                return False

            self.storage.update_task_status(task_id, STATE_STAGED)
            failpoint("after_unpack")

            # 阶段 C: 适配器 prepare 与环境检查
            if not self.adapter.prepare(skill_id, version, staging_dir, manifest):
                self.storage.update_task_status(task_id, STATE_FAILED, "PREPARE_REJECTED", "适配器环境或机型预检拒绝")
                self.emit_event(task_id, PHASE_FAILED, "PREPARE_REJECTED", "适配器环境或机型预检拒绝")
                return False

            failpoint("after_verify")

            # 阶段 D: 事务提交（两阶段：先把 staging 换成版本目录，旧版本留作备份）
            self.storage.update_task_status(task_id, STATE_INSTALLING)
            self.emit_event(task_id, PHASE_INSTALLING)

            try:
                with transaction.lock(skill_id):
                    committed_dir, backup_dir = transaction.begin_commit(
                        staging_dir, skill_id, version, meta={"package_sha256": got_sha}
                    )
                staging_dir = None  # 已被 rename 消耗
            except PackageManagerError as e:
                self.storage.update_task_status(task_id, STATE_FAILED, e.code, e.message)
                self.emit_event(task_id, PHASE_FAILED, e.code, e.message)
                return False

            # 调用底层适配器正式安装
            if not self.adapter.install(skill_id, version, committed_dir, manifest):
                self._abort_install(transaction, task_id, skill_id, committed_dir, backup_dir)
                self.storage.update_task_status(task_id, STATE_FAILED, "ADAPTER_INSTALL_FAILED", "底层适配器安装失败")
                self.emit_event(task_id, PHASE_FAILED, "ADAPTER_INSTALL_FAILED", "底层适配器安装失败")
                return False

            # 阶段 E: 健康探针核验
            self.storage.update_task_status(task_id, STATE_HEALTH_CHECKING)
            obs = self.adapter.observe(skill_id)
            if not obs.get("installed"):
                self._abort_install(transaction, task_id, skill_id, committed_dir, backup_dir)
                self.storage.update_task_status(task_id, STATE_FAILED, "OBSERVE_FAILED", "安装后健康观测未通过")
                self.emit_event(task_id, PHASE_FAILED, "OBSERVE_FAILED", "安装后健康观测未通过")
                return False

            # 阶段 F: **原子激活**（最后一步，且只写激活指针）
            #   指针之前的一切都可能失败并被回退；指针一旦落下，设备就"生效"了。
            meta = transaction.read_install_meta(committed_dir) or {}
            with transaction.lock(skill_id):
                transaction.activate(
                    skill_id,
                    version,
                    package_sha256=got_sha,
                    tree_sha256=meta.get("tree_sha256"),
                )
                transaction.finish_commit(backup_dir)
            backup_dir = None

            # A4.1：激活之后、安装事实落库之前 —— 典型的"文件已生效、记录还没写"窗口。
            failpoint("after_activate")

            # 更新本地安装事实表与 state/installed.json
            prev = self.storage.get_installed_skill(skill_id)
            prev_ver = prev["current_version"] if prev and prev["current_version"] != version else None
            status_str = "updated" if prev_ver else "installed"

            files = meta.get("files") or []
            if not files:
                files = [
                    os.path.relpath(os.path.join(root, fn), committed_dir)
                    for root, _, filenames in os.walk(committed_dir)
                    for fn in filenames
                    if fn not in (".install-meta.json", ".active.json")
                ]

            self.storage.save_installed_skill(
                skill_id=skill_id,
                current_version=version,
                previous_version=prev_ver,
                status=status_str,
                enabled=True,
                install_path=committed_dir,
                package_sha256=got_sha,
                manifest=manifest,
                files=files
            )
            self.storage.update_task_status(task_id, STATE_COMMITTED)

            # 上报完成
            self.emit_event(task_id, PHASE_DONE)
            return True
        finally:
            for cleanup_path in (download_dest, staging_dir):
                if not cleanup_path:
                    continue
                try:
                    if os.path.isdir(cleanup_path):
                        shutil.rmtree(cleanup_path, ignore_errors=False)
                    elif os.path.exists(cleanup_path):
                        os.remove(cleanup_path)
                except OSError as cleanup_error:
                    log_event(
                        "warning",
                        "agent.staging_cleanup_failed",
                        task_id=task_id,
                        did=self.client.did,
                        model_code=self.adapter.model_code,
                        error_code="STAGING_CLEANUP_FAILED",
                        status=str(cleanup_error),
                    )

    def _abort_install(self, transaction: InstallTransaction, task_id: str, skill_id: str, committed_dir: str, backup_dir: Optional[str]) -> None:
        """安装失败回退：删半成品、把旧版本目录放回去（A5.5 首装失败不留残骸）。"""
        try:
            with transaction.lock(skill_id):
                transaction.abort_commit(committed_dir, backup_dir)
        except PackageManagerError as error:
            log_event(
                "error",
                "agent.install_abort_failed",
                task_id=task_id,
                did=self.client.did,
                model_code=self.adapter.model_code,
                error_code=error.code,
                status=error.message,
            )

    def _handle_rollback(self, task_id: str, skill_id: str, target_version: str, cmd: Dict[str, Any]) -> bool:
        """显式回滚：严格核验恢复目标内容哈希与过期期限（F07）。首装无旧版时安全停用，不报假恢复。"""
        self.emit_event(task_id, PHASE_INSTALLING)

        current = self.storage.get_installed_skill(skill_id)
        signed = cmd.get("signed") or {}

        # 优先读取签名内层指定的良好恢复目标版本
        restore_ver = signed.get("restore_version")
        if not restore_ver:
            # 若 target_version 与当前版本不同，说明 target_version 为恢复目标
            if target_version and current and target_version != current.get("current_version"):
                restore_ver = target_version
            else:
                # 否则取历史良好旧版本 previous_version
                restore_ver = current.get("previous_version") if current else None

        if not restore_ver:
            # 首装无良好旧版：按规则安全停用
            self.adapter.disable(skill_id)
            if current:
                self.storage.save_installed_skill(
                    skill_id=skill_id,
                    current_version=current["current_version"],
                    previous_version=None,
                    status="disabled",
                    enabled=False,
                    install_path=current.get("install_path", "")
                )
            self.storage.update_task_status(task_id, STATE_COMMITTED)
            self.emit_event(task_id, PHASE_DONE, "NO_PREVIOUS_VERSION", "首装无良好旧版，已按规则安全停用")
            return True

        # ── A5.3：活动执行未确认停止 ⇒ 拒绝回滚 ────────────────────────────────
        #   回滚会把"当前生效版本"换掉；若此时还有执行在跑，等于在动作执行途中抽掉它的代码。
        #   先请求取消，并**独立观测**是否真的停了；观测不到停止就失败退出，绝不"先回滚再说"。
        for lease in self.storage.get_active_leases_for_skill(skill_id):
            eid = lease["execution_id"]
            self.adapter.cancel_execution(eid)
            obs = self.adapter.observe_execution(eid)
            if not obs.get("is_stopped"):
                message = f"执行 {eid} 未能确认安全停止，拒绝回滚"
                self.storage.update_task_status(task_id, STATE_FAILED, ERR_ROLLBACK_BLOCKED, message)
                self.emit_event(task_id, PHASE_FAILED, ERR_ROLLBACK_BLOCKED, message)
                return False
            self.storage.release_execution_lease(eid)

        # ── A5.4：恢复目标必须匹配 entitlement ─────────────────────────────────
        #   授权过期/已被撤销的技能不允许通过"回滚"重新变成可执行状态。
        auth_expires_at = signed.get("authorization_expires_at")
        if auth_expires_at and int(time.time()) > int(auth_expires_at):
            message = "技能授权已过期，拒绝回滚到可执行状态"
            self.storage.update_task_status(task_id, STATE_FAILED, ERR_REVOKED, message)
            self.emit_event(task_id, PHASE_FAILED, ERR_REVOKED, message)
            return False

        revocation_epoch = int(signed.get("revocation_epoch") or 0)
        known_epoch = self.storage.get_revocation_epoch(f"skill:{skill_id}")
        if revocation_epoch and revocation_epoch < known_epoch:
            message = "该技能已被更高代次撤销，拒绝回滚到可执行状态"
            self.storage.update_task_status(task_id, STATE_FAILED, ERR_REVOKED, message)
            self.emit_event(task_id, PHASE_FAILED, ERR_REVOKED, message)
            return False

        transaction = InstallTransaction(self.install_dir)
        restore_sha256 = (signed.get("restore_sha256") or "").lower() or None

        try:
            with transaction.lock(skill_id):
                restore_dir = transaction.version_dir(skill_id, restore_ver)
                if not os.path.isdir(restore_dir) or os.path.islink(restore_dir):
                    message = f"回滚目标版本 {restore_ver} 本地文件不存在"
                    self.storage.update_task_status(task_id, STATE_FAILED, "RESTORE_TARGET_MISSING", message)
                    self.emit_event(task_id, PHASE_FAILED, "RESTORE_TARGET_MISSING", message)
                    return False

                meta = transaction.read_install_meta(restore_dir) or {}

                # ① 版本匹配：restore_sha256 比对的是**恢复目标版本**记录的包哈希，
                #    不是"当前已安装版本"的记录 —— 后者恰好会让回滚通过一个错误的证明。
                if restore_sha256:
                    recorded_sha = (meta.get("package_sha256") or "").lower()
                    if recorded_sha:
                        if recorded_sha != restore_sha256:
                            message = f"回滚目标版本 {restore_ver} 记录的包哈希与签名 restore_sha256 不符"
                            self.storage.update_task_status(task_id, STATE_FAILED, ERR_RESTORE_MISMATCH, message)
                            self.emit_event(task_id, PHASE_FAILED, ERR_RESTORE_MISMATCH, message)
                            return False
                    else:
                        # 旧安装没有版本元数据：只能在存在裸载体文件时直接对磁盘字节核验，
                        # 否则无法证明"磁盘内容 = 签名哈希"，按 fail-closed 拒绝。
                        payload = next(
                            (p for p in (os.path.join(restore_dir, "payload"), os.path.join(restore_dir, "package.skill")) if os.path.isfile(p)),
                            None,
                        )
                        if payload is None:
                            message = f"回滚目标版本 {restore_ver} 缺少版本元数据，无法核对 restore_sha256"
                            self.storage.update_task_status(task_id, STATE_FAILED, ERR_RESTORE_MISMATCH, message)
                            self.emit_event(task_id, PHASE_FAILED, ERR_RESTORE_MISMATCH, message)
                            return False
                        digest = hashlib.sha256()
                        with open(payload, "rb") as handle:
                            for chunk in iter(lambda: handle.read(65536), b""):
                                digest.update(chunk)
                        if digest.hexdigest().lower() != restore_sha256:
                            message = "回滚恢复目标内容哈希与签名 restore_sha256 不符"
                            self.storage.update_task_status(task_id, STATE_FAILED, ERR_RESTORE_MISMATCH, message)
                            self.emit_event(task_id, PHASE_FAILED, ERR_RESTORE_MISMATCH, message)
                            return False

                # ② 磁盘内容匹配：重算目录摘要并与安装时记录比对（文件损坏/被替换都会露出来）
                try:
                    actual_tree = transaction.verify_tree(restore_dir, meta.get("tree_sha256"))
                except PackageManagerError as error:
                    self.storage.update_task_status(task_id, STATE_FAILED, ERR_RESTORE_MISMATCH, error.message)
                    self.emit_event(task_id, PHASE_FAILED, ERR_RESTORE_MISMATCH, error.message)
                    return False

                # ③ 原子激活：先记下当前指针，适配器回滚失败时放回去
                previous_active = transaction.read_active(skill_id) or {}
                transaction.activate(
                    skill_id,
                    restore_ver,
                    package_sha256=meta.get("package_sha256"),
                    tree_sha256=actual_tree,
                    reason="rollback",
                )
        except PackageManagerError as error:
            self.storage.update_task_status(task_id, STATE_FAILED, error.code, error.message)
            self.emit_event(task_id, PHASE_FAILED, error.code, error.message)
            return False

        ok = self.adapter.rollback(skill_id, restore_ver, restore_dir)
        if not ok:
            # 适配器失败 ⇒ 激活指针必须回到回滚前的版本，否则"文件没换、指针已换"。
            with transaction.lock(skill_id):
                if previous_active.get("version"):
                    transaction.activate(
                        skill_id,
                        str(previous_active["version"]),
                        package_sha256=previous_active.get("package_sha256"),
                        tree_sha256=previous_active.get("tree_sha256"),
                        reason="rollback_failed_restore",
                    )
                else:
                    # 之前没有激活指针（旧安装）：不能留下"指向回滚目标"的新指针。
                    transaction.deactivate(skill_id)
            self.storage.update_task_status(task_id, STATE_FAILED, "ROLLBACK_FAILED", "底层适配器回滚执行失败")
            self.emit_event(task_id, PHASE_FAILED, "ROLLBACK_FAILED", "底层适配器回滚执行失败")
            return False

        self.storage.save_installed_skill(
            skill_id=skill_id,
            current_version=restore_ver,
            previous_version=current.get("current_version") if current else None,
            status="rolled_back",
            enabled=True,
            install_path=restore_dir,
            package_sha256=meta.get("package_sha256") or (current or {}).get("package_sha256")
        )
        self.storage.update_task_status(task_id, STATE_COMMITTED)
        self.emit_event(task_id, PHASE_DONE)
        return True

    def _handle_toggle(self, task_id: str, skill_id: str, version: str, operation: int) -> bool:
        self.emit_event(task_id, PHASE_INSTALLING)
        current = self.storage.get_installed_skill(skill_id)
        if not current:
            self.emit_event(task_id, PHASE_FAILED, "SKILL_NOT_FOUND", "本地无该技能记录，无法启停")
            self.storage.update_task_status(task_id, STATE_FAILED, "SKILL_NOT_FOUND", "本地无该技能记录，无法启停")
            return False

        if operation == OP_ENABLE:
            self.adapter.enable(skill_id)
            self.storage.save_installed_skill(
                skill_id=skill_id,
                current_version=current["current_version"],
                previous_version=current.get("previous_version"),
                status="installed",
                enabled=True,
                install_path=current["install_path"]
            )
        else:
            # 停用处置：必须先取消并确认关联执行停止（F02）
            active_leases = self.storage.get_active_leases_for_skill(skill_id)
            for lease in active_leases:
                eid = lease["execution_id"]
                self.adapter.cancel_execution(eid)
                obs = self.adapter.observe_execution(eid)
                if not obs.get("is_stopped"):
                    self.emit_event(task_id, PHASE_FAILED, "STOP_UNCONFIRMED", f"执行 {eid} 未能确认安全停止，禁止停用")
                    self.storage.update_task_status(task_id, STATE_FAILED, "STOP_UNCONFIRMED", f"执行 {eid} 未能确认安全停止")
                    return False

            self.adapter.disable(skill_id)
            for lease in active_leases:
                self.storage.release_execution_lease(lease["execution_id"])

            self.storage.save_installed_skill(
                skill_id=skill_id,
                current_version=current["current_version"],
                previous_version=current.get("previous_version"),
                status="disabled",
                enabled=False,
                install_path=current["install_path"]
            )

        self.storage.update_task_status(task_id, STATE_COMMITTED)
        self.emit_event(task_id, PHASE_DONE)
        return True

    def _handle_uninstall_or_reclaim(self, task_id: str, skill_id: str, operation: int) -> bool:
        """卸载/回收：先确认执行停止与底层适配器卸载结果，严禁在未确认停止前释放租约或删除文件（F02）。"""
        self.emit_event(task_id, PHASE_INSTALLING)

        # 1. 检查并取消所有关联执行，必须观测确认停止
        active_leases = self.storage.get_active_leases_for_skill(skill_id)
        for lease in active_leases:
            eid = lease["execution_id"]
            self.adapter.cancel_execution(eid)
            obs = self.adapter.observe_execution(eid)
            if not obs.get("is_stopped"):
                self.emit_event(task_id, PHASE_FAILED, "STOP_UNCONFIRMED", f"执行 {eid} 未能确认安全停止，禁止清理租约与文件")
                self.storage.update_task_status(task_id, STATE_FAILED, "STOP_UNCONFIRMED", f"执行 {eid} 未能确认安全停止")
                return False

        # 2. 只有在执行确认停止后，才调用适配器卸载
        transaction = InstallTransaction(self.install_dir)
        try:
            skill_dir = transaction.skill_root(skill_id)
        except PackageManagerError as error:
            self.storage.update_task_status(task_id, STATE_FAILED, error.code, error.message)
            self.emit_event(task_id, PHASE_FAILED, error.code, error.message)
            return False
        uninstalled = self.adapter.uninstall(skill_id, skill_dir)
        if not uninstalled:
            self.emit_event(task_id, PHASE_FAILED, "UNINSTALL_FAILED", "底层适配器卸载失败")
            self.storage.update_task_status(task_id, STATE_FAILED, "UNINSTALL_FAILED", "底层适配器卸载失败")
            return False

        # 3. 卸载成功后，方可释放租约与清理文件
        for lease in active_leases:
            self.storage.release_execution_lease(lease["execution_id"])

        # 原子卸载：先整体 rename 走（对外瞬间消失），再删除；并发安装会被同一把锁挡住。
        try:
            with transaction.lock(skill_id):
                transaction.uninstall(skill_id)
        except PackageManagerError as error:
            self.storage.update_task_status(task_id, STATE_FAILED, error.code, error.message)
            self.emit_event(task_id, PHASE_FAILED, error.code, error.message)
            return False

        status_val = "expired" if operation == OP_RECLAIM else "uninstalled"
        current = self.storage.get_installed_skill(skill_id)
        cur_ver = current["current_version"] if current else "1.0.0"

        self.storage.save_installed_skill(
            skill_id=skill_id,
            current_version=cur_ver,
            previous_version=None,
            status=status_val,
            enabled=False,
            install_path=""
        )
        self.storage.update_task_status(task_id, STATE_COMMITTED)
        self.emit_event(task_id, PHASE_DONE)
        return True

    def _handle_execute(self, task_id: str, skill_id: str, cmd: Dict[str, Any]) -> bool:
        signed = cmd.get("signed", {})
        execution_id = signed.get("execution_id") or cmd.get("execution_id") or f"exec_{task_id}"
        params = signed.get("execution_params", {})
        plan_digest = signed.get("plan_digest")
        version = signed.get("version") or cmd.get("version") or "1.0.0"

        # 跨重启幂等：execution_id 一旦存在，绝不再次调用外部启动接口。
        existing_exec = self.storage.get_execution(execution_id)
        if existing_exec:
            st = existing_exec.get("status")
            started = bool(existing_exec.get("started_at"))
            if st in ("SUCCEEDED", "COMPLETED"):
                self.storage.update_task_status(task_id, STATE_COMMITTED)
                self.emit_event(
                    task_id, PHASE_DONE,
                    execution_fact=self._execution_fact(execution_id, "SUCCEEDED", True, True),
                )
                return True
            if st == "UNKNOWN":
                self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_UNKNOWN", existing_exec.get("error_msg"))
                self.emit_event(
                    task_id, "unknown", "EXECUTION_UNKNOWN", existing_exec.get("error_msg"),
                    execution_fact=self._execution_fact(execution_id, "UNKNOWN", started, False, "EXECUTION_UNKNOWN"),
                )
                return True
            if st in ("ACCEPTED", "RUNNING", "CANCEL_REQUESTED"):
                obs = self.executor.observe(execution_id)
                if obs.get("is_stopped") and obs.get("status") == "SUCCEEDED":
                    self.storage.update_execution_status(execution_id, "SUCCEEDED")
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(
                        task_id, PHASE_DONE,
                        execution_fact=self._execution_fact(execution_id, "SUCCEEDED", True, True),
                    )
                elif obs.get("is_stopped") and obs.get("status") == "CANCELED":
                    self.storage.update_execution_status(execution_id, "CANCELED")
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(
                        task_id, "canceled",
                        execution_fact=self._execution_fact(execution_id, "CANCELED", started, True),
                    )
                elif obs.get("is_stopped"):
                    self.storage.update_execution_status(execution_id, "FAILED", "EXECUTION_FAILED", obs.get("error_msg"))
                    self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_FAILED", obs.get("error_msg"))
                    self.emit_event(
                        task_id, PHASE_FAILED, "EXECUTION_FAILED", obs.get("error_msg"),
                        execution_fact=self._execution_fact(execution_id, "FAILED", started, True, "EXECUTION_FAILED"),
                    )
                return True
            if st == "FAILED":
                self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_FAILED", existing_exec.get("error_msg"))
                self.emit_event(
                    task_id, PHASE_FAILED, "EXECUTION_FAILED", existing_exec.get("error_msg"),
                    execution_fact=self._execution_fact(execution_id, "FAILED", started, True, "EXECUTION_FAILED"),
                )
                return False

        self.storage.record_execution(
            execution_id=execution_id,
            task_id=task_id,
            skill_id=skill_id,
            version=version,
            params=params,
            status="ACCEPTED",
            plan_digest=plan_digest,
        )
        self.emit_event(
            task_id, "accepted",
            execution_fact=self._execution_fact(execution_id, "ACCEPTED", False, False),
        )

        ok, status, err = self.executor.execute(
            execution_id=execution_id,
            skill_id=skill_id,
            plan_digest=plan_digest,
            params=params,
        )
        if ok:
            # A4.1：执行已启动、事实尚未落库 —— 重启后不得再次启动物理动作。
            failpoint("after_execution_start")

        if not ok:
            if status == EXEC_UNKNOWN:
                self.storage.update_execution_status(execution_id, "UNKNOWN", "EXECUTION_UNKNOWN", err or "执行副作用状态未知")
                self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_UNKNOWN", err or "执行副作用状态未知")
                self.emit_event(
                    task_id, "unknown", "EXECUTION_UNKNOWN", err or "执行副作用状态未知",
                    execution_fact=self._execution_fact(execution_id, "UNKNOWN", False, False, "EXECUTION_UNKNOWN"),
                )
            else:
                self.storage.update_execution_status(execution_id, "FAILED", ERR_EXECUTION_FAILED, err or "启动执行失败")
                self.storage.update_task_status(task_id, STATE_FAILED, ERR_EXECUTION_FAILED, err or "启动执行失败")
                self.emit_event(
                    task_id, PHASE_FAILED, ERR_EXECUTION_FAILED, err or "启动执行失败",
                    execution_fact=self._execution_fact(execution_id, "FAILED", False, True, ERR_EXECUTION_FAILED),
                )
            return False

        self.storage.update_execution_status(execution_id, "RUNNING")
        # RUNNING 是“已观察到外部启动”，不是“动作成功”。
        self.emit_event(
            task_id, "running",
            execution_fact=self._execution_fact(execution_id, "RUNNING", True, False),
        )

        start_t = time.time()
        raw_deadline = signed.get("execution_deadline")
        if raw_deadline and float(raw_deadline) > 1e9:
            timeout = max(0.5, float(raw_deadline) - time.time())
        else:
            timeout = float(raw_deadline or 30)
        timeout = min(timeout, 30.0)

        while time.time() - start_t < timeout:
            obs = self.executor.observe(execution_id)
            if obs.get("is_stopped"):
                if obs.get("status") == "SUCCEEDED":
                    self.storage.update_execution_status(execution_id, "SUCCEEDED")
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(
                        task_id, PHASE_DONE,
                        execution_fact=self._execution_fact(execution_id, "SUCCEEDED", True, True),
                    )
                    return True
                if obs.get("status") == "CANCELED":
                    self.storage.update_execution_status(execution_id, "CANCELED")
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(
                        task_id, "canceled",
                        execution_fact=self._execution_fact(execution_id, "CANCELED", True, True),
                    )
                    return True
                err_msg = obs.get("error_msg") or "执行失败"
                self.storage.update_execution_status(execution_id, "FAILED", ERR_EXECUTION_FAILED, err_msg)
                self.storage.update_task_status(task_id, STATE_FAILED, ERR_EXECUTION_FAILED, err_msg)
                self.emit_event(
                    task_id, PHASE_FAILED, ERR_EXECUTION_FAILED, err_msg,
                    execution_fact=self._execution_fact(execution_id, "FAILED", True, True, ERR_EXECUTION_FAILED),
                )
                return False
            time.sleep(0.01)

        # 超时先发取消，再以独立 observe 判定。未确认停止时只能是 UNKNOWN，
        # 绝不能因墙钟到期把执行写成 FAILED 并释放资源租约。
        cancel_ok, cancel_status, cancel_err = self.executor.cancel(execution_id)
        if cancel_ok:
            self.storage.update_execution_status(execution_id, "FAILED", "TIMEOUT", "技能执行超时且已确认安全停止")
            self.storage.update_task_status(task_id, STATE_FAILED, "TIMEOUT", "技能执行超时且已确认安全停止")
            self.emit_event(
                task_id, PHASE_FAILED, "TIMEOUT", "技能执行超时且已确认安全停止",
                execution_fact=self._execution_fact(execution_id, "FAILED", True, True, "TIMEOUT"),
            )
        else:
            message = cancel_err or "技能执行超时且未能确认安全停止"
            self.storage.update_execution_status(execution_id, "UNKNOWN", "EXECUTION_UNKNOWN", message)
            self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_UNKNOWN", message)
            self.emit_event(
                task_id, "unknown", "EXECUTION_UNKNOWN", message,
                execution_fact=self._execution_fact(execution_id, "UNKNOWN", True, False, "EXECUTION_UNKNOWN"),
            )
        return False
    def _handle_cancel_execution(self, task_id: str, cmd: Dict[str, Any]) -> bool:
        signed = cmd.get("signed", {})
        execution_id = signed.get("execution_id") or cmd.get("execution_id")
        if not execution_id:
            self.emit_event(task_id, PHASE_FAILED, "EXECUTION_ID_MISSING", "取消任务缺少 execution_id")
            return False

        existing = self.storage.get_execution(execution_id) or {}
        started = bool(existing.get("started_at"))
        self.storage.update_execution_status(execution_id, "CANCEL_REQUESTED")
        self.emit_event(
            task_id, "cancel_requested",
            execution_fact=self._execution_fact(execution_id, "CANCEL_REQUESTED", started, False),
        )

        ok, status, err = self.executor.cancel(execution_id)
        if ok:
            self.storage.update_execution_status(execution_id, "CANCELED")
            self.storage.update_task_status(task_id, STATE_COMMITTED)
            self.emit_event(
                task_id, "canceled",
                execution_fact=self._execution_fact(execution_id, "CANCELED", started, True),
            )
            return True

        message = err or "取消执行未能在时限内确认安全停止"
        self.storage.update_execution_status(execution_id, "UNKNOWN", "STOP_UNCONFIRMED", message)
        self.storage.update_task_status(task_id, STATE_FAILED, "STOP_UNCONFIRMED", message)
        self.emit_event(
            task_id, "unknown", "STOP_UNCONFIRMED", message,
            execution_fact=self._execution_fact(execution_id, "UNKNOWN", started, False, "STOP_UNCONFIRMED"),
        )
        return False
    def poll_once(self) -> int:
        """单次轮询：自愈恢复、冲刷积压、拉取指令并处理。返回成功处理的指令数（F04）。"""
        self.resume_recovery()
        self.flush_outbox()

        fetch_fn = getattr(self.client, "fetch_commands", None) or getattr(self.client, "pull_commands", None)
        if not fetch_fn:
            return 0
        ok, res = fetch_fn()
        if not ok or not res or not res.get("data"):
            return 0

        data = res.get("data")
        if isinstance(data, list):
            commands = data
        elif isinstance(data, dict):
            commands = data.get("commands", [])
        else:
            commands = []
        count = 0
        for cmd in commands:
            if self.process_command(cmd):
                count += 1
        return count
