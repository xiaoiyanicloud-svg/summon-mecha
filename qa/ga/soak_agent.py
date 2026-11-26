#!/usr/bin/env python3
"""30-minute Agent soak: signed v2 install -> execute -> uninstall over local HTTP.

Software soak only; PASS cannot be promoted to SIL/HIL/GA.
"""

from __future__ import annotations
import argparse, ctypes, ctypes.wintypes, hashlib, json, os, pathlib, statistics, sys, tempfile, threading, time, urllib.parse, urllib.request, zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, List

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "agent"))
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.client import AgentClient
from zhjj_agent.constants import OP_EXECUTE, OP_INSTALL, OP_UNINSTALL, PROTOCOL_V2
from zhjj_agent.crypto import canonical_v2, sign_data
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager

class State:
    def __init__(self):
        self.lock = threading.Lock()
        self.commands: List[Dict[str, Any]] = []
        self.events: List[Dict[str, Any]] = []
        self.acks: List[Dict[str, Any]] = []
        self.package_path = ""
STATE = State()

class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def _json(self, status: int, payload: Dict[str, Any]) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
    def do_GET(self) -> None:
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path == "/api/agent/commands":
            with STATE.lock:
                cmd = STATE.commands.pop(0) if STATE.commands else None
            self._json(200, {"success": True, "data": [cmd] if cmd else []})
            return
        if parsed.path == "/package.skill":
            data = pathlib.Path(STATE.package_path).read_bytes()
            self.send_response(200)
            self.send_header("Content-Type", "application/octet-stream")
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(data)
            return
        self._json(404, {"success": False, "code": "NOT_FOUND"})
    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length) if length else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8"))
        except json.JSONDecodeError:
            self._json(400, {"success": False, "code": "INVALID_JSON"})
            return
        if self.path == "/api/agent/evt":
            with STATE.lock:
                STATE.events.append(payload)
            self._json(200, {"success": True, "data": {"event_id": payload.get("event_id"), "ack_id": "ack_" + hashlib.sha256(str(payload.get("event_id")).encode()).hexdigest()[:24], "duplicate": False, "applied": True}})
            return
        if self.path == "/api/agent/ack":
            with STATE.lock:
                STATE.acks.append(payload)
            self._json(200, {"success": True, "data": {"ack_id": "mut_" + str(payload.get("event_id", ""))}})
            return
        if self.path == "/api/agent/reconcile":
            self._json(200, {"success": True, "data": {"aligned": False, "drift_count": 1}})
            return
        if self.path == "/api/agent/heartbeat":
            self._json(200, {"success": True, "data": {"online": True}})
            return
        self._json(404, {"success": False, "code": "NOT_FOUND"})
    def log_message(self, fmt: str, *args: Any) -> None:
        return

def sign_command(private_pem: str, public_pem: str, did: str, task_id: str, op: int, skill_id: int, version_id: int, package_sha: str = "", execution_id: str | None = None, package_url: str = "") -> Dict[str, Any]:
    now = int(time.time())
    signed: Dict[str, Any] = {
        "protocol": PROTOCOL_V2, "protocol_version": PROTOCOL_V2,
        "signature_algorithm": "RSA-SHA256",
        "key_id": hashlib.sha256(public_pem.strip().encode("utf-8")).hexdigest(),
        "task_id": task_id, "command_seq": 1, "did": did,
        "operation": op, "skill_id": str(skill_id), "skill_version_id": str(version_id),
        "version": "1.0.0", "package_sha256": package_sha, "model_code": "mock",
        "target_profile_digest": "sha256:" + "a" * 64,
        "nonce": "nonce_" + hashlib.sha256(f"{task_id}:{time.time_ns()}".encode()).hexdigest()[:32],
        "issued_at": now, "expires_at": now + 3600, "task_expires_at": now + 3600,
        "revocation_epoch": 0, "authorization_not_before": now - 10,
        "authorization_expires_at": now + 3600,
    }
    if execution_id:
        signed.update({"execution_id": execution_id, "execution_params": {}, "execution_deadline": now + 10, "plan_digest": "plan_" + execution_id})
    signature = sign_data(canonical_v2(signed), private_pem)
    return {"task_id": task_id, "command_seq": 1, "did": did, "operation": op, "skill_id": str(skill_id), "version": "1.0.0", "protocol_version": PROTOCOL_V2, "package_sha256": package_sha, "package_url": package_url, "execution_id": execution_id, "signed": signed, "signature": signature, "signature_v2": signature}

