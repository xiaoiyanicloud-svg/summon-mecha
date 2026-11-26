"""A1.9 setup：进程内产生事件并断网留在 outbox，打印持久化的 event_id/签名。"""

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
client = AgentClient(
    server_url="https://example.test",
    did="did_restart",
    private_key_pem=fixture["private_key_pem"],
)
client.send_event = lambda **kwargs: (False, {"code": "NETWORK_DOWN"})

manager = TaskManager(
    install_dir=work_dir,
    storage=storage,
    client=client,
    adapter=MockAdapter(),
    pubkey_pem=fixture["public_key_pem"],
)

manager.emit_event(
    "T_RESTART",
    "done",
    execution_fact={"execution_id": "exec_restart", "observed_status": "SUCCEEDED"},
)

events = storage.get_unacked_events()
assert len(events) == 1, events
payload = events[0]["payload"]

print(json.dumps({
    "event_id": payload["event_id"],
    "signature": payload["fact_envelope"]["signature"],
    "key_id": payload["fact_envelope"]["key_id"],
    "phase": payload["phase"],
}))
