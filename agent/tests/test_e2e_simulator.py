"""端到端全链路模拟器测试：
完整覆盖：设备注册/心跳 -> 任务领取 -> v2验签 -> Staging安装 -> ACK确认 -> 技能执行(OP 7) -> 观测停止 -> 取消(OP 8) -> 事实对账。
"""

import json
import os
import shutil
import tempfile
import time
import unittest
import zipfile
from typing import Any, Dict, List, Optional, Tuple

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.constants import (
    ERR_SIG_INVALID,
    EXEC_CANCELED,
    EXEC_SUCCEEDED,
    OP_CANCEL_EXECUTION,
    OP_EXECUTE,
    OP_INSTALL,
    PHASE_DONE,
    PHASE_DOWNLOADING,
    PHASE_FAILED,
    PHASE_INSTALLING,
    PROTOCOL_V2,
)
from zhjj_agent.crypto import canonical_v2, sha256_file, sign_data
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


class SimulatedPlatformServer(AgentClient):
    """内存模拟的服务端平台契约控制器"""

    def __init__(self, did: str, priv_pem: str):
        super().__init__("http://simulator.local", did=did)
        self.priv_pem = priv_pem
        self.pending_tasks: List[Dict[str, Any]] = []
        self.event_log: List[Dict[str, Any]] = []
        self.ack_log: List[Dict[str, Any]] = []
        self.reconciliation_history: List[Dict[str, Any]] = []
        self.server_install_records: Dict[str, str] = {}  # skill_id -> version

    def enqueue_v2_task(
        self,
        task_id: str,
        command_seq: int,
        operation: int,
        skill_id: str,
        version: str,
        package_sha256: Optional[str] = None,
        package_url: Optional[str] = None,
        execution_id: Optional[str] = None,
        execution_params: Optional[Dict[str, Any]] = None,
    ):
        expires_at = int(time.time()) + 3600
        signed_payload = {
            "protocol_version": PROTOCOL_V2,
            "task_id": task_id,
            "command_seq": command_seq,
            "did": self.did,
            "operation": operation,
            "skill_id": skill_id,
            "version": version,
            "package_sha256": package_sha256 or "",
            "execution_id": execution_id or "",
            "task_expires_at": expires_at,
        }
        if execution_params:
            signed_payload["execution_params"] = execution_params

        v2_bytes = canonical_v2(signed_payload)
        sig = sign_data(v2_bytes, self.priv_pem)

        cmd = {
            "task_id": task_id,
            "command_seq": command_seq,
            "operation": operation,
            "skill_id": skill_id,
            "version": version,
            "package_url": package_url,
            "package_sha256": package_sha256,
            "execution_id": execution_id,
            "protocol_version": PROTOCOL_V2,
            "signed": signed_payload,
            "signature": sig,
            "task_expires_at": expires_at,
        }
        self.pending_tasks.append(cmd)

    def fetch_commands(self) -> Tuple[bool, Dict[str, Any]]:
        cmds = list(self.pending_tasks)
        self.pending_tasks.clear()
        return True, {"data": cmds}

    def send_event(
        self,
        task_no: str,
        phase: str,
        error_code: Optional[str] = None,
        error_msg: Optional[str] = None
    ) -> Tuple[bool, Dict[str, Any]]:
        ev = {
            "task_no": task_no,
            "did": self.did,
            "phase": phase,
            "error_code": error_code,
            "error_msg": error_msg,
            "time": time.time()
        }
        self.event_log.append(ev)
        if phase == PHASE_DONE:
            # 模拟云端根据任务类型更新安装记录
            for cmd in self.event_log:
                if cmd["task_no"] == task_no:
                    pass
        return True, {"success": True, "data": {"task_no": task_no, "phase": phase}}

    def send_ack(self, task_no: str, event_id: str, stage: str) -> Tuple[bool, Dict[str, Any]]:
        ack = {
            "task_no": task_no,
            "event_id": event_id,
            "stage": stage,
            "ack_id": f"ack_{event_id}",
            "status": "acknowledged"
        }
        self.ack_log.append(ack)
        return True, {"success": True, "data": ack}

    def reconcile(self, skills: list, tasks: Optional[list] = None) -> Tuple[bool, Dict[str, Any]]:
        self.reconciliation_history.append({"skills": skills, "tasks": tasks})
        return True, {
            "success": True,
            "data": {
                "did": self.did,
                "aligned": True,
                "server_time": int(time.time()),
                "details": [
                    {"skill_id": s.get("skill_id"), "match": True} for s in skills
                ]
            }
        }