def memory_bytes() -> int:
    if os.name != "nt": return 0
    class PMC(ctypes.Structure):
        _fields_ = [("cb", ctypes.wintypes.DWORD), ("PageFaultCount", ctypes.wintypes.DWORD), ("PeakWorkingSetSize", ctypes.c_size_t), ("WorkingSetSize", ctypes.c_size_t), ("QuotaPeakPagedPoolUsage", ctypes.c_size_t), ("QuotaPagedPoolUsage", ctypes.c_size_t), ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t), ("QuotaNonPagedPoolUsage", ctypes.c_size_t), ("PagefileUsage", ctypes.c_size_t), ("PeakPagefileUsage", ctypes.c_size_t)]
    counters = PMC(); counters.cb = ctypes.sizeof(counters)
    kernel32 = ctypes.windll.kernel32
    kernel32.GetCurrentProcess.restype = ctypes.wintypes.HANDLE
    process = kernel32.GetCurrentProcess()
    psapi = ctypes.windll.psapi
    psapi.GetProcessMemoryInfo.argtypes = [ctypes.wintypes.HANDLE, ctypes.POINTER(PMC), ctypes.wintypes.DWORD]
    psapi.GetProcessMemoryInfo.restype = ctypes.wintypes.BOOL
    if not psapi.GetProcessMemoryInfo(process, ctypes.byref(counters), counters.cb): raise ctypes.WinError()
    return int(counters.WorkingSetSize)

def handle_count() -> int:
    if os.name != "nt": return -1
    count = ctypes.wintypes.DWORD()
    kernel32 = ctypes.windll.kernel32
    kernel32.GetCurrentProcess.restype = ctypes.wintypes.HANDLE
    process = kernel32.GetCurrentProcess()
    kernel32.GetProcessHandleCount.argtypes = [ctypes.wintypes.HANDLE, ctypes.POINTER(ctypes.wintypes.DWORD)]
    kernel32.GetProcessHandleCount.restype = ctypes.wintypes.BOOL
    if not kernel32.GetProcessHandleCount(process, ctypes.byref(count)): raise ctypes.WinError()
    return int(count.value)

def count_files(root: pathlib.Path) -> int:
    return sum(1 for p in root.rglob("*") if p.is_file()) if root.exists() else 0

def build_package(path: pathlib.Path) -> str:
    manifest = {"schema": "zhaohuan.skill/v2", "name": {"zh_CN": "soak", "en": "soak"}, "version": "1.0.0", "kind": "composite", "entrypoint": "action.py", "target_model_code": "mock", "runtime": {"source_format": "btcpp/xml4", "target_model": "mock", "target_profile_digest": "sha256:" + "a" * 64}}
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("manifest.json", json.dumps(manifest))
        zf.writestr("action.py", "def run(context=None):\n    return {'status': 'SUCCEEDED', 'stopped': True}\n")
    return hashlib.sha256(path.read_bytes()).hexdigest()

def p95(values: List[float]) -> float:
    if not values: return 0.0
    ordered = sorted(values)
    return ordered[int(0.95 * (len(ordered) - 1))]

