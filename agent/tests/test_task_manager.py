"""测试 TaskManager：v2 全字段验签、防重放、时效阻断、Outbox 可靠补偿与回滚安全"""

import json
import os
import shutil
import tempfile
import time
import unittest
from typing import Any, Dict, List, Optional, Tuple

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.constants import (
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
    PHASE_FAILED,
    PROTOCOL_V2,
)
from zhjj_agent.crypto import canonical_v2, sign_data
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


class MockHttpClient(AgentClient):
    def __init__(self, did: str = "did_test_bot_1"):
        super().__init__("http://127.0.0.1:8000", did=did)
        self.sent_events: List[Dict[str, Any]] = []
        self.sent_acks: List[Dict[str, Any]] = []
        self.commands_queue: List[Dict[str, Any]] = []
        self.simulate_network_down = False

    def fetch_commands(self) -> Tuple[bool, Dict[str, Any]]:
        if self.simulate_network_down:
            return False, {"code": "NETWORK_DOWN", "message": "模拟断网"}
        cmds = list(self.commands_queue)
        self.commands_queue.clear()
        return True, {"data": cmds}

    def send_event(self, task_no: str, phase: str, error_code: Optional[str] = None, error_msg: Optional[str] = None) -> Tuple[bool, Dict[str, Any]]:
        if self.simulate_network_down:
            return False, {"code": "NETWORK_DOWN", "message": "模拟断网"}
        ev = {"task_no": task_no, "phase": phase, "error_code": error_code, "error_msg": error_msg}
        self.sent_events.append(ev)
        return True, {"success": True, "data": {"task_no": task_no, "phase": phase}}

    def send_ack(self, task_no: str, event_id: str, stage: str) -> Tuple[bool, Dict[str, Any]]:
        if self.simulate_network_down:
            return False, {"code": "NETWORK_DOWN", "message": "模拟断网"}
        ack = {"task_no": task_no, "event_id": event_id, "stage": stage}
        self.sent_acks.append(ack)
        return True, {"success": True, "data": ack}