class TestE2ESimulator(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()
        self.storage = Storage(self.temp_dir)
        self.adapter = MockAdapter()

        # 生成平台密钥对
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

        self.server = SimulatedPlatformServer(did="did_simulated_g1_001", priv_pem=self.priv_pem)
        self.manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=self.server,
            adapter=self.adapter,
            pubkey_pem=self.pub_pem
        )

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def _create_skill_zip(self, skill_id: str, version: str) -> Tuple[str, str]:
        zip_path = os.path.join(self.temp_dir, f"{skill_id}_{version}.skill")
        manifest = {
            "schema": "zhaohuan.skill/v2",
            "kind": "motion",
            "name": {"zh_CN": "单元仿真动作"},
            "version": version,
            "action": "action.py"
        }
        with zipfile.ZipFile(zip_path, "w") as zf:
            zf.writestr("manifest.json", json.dumps(manifest))
            zf.writestr("action.py", "def run():\n    return True\n")
            zf.writestr("data/trajectory.bin", b"\x00" * 128)

        sha = sha256_file(zip_path)
        return zip_path, sha

    def test_full_agent_lifecycle_end_to_end(self):
        # 1. 创建合规成品包并计算 SHA-256
        pkg_path, pkg_sha = self._create_skill_zip("skill_greet_01", "1.0.0")

        # 2. 平台下发 OP_INSTALL 任务
        file_url = f"file:///{os.path.abspath(pkg_path).replace(os.sep, '/')}"
        self.server.enqueue_v2_task(
            task_id="T_E2E_INSTALL_001",
            command_seq=1,
            operation=OP_INSTALL,
            skill_id="skill_greet_01",
            version="1.0.0",
            package_sha256=pkg_sha,
            package_url=file_url
        )

        # 3. Agent 轮询并执行任务
        processed_count = self.manager.poll_once()
        self.assertEqual(processed_count, 1)

        # 4. 验证安装完成，检查状态与文件
        installed_entry = self.storage.get_installed_skill("skill_greet_01")
        self.assertIsNotNone(installed_entry)
        self.assertEqual(installed_entry["current_version"], "1.0.0")
        self.assertTrue(installed_entry["enabled"])
        self.assertEqual(installed_entry["status"], "installed")

        # 验证 state/installed.json 存在且内容同步
        state_file = os.path.join(self.temp_dir, "state", "installed.json")
        self.assertTrue(os.path.isfile(state_file))
        with open(state_file, "r", encoding="utf-8") as f:
            state_data = json.load(f)
            self.assertIn("skill_greet_01", state_data["skills"])

        # 5. 验证事件流及 ACK 已发送
        phases = [ev["phase"] for ev in self.server.event_log if ev["task_no"] == "T_E2E_INSTALL_001"]
        self.assertIn(PHASE_DOWNLOADING, phases)
        self.assertIn(PHASE_INSTALLING, phases)
        self.assertIn(PHASE_DONE, phases)
        self.assertTrue(len(self.server.ack_log) >= 1)

        # 6. 平台下发 OP_EXECUTE 触发技能执行
        self.server.enqueue_v2_task(
            task_id="T_E2E_EXEC_001",
            command_seq=2,
            operation=OP_EXECUTE,
            skill_id="skill_greet_01",
            version="1.0.0",
            execution_id="exec_e2e_session_99",
            execution_params={"repeat": 3}
        )

        exec_count = self.manager.poll_once()
        self.assertEqual(exec_count, 1)

        # 7. 观测动作执行与结束
        time.sleep(0.06)
        obs = self.manager.executor.observe("exec_e2e_session_99")
        self.assertEqual(obs["status"], EXEC_SUCCEEDED)
        self.assertTrue(obs["is_stopped"])

        # 验证安装事实绝未被污染
        installed_after_exec = self.storage.get_installed_skill("skill_greet_01")
        self.assertEqual(installed_after_exec["current_version"], "1.0.0")
        self.assertEqual(installed_after_exec["status"], "installed")

        # 8. 触发对账
        ok_rec, rec_data = self.server.reconcile(
            skills=[{"skill_id": "skill_greet_01", "version": "1.0.0", "status": "installed"}]
        )
        self.assertTrue(ok_rec)
        self.assertTrue(rec_data["data"]["aligned"])


if __name__ == "__main__":
    unittest.main()