def main(argv: List[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--duration-seconds", type=int, default=1800)
    parser.add_argument("--interval-seconds", type=float, default=2.0)
    parser.add_argument("--sample-seconds", type=float, default=10.0)
    parser.add_argument("--output", type=pathlib.Path)
    parser.add_argument("--timeline", type=pathlib.Path)
    args = parser.parse_args(argv)
    if args.duration_seconds <= 0 or args.interval_seconds <= 0: parser.error("duration and interval must be positive")

    temp_root = pathlib.Path(tempfile.mkdtemp(prefix="ga_soak_"))
    install_dir = temp_root / "agent"; install_dir.mkdir()
    package_path = temp_root / "package.skill"; package_sha = build_package(package_path); STATE.package_path = str(package_path)
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    private_pem = key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()).decode()
    public_pem = key.public_key().public_bytes(serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo).decode()
    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler); server_thread = threading.Thread(target=server.serve_forever, daemon=True); server_thread.start()
    host, port = server.server_address[:2]; base_url = f"http://{host}:{port}"
    storage = Storage(str(install_dir)); adapter = MockAdapter(app_dir=str(install_dir)); client = AgentClient(base_url, did="did_soak_001", agent_key="soak-key", timeout=10); manager = TaskManager(str(install_dir), storage, client, adapter, public_pem)
    start = time.monotonic(); next_cycle = start; next_sample = start; latencies: List[float] = []; timeline: List[Dict[str, Any]] = []; errors: List[str] = []; cycles = 0
    baseline_memory = memory_bytes(); baseline_handles = handle_count(); baseline_files = count_files(temp_root)
    try:
        while time.monotonic() - start < args.duration_seconds:
            cycle_start = time.monotonic(); cycle = cycles + 1; skill_id = 900000 + cycle; version_id = 800000 + cycle; execution_id = f"soak_exec_{cycle}"
            try:
                commands = [sign_command(private_pem, public_pem, client.did, f"soak_install_{cycle}", OP_INSTALL, skill_id, version_id, package_sha, package_url=base_url + "/package.skill"), sign_command(private_pem, public_pem, client.did, f"soak_execute_{cycle}", OP_EXECUTE, skill_id, version_id, package_sha, execution_id), sign_command(private_pem, public_pem, client.did, f"soak_uninstall_{cycle}", OP_UNINSTALL, skill_id, version_id)]
                for command in commands:
                    with STATE.lock: STATE.commands.append(command)
                    processed = manager.poll_once()
                    if processed != 1: raise RuntimeError(f"cycle {cycle} command {command['task_id']} processed={processed}")
                cycles += 1; latencies.append((time.monotonic() - cycle_start) * 1000)
            except Exception as exc:
                errors.append(f"cycle {cycle}: {exc}")
            now = time.monotonic()
            if now >= next_sample:
                timeline.append({"elapsed_seconds": round(now - start, 3), "memory_mb": round(memory_bytes() / 1024 / 1024, 3), "handles": handle_count(), "threads": threading.active_count(), "files": count_files(temp_root), "events": len(STATE.events), "acks": len(STATE.acks), "cycles": cycles})
                next_sample = now + args.sample_seconds
            next_cycle += args.interval_seconds; sleep_for = next_cycle - time.monotonic()
            if sleep_for > 0: time.sleep(sleep_for)
    finally:
        server.shutdown(); server.server_close(); server_thread.join(timeout=5)
    final_memory = memory_bytes(); final_handles = handle_count(); final_files = count_files(temp_root)
    memory_values = [row["memory_mb"] for row in timeline] or [baseline_memory / 1024 / 1024]; handle_values = [row["handles"] for row in timeline] or [baseline_handles]; thread_values = [row["threads"] for row in timeline] or [threading.active_count()]; file_values = [row["files"] for row in timeline] or [baseline_files]
    result = {"evidence_level": "SOFTWARE_SOAK_ONLY", "requested_duration_seconds": args.duration_seconds, "actual_duration_seconds": round(time.monotonic() - start, 3), "cycles": cycles, "commands": cycles * 3, "errors": errors, "latency_ms": {"p50": round(statistics.median(latencies), 3) if latencies else None, "p95": round(p95(latencies), 3), "max": round(max(latencies), 3) if latencies else None}, "samples": len(timeline), "memory_mb": {"baseline": round(baseline_memory / 1024 / 1024, 3), "final": round(final_memory / 1024 / 1024, 3), "peak": round(max(memory_values), 3), "growth": round(final_memory / 1024 / 1024 - baseline_memory / 1024 / 1024, 3), "threshold_growth": 64}, "handles": {"baseline": baseline_handles, "final": final_handles, "peak": max(handle_values), "growth": final_handles - baseline_handles, "threshold_growth": 128}, "threads": {"peak": max(thread_values), "threshold": 32}, "temp_files": {"baseline": baseline_files, "final": final_files, "peak": max(file_values), "threshold_growth": 20}}
    result["passed"] = (not errors and cycles > 0 and result["memory_mb"]["growth"] < result["memory_mb"]["threshold_growth"] and result["handles"]["growth"] <= result["handles"]["threshold_growth"] and result["threads"]["peak"] <= result["threads"]["threshold"] and final_files - baseline_files <= result["temp_files"]["threshold_growth"])
    output = json.dumps(result, ensure_ascii=False, indent=2)
    if args.output: args.output.parent.mkdir(parents=True, exist_ok=True); args.output.write_text(output + "\n", encoding="utf-8")
    if args.timeline: args.timeline.parent.mkdir(parents=True, exist_ok=True); args.timeline.write_text("\n".join(json.dumps(row, ensure_ascii=False) for row in timeline) + "\n", encoding="utf-8")
    print(output)
    return 0 if result["passed"] else 1

if __name__ == "__main__": raise SystemExit(main())