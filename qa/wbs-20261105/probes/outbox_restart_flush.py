"""A1.9 flush：**新进程**打开同一 outbox 重投，验证签名字节未被重签。"""

import json
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "..", "agent")))

from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager

work_dir = sys.argv[1]

with open(os.path.join(os.path.dirname(__file__), "..", "jcs-vectors.json"), "r", encoding="utf-8") as f:
    fixture = json.load(f)

storage = Storage(work_dir)

captured = {}


def send_event(**kwargs):
    captured.update(kwargs.get("extra") or {})
    captured["_event_id"] = kwargs.get("event_id")
    return True, {"success": True, "data": {"event_id": kwargs.get("event_id")}}


# 关键：新进程没有设备私钥，只靠 outbox 的持久化字节重投。
client = AgentClient(server_url="https://example.test", did="did_restart")
client.send_event = send_event

manager = TaskManager(
    install_dir=work_dir,
    storage=storage,
    client=client,
    adapter=MockAdapter(),
    pubkey_pem=fixture["public_key_pem"],
)

manager.flush_outbox()

events = storage.get_unacked_events()
envelope = captured.get("fact_envelope") or {}

print(json.dumps({
    "event_id": captured.get("_event_id"),
    "signature": envelope.get("signature"),
    "key_id": envelope.get("key_id"),
    "unacked": len(events),
}))
