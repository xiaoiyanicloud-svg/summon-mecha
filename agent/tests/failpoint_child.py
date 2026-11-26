"""A4.1 故障注入子进程：在真实 TaskManager / Storage 上运行，命中 failpoint 时进程直接消失。

父进程（tests/test_failpoints.py）用它验证九条边界；本文件不是产品代码。

用法：
    python tests/failpoint_child.py <mode> <install_dir>
mode ∈ install | execute | event | start | recover
环境：
    ZHJJ_FAILPOINT=<name>   命中即 os._exit(86)
"""

import json
import os
import shutil
import sys
import time
import zipfile

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.constants import OP_EXECUTE, OP_INSTALL, PHASE_FAILED, PROTOCOL_V2
from zhjj_agent.crypto import canonical_v2, sign_data
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


class _SilentClient(AgentClient):
    def __init__(self, did="did_a4_child"):
        super().__init__("http://127.0.0.1:9", did=did)

    def send_event(self, task_no, phase, error_code=None, error_msg=None, event_id=None, **kwargs):
        return True, {"success": True, "data": {"task_no": task_no}}

    def send_ack(self, task_no, event_id, stage):
        return True, {"success": True, "data": {}}

    def fetch_commands(self):
        return True, {"data": []}

    def reconcile(self, skills, tasks=None, **kwargs):
        return True, {"success": True, "data": {"aligned": True}}


def build_manager(install_dir):
    storage = Storage(install_dir)
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    priv = key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=serialization.NoEncryption(),
    ).decode("ascii")
    pub = key.public_key().public_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PublicFormat.SubjectPublicKeyInfo,
    ).decode("ascii")
    manager = TaskManager(
        install_dir=install_dir,
        storage=storage,
        client=_SilentClient(),
        adapter=MockAdapter(app_dir=install_dir),
        pubkey_pem=pub,
    )
    return manager, storage, priv


def sign(priv_pem, signed):
    signature = sign_data(canonical_v2(signed), priv_pem)
    return {
        "task_id": signed["task_id"],
        "command_seq": signed["command_seq"],
        "operation": signed["operation"],
        "skill_id": signed["skill_id"],
        "version": signed["version"],
        "protocol_version": PROTOCOL_V2,
        "signed": signed,
        "signature": signature,
    }


def command(priv_pem, task_id, operation, extra=None):
    signed = {
        "protocol_version": PROTOCOL_V2,
        "task_id": task_id,
        "command_seq": 1,
        "did": "did_a4_child",
        "operation": operation,
        "skill_id": "100",
        "version": "1.0.0",
        "task_expires_at": int(time.time()) + 3600,
    }
    signed.update(extra or {})
    cmd = sign(priv_pem, signed)
    return cmd


def make_package(install_dir):
    path = os.path.join(install_dir, "pkg.skill")
    with zipfile.ZipFile(path, "w") as zf:
        zf.writestr("manifest.json", json.dumps({"version": "1.0.0"}))
        zf.writestr("action.py", "# a4 failpoint")
    return path


def run(mode, install_dir):
    manager, storage, priv = build_manager(install_dir)

    if mode == "start":
        manager.mark_agent_start()
        return

    if mode == "install":
        package = make_package(install_dir)
        cmd = command(priv, "T_A4_FP", OP_INSTALL, {"package_sha256": __import__("hashlib").sha256(open(package, "rb").read()).hexdigest()})
        cmd["package_url"] = "file:///" + package.replace(os.sep, "/")
        manager.process_command(cmd)
        return

    if mode == "execute":
        package = make_package(install_dir)
        import hashlib
        cmd = command(priv, "T_A4_FP_INSTALL", OP_INSTALL, {"package_sha256": hashlib.sha256(open(package, "rb").read()).hexdigest()})
        cmd["package_url"] = "file:///" + package.replace(os.sep, "/")
        assert manager.process_command(cmd), "安装前置失败"

        exec_cmd = command(priv, "T_A4_FP_EXEC", OP_EXECUTE, {"execution_id": "exec_a4_fp", "execution_params": {}, "task_expires_at": int(time.time()) + 3600})
        manager.process_command(exec_cmd)
        return

    if mode == "event":
        manager.emit_event("T_A4_EVT", PHASE_FAILED, "E_FAILPOINT", "故障注入事件")
        return

    if mode == "recover":
        with storage._connection() as conn:
            unacked_before = int(conn.execute("SELECT COUNT(*) FROM outbox WHERE acked = 0").fetchone()[0])

        cleaned = manager.mark_agent_start()["staging_orphans_cleaned"]
        manager.resume_recovery()
        tasks = []
        for row in storage.get_uncompleted_tasks():
            tasks.append({"task_id": row["task_id"], "status": row["status"], "operation": row["operation"]})
        executions = [
            {"execution_id": e["execution_id"], "status": e["status"]}
            for e in storage.get_all_executions()
        ]
        leases = []
        for skill_id in ("100",):
            leases.extend(
                {"execution_id": l["execution_id"], "status": l["status"]}
                for l in storage.get_active_leases_for_skill(skill_id)
            )
        with storage._connection() as conn:
            unacked = [
                {"event_id": r["event_id"], "task_id": r["task_id"]}
                for r in conn.execute("SELECT event_id, task_id FROM outbox WHERE acked = 0").fetchall()
            ]
        installed = storage.get_installed_skill("100")
        active_path = os.path.join(install_dir, "skills", "100", ".active.json")
        active = json.load(open(active_path, encoding="utf-8")) if os.path.isfile(active_path) else None

        print(json.dumps({
            "cleaned": cleaned,
            "tasks": tasks,
            "executions": executions,
            "leases": leases,
            "unacked": unacked,
            "unacked_before_flush": unacked_before,
            "installed": {"current_version": installed["current_version"], "status": installed["status"]} if installed else None,
            "active": active,
        }, ensure_ascii=False))
        return

    raise SystemExit(f"unknown mode: {mode}")


if __name__ == "__main__":
    mode = sys.argv[1]
    install_dir = sys.argv[2]
    os.makedirs(install_dir, exist_ok=True)
    run(mode, install_dir)
