"""500MB 流式下载内存增量基准。

真实启动本地 HTTP 服务并持续发送 500MiB，Agent PackageManager 以 64KiB 块落盘并计算 SHA-256。
Windows 下采样当前进程 WorkingSetSize；验收阈值为增量 < 64MiB。
"""

import ctypes
import ctypes.wintypes as wintypes
import json
import os
import pathlib
import socket
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'agent'))
from zhjj_agent.package_manager import PackageManager  # noqa: E402

SIZE = 500 * 1024 * 1024
CHUNK = b'\x5a' * (1024 * 1024)


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Type', 'application/octet-stream')
        self.send_header('Content-Length', str(SIZE))
        self.send_header('Connection', 'close')
        self.end_headers()
        remaining = SIZE
        try:
            while remaining > 0:
                data = CHUNK if remaining >= len(CHUNK) else CHUNK[:remaining]
                self.wfile.write(data)
                remaining -= len(data)
        except (BrokenPipeError, ConnectionResetError):
            return

    def log_message(self, fmt, *args):
        return


class PROCESS_MEMORY_COUNTERS(ctypes.Structure):
    _fields_ = [
        ('cb', wintypes.DWORD),
        ('PageFaultCount', wintypes.DWORD),
        ('PeakWorkingSetSize', ctypes.c_size_t),
        ('WorkingSetSize', ctypes.c_size_t),
        ('QuotaPeakPagedPoolUsage', ctypes.c_size_t),
        ('QuotaPagedPoolUsage', ctypes.c_size_t),
        ('QuotaPeakNonPagedPoolUsage', ctypes.c_size_t),
        ('QuotaNonPagedPoolUsage', ctypes.c_size_t),
        ('PagefileUsage', ctypes.c_size_t),
        ('PeakPagefileUsage', ctypes.c_size_t),
    ]


def working_set_bytes() -> int:
    if os.name != 'nt':
        return 0
    counters = PROCESS_MEMORY_COUNTERS()
    counters.cb = ctypes.sizeof(counters)
    kernel32 = ctypes.windll.kernel32
    kernel32.GetCurrentProcess.restype = wintypes.HANDLE
    process = kernel32.GetCurrentProcess()
    function = ctypes.windll.psapi.GetProcessMemoryInfo
    function.argtypes = [wintypes.HANDLE, ctypes.POINTER(PROCESS_MEMORY_COUNTERS), wintypes.DWORD]
    function.restype = wintypes.BOOL
    if not function(process, ctypes.byref(counters), counters.cb):
        raise ctypes.WinError(ctypes.get_last_error())
    return int(counters.WorkingSetSize)


class Server:
    def __init__(self):
        self.httpd = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.thread.start()

    @property
    def url(self):
        host, port = self.httpd.server_address[:2]
        return f'http://{host}:{port}/package.bin'

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


def main():
    server = Server()
    tmp_fd, tmp_path = tempfile.mkstemp(prefix='ga_stream_', suffix='.bin')
    os.close(tmp_fd)
    baseline = working_set_bytes()
    samples = [baseline]
    stop = threading.Event()

    def sampler():
        while not stop.wait(0.01):
            samples.append(working_set_bytes())

    sampler_thread = threading.Thread(target=sampler, daemon=True)
    sampler_thread.start()
    started = time.perf_counter()
    try:
        digest = PackageManager.download_package(server.url, tmp_path, max_bytes=SIZE)
        elapsed = time.perf_counter() - started
        stop.set()
        sampler_thread.join(timeout=2)
        final_size = os.path.getsize(tmp_path)
        peak = max(samples)
        result = {
            'bytes_downloaded': final_size,
            'expected_bytes': SIZE,
            'sha256': digest,
            'elapsed_seconds': round(elapsed, 3),
            'baseline_working_set_mb': round(baseline / 1024 / 1024, 3),
            'peak_working_set_mb': round(peak / 1024 / 1024, 3),
            'memory_delta_mb': round((peak - baseline) / 1024 / 1024, 3),
            'threshold_mb': 64,
            'passed': final_size == SIZE and (peak - baseline) < 64 * 1024 * 1024,
        }
        print(json.dumps(result, indent=2))
        return 0 if result['passed'] else 1
    finally:
        stop.set()
        server.close()
        if os.path.exists(tmp_path):
            os.remove(tmp_path)


if __name__ == '__main__':
    raise SystemExit(main())