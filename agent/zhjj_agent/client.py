"""平台 HTTP 客户端：支持 per-device 独立凭据、请求签名与 X1 信封解析"""

import json
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Any, Dict, Optional, Tuple

from zhjj_agent.constants import PROTOCOL_V2, PROTOCOL_FACT_V3


class AgentClient:
    def __init__(
        self,
        server_url: str,
        did: str,
        device_secret: Optional[str] = None,
        private_key_pem: Optional[str] = None,
        agent_key: Optional[str] = None,
        timeout: int = 15
    ):
        self.server_url = server_url.rstrip("/")
        self.did = did
        self.device_secret = device_secret
        self.private_key_pem = private_key_pem
        self.agent_key = agent_key
        self.timeout = timeout

        # 事件正文 v3 签名（WBS A1）：设备私钥 → 公钥指纹 key_id。
        self.fact_key_id: Optional[str] = None
        if self.private_key_pem:
            from zhjj_agent.crypto import fact_key_id

            self.fact_key_id = fact_key_id(self.private_key_pem)

    def build_fact_envelope(
        self,
        payload: Dict[str, Any],
        execution_fact: Optional[Dict[str, Any]] = None,
    ) -> Optional[Dict[str, Any]]:
        """构造并签名 agent-fact/v3 信封；无设备私钥时返回 None（历史共享密钥模式）。

        签名域固定为 event_id/did/task_no/event_seq/phase/execution_fact/evidence/reported_at，
        覆盖的是事件正文本身，而不是只签 method/path/timestamp。
        """
        if not self.private_key_pem or not self.fact_key_id:
            return None

        from zhjj_agent.crypto import canonical_fact_v3, sign_data

        signed = {
            "protocol": PROTOCOL_FACT_V3,
            "event_id": payload.get("event_id"),
            "did": payload.get("did"),
            "task_no": payload.get("task_no"),
            "event_seq": payload.get("event_seq"),
            "phase": payload.get("phase"),
            "execution_fact": execution_fact,
            "evidence": payload.get("evidence") or {},
            "reported_at": payload.get("reported_at"),
        }

        signature = sign_data(canonical_fact_v3(signed), self.private_key_pem)

        return {**signed, "signature": signature, "key_id": self.fact_key_id}

    def _build_auth_headers(self, method: str, path: str) -> Dict[str, str]:
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-Agent-Protocol": PROTOCOL_V2,
        }

        # 优先 1：证书私钥请求签名（目标形态）
        if self.private_key_pem:
            ts = str(int(time.time()))
            from zhjj_agent.crypto import sign_data
            canonical = f"{self.did}|{ts}|{method.upper()}|{path.lstrip('/')}"
            sig_b64 = sign_data(canonical.encode("utf-8"), self.private_key_pem)
            headers["X-Agent-Did"] = self.did
            headers["X-Agent-Signature"] = sig_b64
            headers["X-Agent-Ts"] = ts
            return headers

        # 优先 2：per-device bearer secret（独立凭据）
        if self.device_secret:
            headers["X-Agent-Did"] = self.did
            headers["X-Agent-Secret"] = self.device_secret
            return headers

        # 回退：共享密钥（仅联调/过渡）
        if self.agent_key:
            headers["X-Agent-Key"] = self.agent_key
            return headers

        return headers

    def get_download_headers(self, url: str) -> Dict[str, str]:
        """为技能包下载生成认证请求头。
        安全限制：仅允许发往平台源站，绝不向第三方主机泄露设备凭据。
        """
        parsed = urllib.parse.urlparse(url)
        server_parsed = urllib.parse.urlparse(self.server_url)

        # 严格同源校验 (同一 netloc 与 scheme)
        if parsed.netloc and parsed.netloc.lower() == server_parsed.netloc.lower():
            headers = self._build_auth_headers("GET", parsed.path)
            headers["Accept"] = "*/*"
            return headers

        return {"Accept": "*/*"}

    def request(
        self,
        method: str,
        endpoint: str,
        payload: Optional[Dict[str, Any]] = None,
        params: Optional[Dict[str, Any]] = None
    ) -> Tuple[bool, Dict[str, Any]]:
        """发起 HTTP 请求并解析 X1 信封 {success, code, message, data}。
        返回 (is_success, response_dict)。
        """
        path = endpoint.lstrip("/")
        url = f"{self.server_url}/{path}"
        if params:
            query = urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})
            if query:
                url = f"{url}?{query}"

        headers = self._build_auth_headers(method, path)
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8") if payload is not None else None

        req = urllib.request.Request(url, data=data, method=method.upper())
        for k, v in headers.items():
            req.add_header(k, v)

        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as r:
                body_str = r.read().decode("utf-8") or "{}"
                body = json.loads(body_str)
                success = body.get("success", True)
                return success, body
        except urllib.error.HTTPError as e:
            try:
                err_body = json.loads(e.read().decode("utf-8") or "{}")
            except Exception:
                err_body = {"code": f"HTTP_{e.code}", "message": str(e)}
            return False, err_body
        except Exception as e:
            return False, {"code": "NETWORK_ERROR", "message": str(e)}

    # ── 平台各端点快捷方法 ───────────────────────────────────────────────────

    def heartbeat(
        self,
        agent_version: str,
        capability_snapshot: Optional[Dict[str, Any]] = None,
        metrics: Optional[Dict[str, Dict[str, int]]] = None,
        health: Optional[Dict[str, Any]] = None,
    ) -> Tuple[bool, Dict[str, Any]]:
        payload = {
            "did": self.did,
            "agent_version": agent_version,
            "capability_snapshot": capability_snapshot or {},
        }
        # A3：增量计数随心跳上报。批次由 AgentMetrics 生成并持久化，
        # 失败重发用同一个 batch_id（平台按 (did,batch_id) 去重，不会重复累加）。
        if metrics:
            payload["metrics"] = metrics
        # A2：健康快照是**瞬时值**（覆盖语义），采不到就不带 —— 不编造 0。
        if health:
            payload["health"] = health
        return self.request("POST", "api/agent/heartbeat", payload=payload)

    def fetch_commands(self) -> Tuple[bool, Dict[str, Any]]:
        return self.request("GET", "api/agent/commands", params={"did": self.did, "protocol_version": PROTOCOL_V2})

    pull_commands = fetch_commands

    def check_entitlement(
        self,
        skill_id: Any,
        skill_version_id: Optional[int] = None,
    ) -> Tuple[bool, Dict[str, Any]]:
        """A5：执行前向平台回源确认本机对该技能仍持有有效授权。"""
        payload: Dict[str, Any] = {"did": self.did, "skill_id": int(skill_id)}

        if skill_version_id is not None:
            payload["skill_version_id"] = int(skill_version_id)

        return self.request("POST", "api/agent/entitlement/check", payload=payload)

    def fetch_desired_state(self) -> Tuple[bool, Dict[str, Any]]:
        """A4：拉取平台声明的期望技能集合与实际 drift。"""
        return self.request("GET", "api/agent/desired-state", params={"did": self.did})

    def send_event(
        self,
        task_no: str,
        phase: str,
        error_code: Optional[str] = None,
        error_msg: Optional[str] = None,
        event_id: Optional[str] = None,
        event_seq: Optional[int] = None,
        extra: Optional[Dict[str, Any]] = None,
    ) -> Tuple[bool, Dict[str, Any]]:
        payload = {
            "task_no": task_no,
            "did": self.did,
            "phase": phase,
            "error_code": error_code,
            "error_msg": error_msg,
            # 单次调用也必须可幂等；持久 Outbox 调用方应传入稳定 event_id。
            "event_id": event_id or f"evt_{uuid.uuid4().hex}",
        }
        if event_seq is not None:
            payload["event_seq"] = event_seq
        if extra:
            payload.update(extra)

        # A1/B 修补：手握设备私钥时，`send_event` 也必须自带 agent-fact/v3 信封。
        # 否则"直接调用 send_event"的通道（联调脚本、端到端测试）会被平台按
        # AGENT_FACT_SIGNATURE_REQUIRED 拒掉 —— 那是**对的**（平台不该为某条客户端路径开后门），
        # 所以这里统一在发出去之前签，而不是让调用方各签各的。
        if "fact_envelope" not in payload and self.private_key_pem:
            payload["reported_at"] = int(time.time())
            envelope = self.build_fact_envelope(payload, payload.get("execution_fact"))
            if envelope:
                payload["fact_envelope"] = envelope

        return self.request("POST", "api/agent/evt", payload=payload)

    def send_ack(self, task_no: str, event_id: str, stage: str) -> Tuple[bool, Dict[str, Any]]:
        payload = {
            "task_no": task_no,
            "did": self.did,
            "event_id": event_id,
            "stage": stage,
        }
        return self.request("POST", "api/agent/ack", payload=payload)

    def reconcile(
        self,
        skills: list,
        tasks: Optional[list] = None,
        model_code: Optional[str] = None,
        observed_generation: int = 0,
        active_executions: Optional[list] = None,
        outbox_cursor: Optional[str] = None,
        reported_at: Optional[int] = None,
    ) -> Tuple[bool, Dict[str, Any]]:
        if not model_code:
            raise ValueError("reconcile 必须提供 model_code，禁止用隐式默认机型")
        payload = {
            "did": self.did,
            "model_code": model_code,
            "observed_generation": int(observed_generation),
            "skills": skills,
            "tasks": tasks or [],
            "active_executions": active_executions or [],
            "outbox_cursor": outbox_cursor,
            "reported_at": int(reported_at or time.time()),
        }
        return self.request("POST", "api/agent/reconcile", payload=payload)
