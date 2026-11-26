"""生成 A1 agent-fact/v3 跨语言黄金向量（仅测试用固定密钥）。"""

import json
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "..", "agent")))

from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives import serialization

from zhjj_agent.crypto import canonical_fact_v3, fact_key_id, public_key_pem_from_private

key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
priv_pem = key.private_bytes(
    encoding=serialization.Encoding.PEM,
    format=serialization.PrivateFormat.PKCS8,
    encryption_algorithm=serialization.NoEncryption(),
).decode("ascii")
pub_pem = public_key_pem_from_private(priv_pem)

signed = {
    "protocol": "agent-fact/v3",
    "event_id": "evt_v3_golden_0001",
    "did": "did_v3_golden",
    "task_no": "T-V3-GOLDEN",
    "event_seq": 7,
    "phase": "done",
    "execution_fact": {
        "execution_id": "exec_v3_golden",
        "observed_status": "SUCCEEDED",
        "actual_started": True,
        "actual_stopped": True,
        "started_at": 1700000000,
        "stopped_at": 1700000002,
        "velocity": 1e-6,
        "current": -0.0,
        "unicode": "召唤机甲",
        "nested": {"z": 1, "a": [3, 2, 1]},
    },
    "evidence": {"observer": "synthetic-test-only", "seq": 1},
    "reported_at": 1700000003,
}

canonical = canonical_fact_v3(signed).decode("utf-8")

doc = {
    "wbs": "A1",
    "note": "synthetic-test-only RSA key; never use in production",
    "protocol": "agent-fact/v3",
    "private_key_pem": priv_pem,
    "public_key_pem": pub_pem,
    "expected_key_id": fact_key_id(priv_pem),
    "signed": signed,
    "expected_canonical": canonical,
}

out = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "jcs-vectors.json"))
with open(out, "w", encoding="utf-8") as f:
    json.dump(doc, f, ensure_ascii=False, indent=2)
    f.write("\n")

print(out)
print("expected_key_id=", doc["expected_key_id"])
print("expected_canonical=", canonical)
