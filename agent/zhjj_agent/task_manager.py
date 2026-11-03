"""任务管理器：生命周期状态机、验签、Staging 安装、执行监督与 Outbox 可靠对账"""

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
            # 无论服务端返回成功还是合法业务拒绝（409/404 等 X1 信封），均视为平台已知晓并记录 ACK
            if ok or resp.get("code") in ("TASK_TRANSITION_INVALID", "TASK_NOT_FOUND"):
                self.storage.mark_event_acked(event_id, resp)

    def resume_recovery(self):
        """断网/重启后的崩溃恢复与事实对账。"""
        # 1. 优先冲刷积压事件
        self.flush_outbox()

        # 2. 检查未完结的持久化任务
        uncompleted = self.storage.get_uncompleted_tasks()
        for task in uncompleted:
            task_id = task["task_id"]
            op = task["operation"]
            skill_id = task["skill_id"]

            # 通过底层适配器独立观测设备侧实际状态
            obs = self.adapter.observe(skill_id)
            if obs.get("installed") and obs.get("status") == "active":
                # 实际已安装完成，补充持久化提交并上报 done
                self.storage.update_task_status(task_id, STATE_COMMITTED)
                self.emit_event(task_id, PHASE_DONE)
            else:
                # 无法确认完成，标记需人工处置，绝不伪造成功，并入队回报事件
                self.storage.update_task_status(task_id, STATE_FAILED, "RECOVERY_INCOMPLETE", "进程重启后未能确认外部安装状态")
                self.emit_event(task_id, PHASE_FAILED, "RECOVERY_INCOMPLETE", "进程重启后未能确认外部安装状态")

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
            error_msg=error_msg
        )
        if ok or resp.get("code") in ("TASK_TRANSITION_INVALID", "TASK_NOT_FOUND"):
            self.storage.mark_event_acked(event_id, resp)

    # ── 指令处理核心流水线 ───────────────────────────────────────────────────

    def process_command(self, cmd: Dict[str, Any]) -> bool:
        task_id = cmd.get("task_id")
        operation = int(cmd.get("operation") or OP_INSTALL)
        skill_id = str(cmd.get("skill_id") or "")
        version = str(cmd.get("version") or "1.0.0")
        command_seq = int(cmd.get("command_seq") or 1)
        protocol = cmd.get("protocol_version") or cmd.get("signed", {}).get("protocol_version")

        # ── 1. 验签与防重放 ───────────────────────────────────────────────────
        if protocol == PROTOCOL_V2 and "signed" in cmd:
            signed = cmd["signed"]
            sig_b64 = cmd.get("signature") or cmd.get("signature_v2")
            if not verify_signature(canonical_v2(signed), sig_b64, self.pubkey_pem):
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "v2 签名校验失败")
                return False

            # 时效检查
            task_expires_at = signed.get("task_expires_at")
            if task_expires_at and int(time.time()) > int(task_expires_at):
                # 处置类操作不被过期阻断，安装/启用/执行类阻断
                if operation in (OP_INSTALL, OP_ENABLE, OP_EXECUTE):
                    self.emit_event(task_id, PHASE_FAILED, ERR_TASK_EXPIRED, "指令已过任务有效期")
                    return False

            # 撤销代次检查
            revocation_epoch = int(signed.get("revocation_epoch") or 0)
            known_epoch = self.storage.get_revocation_epoch(f"skill:{skill_id}")
            if revocation_epoch < known_epoch and operation in (OP_INSTALL, OP_ENABLE, OP_EXECUTE):
                self.emit_event(task_id, PHASE_FAILED, ERR_REVOKED, "该技能已被更高代次撤销，拒绝启用或安装")
                return False
        else:
            # v1 回退验签
            sig_b64 = cmd.get("signature") or cmd.get("signature_v1")
            if not verify_signature(canonical_v1(cmd), sig_b64, self.pubkey_pem):
                self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "v1 签名校验未通过")
                return False

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

        # ── 3. 按操作分支执行 ─────────────────────────────────────────────────
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
        expected_sha = (cmd.get("package_sha256") or "").lower()
        url = cmd.get("package_url")

        if not expected_sha and not url:
            # 无包 dry install
            self.emit_event(task_id, PHASE_INSTALLING)
            self.storage.update_task_status(task_id, STATE_COMMITTED)
            self.emit_event(task_id, PHASE_DONE, ERR_PKG_MISSING, "未分发技能包(dry install)，仅走通状态机")
            return True

        if expected_sha and not url:
            self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令声明了包哈希却没有包下载地址")
            return False

        if url and not expected_sha:
            self.emit_event(task_id, PHASE_FAILED, ERR_SIG_INVALID, "指令带有包地址却没有签住的哈希，拒绝下载")
            return False

        # 开始下载
        self.emit_event(task_id, PHASE_DOWNLOADING)
        self.storage.update_task_status(task_id, STATE_DOWNLOADING)

        tmp_fd, tmp_pkg_path = tempfile.mkstemp(prefix="zhskill_", suffix=".skill")
        os.close(tmp_fd)

        try:
            got_sha = PackageManager.download_package(url, tmp_pkg_path, self.client._build_auth_headers("GET", url))
        except PackageManagerError as e:
            if os.path.exists(tmp_pkg_path):
                os.remove(tmp_pkg_path)
            self.emit_event(task_id, PHASE_DOWNLOAD_FAILED, e.code, str(e))
            return False
        except Exception as e:
            if os.path.exists(tmp_pkg_path):
                os.remove(tmp_pkg_path)
            self.emit_event(task_id, PHASE_DOWNLOAD_FAILED, ERR_DOWNLOAD_FAILED, f"下载网络异常: {e}")
            return False

        # 核验哈希
        if got_sha != expected_sha:
            if os.path.exists(tmp_pkg_path):
                os.remove(tmp_pkg_path)
            self.emit_event(task_id, PHASE_FAILED, ERR_PKG_HASH_MISMATCH, f"下载包哈希不符: 期望 {expected_sha}, 实际 {got_sha}")
            return False

        # 进入解包与 Staging 事务
        self.emit_event(task_id, PHASE_INSTALLING)
        self.storage.update_task_status(task_id, STATE_STAGED)
        self.storage.save_checkpoint(task_id, STATE_STAGED, {"sha256": got_sha})

        try:
            final_dir, manifest, files = PackageManager.install_with_staging(
                tmp_pkg_path, self.install_dir, skill_id, version
            )
        except PackageManagerError as e:
            self.emit_event(task_id, PHASE_FAILED, e.code, e.message)
            return False
        finally:
            if os.path.exists(tmp_pkg_path):
                os.remove(tmp_pkg_path)

        # 底层适配器安装
        self.storage.update_task_status(task_id, STATE_INSTALLING)
        if not self.adapter.prepare(skill_id, version, final_dir, manifest):
            self.emit_event(task_id, PHASE_FAILED, "PREPARE_FAILED", "底层适配器预检环境失败")
            return False

        if not self.adapter.install(skill_id, version, final_dir, manifest):
            self.emit_event(task_id, PHASE_FAILED, "INSTALL_FAILED", "底层适配器安装注册失败")
            return False

        # 独立健康观测
        self.storage.update_task_status(task_id, STATE_HEALTH_CHECKING)
        obs = self.adapter.observe(skill_id)
        if not obs.get("installed"):
            self.emit_event(task_id, PHASE_FAILED, "OBSERVE_FAILED", "安装后设备独立观测失败")
            return False

        # 持久化提交
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
        """显式回滚：区分坏版本与良好恢复目标。首装无旧版时安全停用，不报假恢复。"""
        self.emit_event(task_id, PHASE_INSTALLING)

        current = self.storage.get_installed_skill(skill_id)
        restore_ver = target_version or (current.get("previous_version") if current else None)

        if not restore_ver or (current and restore_ver == current.get("current_version")):
            # 首装或无良好旧版：按规则安全停用
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
            return False

        ok = self.adapter.rollback(skill_id, restore_ver, restore_dir)
        if not ok:
            self.emit_event(task_id, PHASE_FAILED, "ROLLBACK_FAILED", "底层适配器回滚执行失败")
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
            return False

        if operation == OP_ENABLE:
            # enable 只调用适配器允许调用，不能自动触发物理动作
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
            # disable：先停执行
            self.adapter.disable(skill_id)
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
        self.emit_event(task_id, PHASE_INSTALLING)
        skill_dir = os.path.join(self.install_dir, "skills", str(skill_id))
        self.adapter.uninstall(skill_id, skill_dir)
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
        execution_id = cmd.get("execution_id") or signed.get("execution_id") or f"exec_{task_id}"
        params = signed.get("execution_params") or cmd.get("execution_params") or {}
        plan_digest = signed.get("plan_digest")

        self.emit_event(task_id, PHASE_INSTALLING)  # 对齐平台执行中状态

        ok, status, err = self.executor.execute(
            execution_id=execution_id,
            skill_id=skill_id,
            plan_digest=plan_digest,
            params=params
        )
        if not ok:
            self.emit_event(task_id, PHASE_FAILED, ERR_EXECUTION_FAILED, err or "启动执行失败")
            return False

        # 观测执行直到完成或停止
        start_t = time.time()
        timeout = float(signed.get("execution_deadline") or 30)
        while time.time() - start_t < timeout:
            obs = self.executor.observe(execution_id)
            if obs.get("is_stopped"):
                if obs.get("status") == "SUCCEEDED":
                    self.storage.update_task_status(task_id, STATE_COMMITTED)
                    self.emit_event(task_id, PHASE_DONE)
                    return True
                else:
                    self.emit_event(task_id, PHASE_FAILED, ERR_EXECUTION_FAILED, obs.get("error_msg") or "执行失败")
                    return False
            time.sleep(0.01)

        # 超时
        self.executor.cancel(execution_id)
        self.emit_event(task_id, PHASE_FAILED, "TIMEOUT", "技能执行超时未确认完成")
        return False

    def _handle_cancel_execution(self, task_id: str, cmd: Dict[str, Any]) -> bool:
        execution_id = cmd.get("execution_id") or cmd.get("signed", {}).get("execution_id")
        if not execution_id:
            self.emit_event(task_id, PHASE_FAILED, "EXECUTION_ID_MISSING", "取消任务缺少 execution_id")
            return False

        ok, status, err = self.executor.cancel(execution_id)
        if ok:
            self.storage.update_task_status(task_id, STATE_COMMITTED)
            self.emit_event(task_id, PHASE_DONE)
            return True
        else:
            self.emit_event(task_id, PHASE_FAILED, "CANCEL_NOT_STOPPED", err or "未能确认安全停止")
            return False

    # ── 轮询单次循环 ─────────────────────────────────────────────────────────

    def poll_once(self) -> int:
        """执行一次轮询循环：自愈补报 -> 拉取指令 -> 执行 -> 冲刷事件。返回处理的任务数。"""
        self.resume_recovery()

        ok, resp = self.client.fetch_commands()
        if not ok:
            return 0

        commands = resp.get("data") or []
        count = 0
        for cmd in commands:
            self.process_command(cmd)
            count += 1

        self.flush_outbox()
        return count