class TestTaskManager(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()
        self.storage = Storage(self.temp_dir)
        self.adapter = MockAdapter()
        self.client = MockHttpClient("did_robot_101")

        # 生成测试密钥对
        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        self.priv_pem = key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption()
        ).decode("ascii")
        self.pub_pem = key.public_key().public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo
        ).decode("ascii")

        self.manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=self.client,
            adapter=self.adapter,
            pubkey_pem=self.pub_pem
        )

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _sign_v2_command(self, signed_data: dict) -> dict:
        v2_bytes = canonical_v2(signed_data)
        sig = sign_data(v2_bytes, self.priv_pem)
        return {
            "task_id": signed_data["task_id"],
            "command_seq": signed_data.get("command_seq", 1),
            "operation": signed_data["operation"],
            "skill_id": signed_data["skill_id"],
            "version": signed_data.get("version", "1.0.0"),
            "protocol_version": PROTOCOL_V2,
            "signed": signed_data,
            "signature": sig,
        }

    def test_v2_signature_tamper_rejected(self):
        signed = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_TAMPER_01",
            "command_seq": 1,
            "did": "did_robot_101",
            "operation": OP_INSTALL,
            "skill_id": "100",
            "version": "1.0.0",
            "package_sha256": "abc123sha",
            "task_expires_at": int(time.time()) + 3600,
        }
        cmd = self._sign_v2_command(signed)

        # 1. 篡改操作码 operation 1 -> 2
        cmd_tampered = dict(cmd)
        cmd_tampered["signed"] = dict(signed)
        cmd_tampered["signed"]["operation"] = OP_UNINSTALL  # 篡改 signed 内容，但保持原签名
        cmd_tampered["operation"] = OP_UNINSTALL

        ok = self.manager.process_command(cmd_tampered)
        self.assertFalse(ok)
        last_ev = self.client.sent_events[-1]
        self.assertEqual(last_ev["phase"], PHASE_FAILED)
        self.assertEqual(last_ev["error_code"], ERR_SIG_INVALID)

    def test_task_expired_rejected_before_side_effects(self):
        signed = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_EXPIRED_01",
            "command_seq": 1,
            "did": "did_robot_101",
            "operation": OP_INSTALL,
            "skill_id": "100",
            "version": "1.0.0",
            "task_expires_at": int(time.time()) - 10,  # 10秒前已过期
        }
        cmd = self._sign_v2_command(signed)
        ok = self.manager.process_command(cmd)
        self.assertFalse(ok)
        last_ev = self.client.sent_events[-1]
        self.assertEqual(last_ev["phase"], PHASE_FAILED)
        self.assertEqual(last_ev["error_code"], ERR_TASK_EXPIRED)

    def test_replay_duplicate_task_idempotency(self):
        signed = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_REPLAY_01",
            "command_seq": 1,
            "did": "did_robot_101",
            "operation": OP_ENABLE,
            "skill_id": "100",
            "version": "1.0.0",
            "task_expires_at": int(time.time()) + 3600,
        }
        cmd = self._sign_v2_command(signed)

        # 预先安装好技能
        self.storage.save_installed_skill("100", "1.0.0", None, "installed", True, "/tmp/v1")
        self.adapter.install("100", "1.0.0", "/tmp/v1", {})

        # 首次执行
        ok1 = self.manager.process_command(cmd)
        self.assertTrue(ok1)
        ack_count_1 = len(self.client.sent_acks)
        self.assertEqual(ack_count_1, 1)

        # 再次送达相同 task_id -> 幂等直接返回，不重复创建安装事实
        ok2 = self.manager.process_command(cmd)
        self.assertTrue(ok2)
        # 不会发送新的 ACK 登记请求
        self.assertEqual(len(self.client.sent_acks), 1)

    def test_outbox_offline_retry_and_flush(self):
        # 1. 模拟网络断开
        self.client.simulate_network_down = True

        # 发射事件 -> 写入本地 SQLite outbox，网络失败留在队列
        self.manager.emit_event("T_OFFLINE_01", "downloading")
        self.manager.emit_event("T_OFFLINE_01", "done")

        unacked = self.storage.get_unacked_events()
        self.assertEqual(len(unacked), 2)
        self.assertEqual(len(self.client.sent_events), 0)

        # 2. 网络恢复
        self.client.simulate_network_down = False
        self.manager.flush_outbox()

        # 全部事件成功上报并被 ACK
        unacked_after = self.storage.get_unacked_events()
        self.assertEqual(len(unacked_after), 0)
        self.assertEqual(len(self.client.sent_events), 2)

    def test_first_install_rollback_safely_disables(self):
        # 首装回滚（无旧版）-> 安全停用，不报假恢复
        self.storage.save_installed_skill("100", "1.0.0", None, "installed", True, "/tmp/v1")
        self.adapter.install("100", "1.0.0", "/tmp/v1", {})

        signed = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_ROLLBACK_01",
            "command_seq": 2,
            "did": "did_robot_101",
            "operation": OP_ROLLBACK,
            "skill_id": "100",
            "version": "1.0.0",  # 无其他版本
            "task_expires_at": int(time.time()) + 3600,
        }
        cmd = self._sign_v2_command(signed)
        ok = self.manager.process_command(cmd)
        self.assertTrue(ok)

        # 检查本地技能状态：变为 disabled，绝不虚报为 rolled_back
        skill = self.storage.get_installed_skill("100")
        self.assertEqual(skill["status"], "disabled")
        self.assertFalse(skill["enabled"])

        # 汇报事件包含 NO_PREVIOUS_VERSION 真实标注
        last_ev = self.client.sent_events[-1]
        self.assertEqual(last_ev["phase"], PHASE_DONE)
        self.assertEqual(last_ev["error_code"], "NO_PREVIOUS_VERSION")

    def test_v2_signature_tamper_all_fields_rejected(self):
        base_signed = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_TAMPER_FIELDS",
            "command_seq": 1,
            "did": "did_robot_101",
            "operation": OP_INSTALL,
            "skill_id": "100",
            "version": "1.0.0",
            "package_sha256": "orig_sha_12345",
            "task_expires_at": int(time.time()) + 3600,
        }
        cmd = self._sign_v2_command(base_signed)

        # 1. 篡改 did (设备冒名)
        cmd_tamper_did = dict(cmd)
        cmd_tamper_did["signed"] = dict(base_signed)
        cmd_tamper_did["signed"]["did"] = "did_evil_bot"
        self.assertFalse(self.manager.process_command(cmd_tamper_did))
        self.assertEqual(self.client.sent_events[-1]["error_code"], ERR_SIG_INVALID)

        # 2. 篡改 package_sha256 (包篡改)
        cmd_tamper_hash = dict(cmd)
        cmd_tamper_hash["signed"] = dict(base_signed)
        cmd_tamper_hash["signed"]["package_sha256"] = "tampered_hash_999"
        self.assertFalse(self.manager.process_command(cmd_tamper_hash))
        self.assertEqual(self.client.sent_events[-1]["error_code"], ERR_SIG_INVALID)

        # 3. 篡改 task_expires_at (延长时效)
        cmd_tamper_exp = dict(cmd)
        cmd_tamper_exp["signed"] = dict(base_signed)
        cmd_tamper_exp["signed"]["task_expires_at"] = int(time.time()) + 99999
        self.assertFalse(self.manager.process_command(cmd_tamper_exp))
        self.assertEqual(self.client.sent_events[-1]["error_code"], ERR_SIG_INVALID)

    def test_op_execute_and_cancel_lifecycle(self):
        # 预先安装好技能并启用
        self.storage.save_installed_skill("100", "1.0.0", None, "installed", True, "/tmp/v1")
        self.adapter.install("100", "1.0.0", "/tmp/v1", {})

        # 1. 发送 OP_EXECUTE 指令
        signed_exec = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_EXEC_001",
            "command_seq": 10,
            "did": "did_robot_101",
            "operation": OP_EXECUTE,
            "skill_id": "100",
            "version": "1.0.0",
            "execution_id": "exec_round_1",
            "task_expires_at": int(time.time()) + 3600,
        }
        cmd_exec = self._sign_v2_command(signed_exec)
        ok1 = self.manager.process_command(cmd_exec)
        self.assertTrue(ok1)

        # 执行事件已发射
        self.assertTrue(any(ev.get("task_no") == "T_EXEC_001" for ev in self.client.sent_events))

        # 执行事实与安装事实严格隔离：installed_skill 版本依然保持 1.0.0，未被篡改
        skill = self.storage.get_installed_skill("100")
        self.assertEqual(skill["current_version"], "1.0.0")
        self.assertEqual(skill["status"], "installed")

        # 2. 发送 OP_CANCEL_EXECUTION 取消指令
        signed_cancel = {
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_CANCEL_001",
            "command_seq": 11,
            "did": "did_robot_101",
            "operation": OP_CANCEL_EXECUTION,
            "skill_id": "100",
            "version": "1.0.0",
            "execution_id": "exec_round_1",
            "task_expires_at": int(time.time()) + 3600,
        }
        cmd_cancel = self._sign_v2_command(signed_cancel)
        ok2 = self.manager.process_command(cmd_cancel)
        self.assertTrue(ok2)
        self.assertTrue(any(ev.get("task_no") == "T_CANCEL_001" for ev in self.client.sent_events))

    def test_distinct_execution_ids_not_deduped(self):
        self.storage.save_installed_skill("100", "1.0.0", None, "installed", True, "/tmp/v1")
        self.adapter.install("100", "1.0.0", "/tmp/v1", {})

        # 执行 1
        cmd1 = self._sign_v2_command({
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_EXEC_A",
            "command_seq": 20,
            "did": "did_robot_101",
            "operation": OP_EXECUTE,
            "skill_id": "100",
            "execution_id": "exec_unique_A",
            "task_expires_at": int(time.time()) + 3600,
        })
        self.assertTrue(self.manager.process_command(cmd1))

        # 等待模拟动作微延迟结束
        time.sleep(0.06)

        # 执行 2（同个技能，不同的 execution_id 与 task_id）
        cmd2 = self._sign_v2_command({
            "protocol_version": PROTOCOL_V2,
            "task_id": "T_EXEC_B",
            "command_seq": 21,
            "did": "did_robot_101",
            "operation": OP_EXECUTE,
            "skill_id": "100",
            "execution_id": "exec_unique_B",
            "task_expires_at": int(time.time()) + 3600,
        })
        # 绝不能被吞掉或视为重复安装
        self.assertTrue(self.manager.process_command(cmd2))
        tasks_in_storage = [self.storage.get_task("T_EXEC_A"), self.storage.get_task("T_EXEC_B")]
        self.assertIsNotNone(tasks_in_storage[0])
        self.assertIsNotNone(tasks_in_storage[1])

    def test_interrupted_install_recovery_on_startup(self):
        # 模拟前序进程崩溃：在 SQLite tasks 中留下中间状态
        self.storage.record_task_durable(
            task_id="T_CRASH_01",
            command_seq=1,
            did="did_robot_101",
            operation=OP_INSTALL,
            skill_id="100",
            skill_version_id="1",
            version="1.0.0",
            signed_payload={},
            raw_command={},
            initial_status="installing"
        )

        # 重新创建 TaskManager 实例（模拟设备重启恢复）
        new_manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=self.client,
            adapter=self.adapter,
            pubkey_pem=self.pub_pem
        )

        # 启动时执行恢复
        new_manager.resume_recovery()

        # 检查未完任务已被安全推进失败并排入 outbox
        recovered_task = self.storage.get_task("T_CRASH_01")
        self.assertEqual(recovered_task["status"], "FAILED")
        self.assertEqual(recovered_task["error_code"], "RECOVERY_INCOMPLETE")

        # flush_outbox 上报服务端
        new_manager.flush_outbox()
        self.assertTrue(any(ev.get("task_no") == "T_CRASH_01" and ev.get("phase") == "failed" for ev in self.client.sent_events))



if __name__ == "__main__":
    unittest.main()
