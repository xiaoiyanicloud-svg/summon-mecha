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
    ERR_REVOKED,
    ERR_SIG_INVALID,
    ERR_TASK_EXPIRED,
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
from zhjj_agent.package_manager import PackageManager, PackageManagerError
from zhjj_agent.storage import Storage


class TaskManager:
    def __init__(
        self,
        install_dir: str,
        storage: Storage,
        client: AgentClient,
        adapter: BaseInstallAdapter,
        pubkey_pem: str
    ):
        self.install_dir = os.path.abspath(install_dir)
        self.storage = storage
        self.client = client
        self.adapter = adapter
        self.executor = SkillExecutor(storage, adapter)
        self.pubkey_pem = pubkey_pem.strip()

    # ── 启动自愈与 Outbox 补偿 ───────────────────────────────────────────────

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
                error_msg=payload.get("error_msg")
            )
            # 只有取得平台确认才标记已确认，严禁因 TASK_TRANSITION_INVALID / TASK_NOT_FOUND 无条件丢弃待发事件
            if ok:
                self.storage.mark_event_acked(event_id, resp)

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
                exec_id = task.get("execution_id") or f"exec_{task_id}"
                exec_obs = self.executor.observe(exec_id)
                if exec_obs.get("is_stopped"):
                    if exec_obs.get("status") == "SUCCEEDED":
                        self.storage.update_task_status(task_id, STATE_COMMITTED)
                        self.emit_event(task_id, PHASE_DONE)
                    else:
                        self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_FAILED", exec_obs.get("error_msg"))
                        self.emit_event(task_id, PHASE_FAILED, "EXECUTION_FAILED", exec_obs.get("error_msg"))
                else:
                    self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "执行状态未知")
                    self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "执行状态未知")
            else:
                self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "未识别的在途任务操作")
                self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "未识别的在途任务操作")

    # ── 事件发射封装 ─────────────────────────────────────────────────────────

    def emit_event(
        self,
        task_id: str,
        phase: str,
        error_code: Optional[str] = None,
        error_msg: Optional[str] = None
    ):
        event_id = f"evt_{task_id}_{phase}_{int(time.time()*1000)}"
        payload = {
            "task_no": task_id,
            "did": self.client.did,
            "phase": phase,
            "error_code": error_code,
            "error_msg": error_msg,
        }
        # 严格遵守：先持久写入 outbox，再尝试网络请求
        self.storage.record_event_outbox(event_id, task_id, phase, payload)

        ok, resp = self.client.send_event(
            task_no=task_id,
            phase=phase,
            error_code=error_code,
            error_msg=error_msg,
            event_id=event_id
        )
        if ok:
            self.storage.mark_event_acked(event_id, resp)

    # ── 指令处理核心流水线 ───────────────────────────────────────────────────

    def process_command(self, cmd: Dict[str, Any]) -> bool:
        raw_op = cmd.get("operation")
        protocol = cmd.get("protocol_version") or cmd.get("signed", {}).get("protocol_version")

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

        pkg_mgr = PackageManager(self.install_dir)
        download_dest = os.path.join(self.install_dir, "downloads", f"{skill_id}_{version}.skill")
        staging_dir = os.path.join(self.install_dir, "staging", f"{skill_id}_{version}_{int(time.time())}")

        # F01: 传递受控设备凭据下载请求头
        download_headers = self.client.get_download_headers(package_url)

        try:
            got_sha = pkg_mgr.download_and_verify(package_url, download_dest, expected_sha, headers=download_headers)
        except PackageManagerError as e:
            self.storage.update_task_status(task_id, STATE_FAILED, e.code, e.message)
            self.emit_event(task_id, PHASE_DOWNLOAD_FAILED, e.code, e.message)
            return False

        # 阶段 B: 解压与 Staging
        try:
            pkg_mgr.unpack_to_staging(download_dest, staging_dir)
            manifest = pkg_mgr.read_and_validate_manifest(staging_dir)
        except PackageManagerError as e:
            self.storage.update_task_status(task_id, STATE_FAILED, e.code, e.message)
            self.emit_event(task_id, PHASE_FAILED, e.code, e.message)
            return False

        self.storage.update_task_status(task_id, STATE_STAGED)

        # 阶段 C: 适配器 prepare 与环境检查
        if not self.adapter.prepare(skill_id, version, staging_dir, manifest):
            self.storage.update_task_status(task_id, STATE_FAILED, "PREPARE_REJECTED", "适配器环境或机型预检拒绝")
            self.emit_event(task_id, PHASE_FAILED, "PREPARE_REJECTED", "适配器环境或机型预检拒绝")
            return False

        # 阶段 D: 事务原子提交与激活
        self.storage.update_task_status(task_id, STATE_INSTALLING)
        self.emit_event(task_id, PHASE_INSTALLING)

        final_dir, files = pkg_mgr.commit_staging_to_final(staging_dir, skill_id, version)

        # 调用底层适配器正式安装
        if not self.adapter.install(skill_id, version, final_dir, manifest):
            self.storage.update_task_status(task_id, STATE_FAILED, "ADAPTER_INSTALL_FAILED", "底层适配器安装失败")
            self.emit_event(task_id, PHASE_FAILED, "ADAPTER_INSTALL_FAILED", "底层适配器安装失败")
            return False

        # 阶段 E: 健康探针核验
        self.storage.update_task_status(task_id, STATE_HEALTH_CHECKING)
        obs = self.adapter.observe(skill_id)
        if not obs.get("installed"):
            self.storage.update_task_status(task_id, STATE_FAILED, "OBSERVE_FAILED", "安装后健康观测未通过")
            self.emit_event(task_id, PHASE_FAILED, "OBSERVE_FAILED", "安装后健康观测未通过")
            return False

        # 更新本地安装事实表与 state/installed.json
        prev = self.storage.get_installed_skill(skill_id)
        prev_ver = prev["current_version"] if prev and prev["current_version"] != version else None
        status_str = "updated" if prev_ver else "installed"

        self.storage.save_installed_skill(
            skill_id=skill_id,
            current_version=version,
            previous_version=prev_ver,
            status=status_str,
            enabled=True,
            install_path=final_dir,
            package_sha256=got_sha,
            manifest=manifest,
            files=files
        )
        self.storage.update_task_status(task_id, STATE_COMMITTED)

        # 上报完成
        self.emit_event(task_id, PHASE_DONE)
        return True

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

        restore_dir = os.path.join(self.install_dir, "skills", str(skill_id), "v" + str(restore_ver))
        if not os.path.isdir(restore_dir):
            self.emit_event(task_id, PHASE_FAILED, "RESTORE_TARGET_MISSING", f"回滚目标版本 {restore_ver} 本地文件不存在")
            self.storage.update_task_status(task_id, STATE_FAILED, "RESTORE_TARGET_MISSING", f"回滚目标版本 {restore_ver} 本地文件不存在")
            return False

        # 核验恢复目标内容哈希（F07）
        restore_sha256 = signed.get("restore_sha256")
        if restore_sha256:
            candidate_files = [os.path.join(restore_dir, "payload"), os.path.join(restore_dir, "package.skill")]
            target_file = next((f for f in candidate_files if os.path.isfile(f)), None)
            if target_file:
                with open(target_file, "rb") as f:
                    actual_sha = hashlib.sha256(f.read()).hexdigest().lower()
                if actual_sha != restore_sha256.lower():
                    self.storage.update_task_status(task_id, STATE_FAILED, ERR_PKG_HASH_MISMATCH, "回滚恢复目标内容哈希与签名 restore_sha256 不符")
                    self.emit_event(task_id, PHASE_FAILED, ERR_PKG_HASH_MISMATCH, "回滚恢复目标内容哈希与签名 restore_sha256 不符")
                    return False
            else:
                inst = self.storage.get_installed_skill(skill_id)
                if inst and inst.get("package_sha256") and inst["package_sha256"].lower() != restore_sha256.lower():
                    self.storage.update_task_status(task_id, STATE_FAILED, ERR_PKG_HASH_MISMATCH, "回滚恢复目标元数据哈希与签名 restore_sha256 不符")
                    self.emit_event(task_id, PHASE_FAILED, ERR_PKG_HASH_MISMATCH, "回滚恢复目标元数据哈希与签名 restore_sha256 不符")
                    return False

        ok = self.adapter.rollback(skill_id, restore_ver, restore_dir)
        if not ok:
            self.emit_event(task_id, PHASE_FAILED, "ROLLBACK_FAILED", "底层适配器回滚执行失败")
            self.storage.update_task_status(task_id, STATE_FAILED, "ROLLBACK_FAILED", "底层适配器回滚执行失败")
            return False

        self.storage.save_installed_skill(
            skill_id=skill_id,
            current_version=restore_ver,
            previous_version=current.get("current_version") if current else None,
            status="rolled_back",
            enabled=True,
            install_path=restore_dir
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
        skill_dir = os.path.join(self.install_dir, "skills", str(skill_id))
        uninstalled = self.adapter.uninstall(skill_id, skill_dir)
        if not uninstalled:
            self.emit_event(task_id, PHASE_FAILED, "UNINSTALL_FAILED", "底层适配器卸载失败")
            self.storage.update_task_status(task_id, STATE_FAILED, "UNINSTALL_FAILED", "底层适配器卸载失败")
            return False

        # 3. 卸载成功后，方可释放租约与清理文件
        for lease in active_leases:
            self.storage.release_execution_lease(lease["execution_id"])

        if os.path.isdir(skill_dir):
            shutil.rmtree(skill_dir, ignore_errors=True)

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
        # 严格使用已签名的参数（F05）
        params = signed.get("execution_params", {})
        plan_digest = signed.get("plan_digest")
        version = signed.get("version") or cmd.get("version") or "1.0.0"

        self.emit_event(task_id, PHASE_INSTALLING)

        # 检查持久化执行事实表，保证跨重启幂等，外部启动至多一次（F03）
        existing_exec = self.storage.get_execution(execution_id)
        if existing_exec:
            st = existing_exec.get("status")
            if st in ("SUCCEEDED", "COMPLETED"):
                self.storage.update_task_status(task_id, STATE_COMMITTED)
                self.emit_event(task_id, PHASE_DONE)
                return True
            elif st in ("ACCEPTED", "RUNNING"):
                obs = self.executor.observe(execution_id)
                if obs.get("status") == "SUCCEEDED":
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(task_id, PHASE_DONE)
                    return True
                elif obs.get("is_stopped"):
                    if obs.get("status") == "FAILED":
                        self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_FAILED", obs.get("error_msg"))
                        self.emit_event(task_id, PHASE_FAILED, "EXECUTION_FAILED", obs.get("error_msg"))
                        return False
                return True
            elif st == "FAILED":
                self.storage.update_task_status(task_id, STATE_FAILED, "EXECUTION_FAILED", existing_exec.get("error_msg"))
                self.emit_event(task_id, PHASE_FAILED, "EXECUTION_FAILED", existing_exec.get("error_msg"))
                return False

        # 记录执行意图（F03）
        self.storage.record_execution(
            execution_id=execution_id,
            task_id=task_id,
            skill_id=skill_id,
            version=version,
            params=params,
            status="ACCEPTED",
            plan_digest=plan_digest
        )

        ok, status, err = self.executor.execute(
            execution_id=execution_id,
            skill_id=skill_id,
            plan_digest=plan_digest,
            params=params
        )
        if not ok:
            self.storage.update_execution_status(execution_id, "FAILED", ERR_EXECUTION_FAILED, err or "启动执行失败")
            self.storage.update_task_status(task_id, STATE_FAILED, ERR_EXECUTION_FAILED, err or "启动执行失败")
            self.emit_event(task_id, PHASE_FAILED, ERR_EXECUTION_FAILED, err or "启动执行失败")
            return False

        self.storage.update_execution_status(execution_id, "RUNNING")

        # 观测执行直到完成或停止
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
                    self.emit_event(task_id, PHASE_DONE)
                    return True
                else:
                    err_msg = obs.get("error_msg") or "执行失败"
                    self.storage.update_execution_status(execution_id, "FAILED", ERR_EXECUTION_FAILED, err_msg)
                    self.storage.update_task_status(task_id, STATE_FAILED, ERR_EXECUTION_FAILED, err_msg)
                    self.emit_event(task_id, PHASE_FAILED, ERR_EXECUTION_FAILED, err_msg)
                    return False
            time.sleep(0.01)

        # 超时
        self.executor.cancel(execution_id)
        self.storage.update_execution_status(execution_id, "FAILED", "TIMEOUT", "技能执行超时未确认完成")
        self.storage.update_task_status(task_id, STATE_FAILED, "TIMEOUT", "技能执行超时未确认完成")
        self.emit_event(task_id, PHASE_FAILED, "TIMEOUT", "技能执行超时未确认完成")
        return False

    def _handle_cancel_execution(self, task_id: str, cmd: Dict[str, Any]) -> bool:
        signed = cmd.get("signed", {})
        execution_id = signed.get("execution_id") or cmd.get("execution_id")
        if not execution_id:
            self.emit_event(task_id, PHASE_FAILED, "EXECUTION_ID_MISSING", "取消任务缺少 execution_id")
            return False

        ok, status, err = self.executor.cancel(execution_id)
        if ok:
            self.storage.update_execution_status(execution_id, "CANCELED")
            self.storage.update_task_status(task_id, STATE_COMMITTED)
            self.emit_event(task_id, PHASE_DONE)
            return True
        else:
            self.emit_event(task_id, PHASE_FAILED, "CANCEL_FAILED", err or "取消执行未能在时限内确认安全停止")
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
