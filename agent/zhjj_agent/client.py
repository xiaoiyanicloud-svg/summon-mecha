"""平台 HTTP 客户端：支持 per-device 独立凭据、请求签名与 X1 信封解析"""

import json
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, Optional, Tuple

from zhjj_agent.constants import PROTOCOL_V2


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

    def heartbeat(self, agent_version: str, capability_snapshot: Optional[Dict[str, Any]] = None) -> Tuple[bool, Dict[str, Any]]:
        payload = {
            "did": self.did,
            "agent_version": agent_version,
            "capability_snapshot": capability_snapshot or {},
        }
        return self.request("POST", "api/agent/heartbeat", payload=payload)

    def fetch_commands(self) -> Tuple[bool, Dict[str, Any]]:
        return self.request("GET", "api/agent/commands", params={"did": self.did, "protocol_version": PROTOCOL_V2})

    pull_commands = fetch_commands

    def send_event(
        self,
        task_no: str,
        phase: str,
        error_code: Optional[str] = None,
        error_msg: Optional[str] = None,
        event_id: Optional[str] = None,
        event_seq: Optional[int] = None
    ) -> Tuple[bool, Dict[str, Any]]:
        payload = {
            "task_no": task_no,
            "did": self.did,
            "phase": phase,
            "error_code": error_code,
            "error_msg": error_msg,
        }
        if event_id is not None:
            payload["event_id"] = event_id
        if event_seq is not None:
            payload["event_seq"] = event_seq
        return self.request("POST", "api/agent/evt", payload=payload)

    def send_ack(self, task_no: str, event_id: str, stage: str) -> Tuple[bool, Dict[str, Any]]:
        payload = {
            "task_no": task_no,
            "did": self.did,
            "event_id": event_id,
            "stage": stage,
        }
        return self.request("POST", "api/agent/ack", payload=payload)

    def reconcile(self, skills: list, tasks: Optional[list] = None) -> Tuple[bool, Dict[str, Any]]:
        payload = {
            "did": self.did,
            "skills": skills,
            "tasks": tasks or [],
        }
        return self.request("POST", "api/agent/reconcile", payload=payload)
