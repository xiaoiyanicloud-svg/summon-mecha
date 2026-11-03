"""
真实 Laravel HTTP 服务端云端到端集成测试
通过独立端口启动真正的 Laravel HTTP 服务，连接独立测试数据库 summon_mecha_step10_test。
使用 Python Agent 的真实 AgentClient 进行全链路网络调用：
握手心跳 -> 拉取指令 -> 签名校验 -> 状态流转 -> 事件回报 -> 双向ACK -> 事实对账。
测试结束后优雅清理测试进程与临时数据，不留残余。
"""

import json
import os
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.request
from typing import Any, Dict

from zhjj_agent.client import AgentClient
from zhjj_agent.constants import (
    OP_ENABLE,
    OP_DISABLE,
    PHASE_DONE,
    PHASE_INSTALLING,
    PROTOCOL_V2,
)
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


ROOT_DIR = pathlib.Path(__file__).resolve().parent.parent.parent
LARAVEL_DIR = ROOT_DIR / "my-bagisto-store"


def find_free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class TestLiveHttpIntegration(unittest.TestCase):
    server_proc = None
    port = None
    base_url = None
    did = None
    device_secret = None
    pubkey_pem = None
    task_no = None

    @classmethod
    def setUpClass(cls):
        cls.port = find_free_port()
        cls.base_url = f"http://127.0.0.1:{cls.port}"

        # 启动隔离端口的真实 PHP 服务
        env = os.environ.copy()
        env["APP_ENV"] = "testing"
        env["DB_DATABASE"] = "summon_mecha_step10_test"

        cls.server_proc = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{cls.port}", "-t", "public"],
            cwd=str(LARAVEL_DIR),
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )

        # 等待服务就绪（最长 6 秒）
        ready = False
        start_wait = time.time()
        while time.time() - start_wait < 6.0:
            try:
                with urllib.request.urlopen(f"{cls.base_url}/api/agent/commands", timeout=1.0) as resp:
                    ready = True
                    break
            except urllib.error.HTTPError as e:
                # 401 或 422 均表示 Laravel 路由与框架已就绪
                ready = True
                break
            except Exception:
                time.sleep(0.2)

        if not ready:
            cls.server_proc.terminate()
            raise RuntimeError(f"Laravel HTTP server on port {cls.port} failed to start")

        # 在 summon_mecha_step10_test 中初始化测试设备、凭据与任务
        setup_script = """
        $did = 'live_http_' . uniqid();
        $binding = Webkul\\Shop\\Models\\DeviceBinding::create([
            'did' => $did,
            'sn' => 'SN_' . $did,
            'source' => 'agent',
            'bind_status' => 'bound',
            'bound_at' => now(),
        ]);
        $cred = app(Webkul\\Shop\\Services\\Deploy\\DeviceCredentialService::class)->issue($binding);
        $secret = $cred['secret'];
        
        // 创建一条 OP_ENABLE 任务
        $task = Webkul\\Shop\\Models\\DeployTask::create([
            'task_no' => 'LIVE_' . uniqid(),
            'did' => $did,
            'skill_id' => 9999,
            'operation' => Webkul\\Shop\\Models\\DeployTask::OP_ENABLE,
            'protocol_version' => Webkul\\Shop\\Models\\DeployTask::PROTOCOL_V2,
            'command_seq' => 1,
            'status' => 'dispatching',
            'payload' => [],
        ]);

        $pubkey = app(Webkul\\Shop\\Services\\Deploy\\SigningService::class)->publicKeyPem();

        echo json_encode([
            'did' => $did,
            'secret' => $secret,
            'pubkey_pem' => $pubkey,
            'task_no' => $task->task_no,
        ]);
        """
        res = subprocess.run(
            ["php", "artisan", "tinker", "--execute", setup_script],
            cwd=str(LARAVEL_DIR),
            env=env,
            capture_output=True,
            text=True
        )
        if res.returncode != 0:
            cls.server_proc.terminate()
            raise RuntimeError(f"Database setup via tinker failed: {res.stderr}")

        # 解析输出中的 JSON
        out_lines = [line.strip() for line in res.stdout.splitlines() if line.strip().startswith("{")]
        if not out_lines:
            cls.server_proc.terminate()
            raise RuntimeError(f"Failed to parse setup json from output: {res.stdout}")

        data = json.loads(out_lines[-1])
        cls.did = data["did"]
        cls.device_secret = data["secret"]
        cls.pubkey_pem = data["pubkey_pem"]
        cls.task_no = data["task_no"]

    @classmethod
    def tearDownClass(cls):
        if cls.server_proc:
            cls.server_proc.terminate()
            try:
                cls.server_proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                cls.server_proc.kill()

    def setUp(self):
        self.temp_dir = tempfile.mkdtemp(prefix="zhjj_live_")
        self.storage = Storage(self.temp_dir)
        self.adapter = MockAdapter(app_dir=self.temp_dir)
        self.client = AgentClient(
            server_url=self.base_url,
            did=self.did,
            device_secret=self.device_secret,
            timeout=10
        )
        self.manager = TaskManager(
            install_dir=self.temp_dir,
            storage=self.storage,
            client=self.client,
            adapter=self.adapter,
            pubkey_pem=self.pubkey_pem
        )

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_live_01_heartbeat(self):
        """测试 1: 真实 HTTP POST /api/agent/heartbeat 心跳刷新"""
        ok, res = self.client.heartbeat(
            agent_version="0.2.0",
            capability_snapshot={"model_code": "unitree_g1", "firmware_version": "1.0.0"}
        )
        self.assertTrue(ok, f"Heartbeat failed: {res}")
        self.assertTrue(res.get("success"))
        self.assertEqual(res.get("data", {}).get("did"), self.did)
        self.assertTrue(res.get("data", {}).get("online"))

    def test_live_02_fetch_and_ack_command(self):
        """测试 2: 真实 HTTP GET /api/agent/commands 拉取指令 -> POST /api/agent/ack 双向 ACK"""
        ok, res = self.client.fetch_commands()
        self.assertTrue(ok, f"Fetch commands failed: {res}")
        self.assertTrue(res.get("success"))
        commands = res.get("data", [])
        self.assertIsInstance(commands, list)
        self.assertGreaterEqual(len(commands), 1)

        cmd = commands[0]
        self.assertEqual(cmd.get("task_id"), self.task_no)
        self.assertEqual(cmd.get("operation"), OP_ENABLE)
        self.assertIn("signature", cmd)
        self.assertIn("signed", cmd)

        # 发送持久化 ACK
        ok_ack, res_ack = self.client.send_ack(
            task_no=self.task_no,
            event_id="evt_init_1",
            stage="received_durable"
        )
        self.assertTrue(ok_ack, f"Send ACK failed: {res_ack}")
        self.assertEqual(res_ack.get("data", {}).get("status"), "acknowledged")

    def test_live_03_send_event(self):
        """测试 3: 真实 HTTP POST /api/agent/evt 上报任务进度事件"""
        # 上报 installing 阶段
        ok, res = self.client.send_event(
            task_no=self.task_no,
            phase=PHASE_INSTALLING
        )
        self.assertTrue(ok, f"Event installing failed: {res}")
        self.assertTrue(res.get("success"))
        self.assertEqual(res.get("data", {}).get("task_no"), self.task_no)
        self.assertEqual(res.get("data", {}).get("status"), "installing")

        # 上报 done 完成阶段
        ok_done, res_done = self.client.send_event(
            task_no=self.task_no,
            phase=PHASE_DONE
        )
        self.assertTrue(ok_done, f"Event done failed: {res_done}")
        self.assertEqual(res_done.get("data", {}).get("status"), "success")

    def test_live_04_reconciliation(self):
        """测试 4: 真实 HTTP POST /api/agent/reconcile 双向事实对账"""
        skills = [
            {"skill_version_id": 9999, "status": "installed"}
        ]
        tasks = [
            {"task_no": self.task_no, "status": "COMMITTED"}
        ]
        ok, res = self.client.reconcile(skills=skills, tasks=tasks)
        self.assertTrue(ok, f"Reconcile failed: {res}")
        self.assertTrue(res.get("success"))
        data = res.get("data", {})
        self.assertEqual(data.get("did"), self.did)
        self.assertIn("aligned", data)
        self.assertIn("details", data)


if __name__ == "__main__":
    unittest.main()
