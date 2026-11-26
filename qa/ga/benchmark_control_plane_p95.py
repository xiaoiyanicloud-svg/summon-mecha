"""控制面本地 p95：100 次支持矩阵请求。"""

import json
import os
import pathlib
import socket
import statistics
import subprocess
import time
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
LARAVEL = ROOT / 'my-bagisto-store'

with socket.socket() as s:
    s.bind(('127.0.0.1', 0))
    port = s.getsockname()[1]

env = os.environ.copy()
env.update({'APP_ENV': 'testing', 'DB_DATABASE': 'summon_mecha_step10_test', 'LOG_CHANNEL': 'stderr'})
creationflags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
proc = subprocess.Popen(
    ['php', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', '-S', f'127.0.0.1:{port}', '-t', 'public'],
    cwd=LARAVEL,
    env=env,
    stdout=subprocess.DEVNULL,
    stderr=subprocess.DEVNULL,
    creationflags=creationflags,
)
url = f'http://127.0.0.1:{port}/api/robot-support-matrix'
request = urllib.request.Request(url, headers={'Connection': 'close'})
try:
    for _ in range(50):
        try:
            urllib.request.urlopen(request, timeout=5).read()
            break
        except Exception:
            time.sleep(0.1)
    else:
        raise RuntimeError('server not ready')
    for _ in range(5):
        urllib.request.urlopen(request, timeout=5).read()
    samples = []
    for _ in range(100):
        start = time.perf_counter()
        with urllib.request.urlopen(request, timeout=5) as response:
            body = response.read()
            payload = json.loads(body.decode('utf-8'))
            if response.status != 200 or not payload.get('success') or len(payload.get('data', {}).get('models', [])) != 5:
                raise RuntimeError(f'unexpected response: {body[:500]!r}')
        samples.append((time.perf_counter() - start) * 1000)
    samples.sort()
    result = {
        'requests': len(samples),
        'p50_ms': round(statistics.median(samples), 3),
        'p95_ms': round(samples[int(0.95 * (len(samples) - 1))], 3),
        'max_ms': round(max(samples), 3),
        'threshold_ms': 300,
        'passed': samples[int(0.95 * (len(samples) - 1))] < 300,
    }
    print(json.dumps(result, indent=2))
finally:
    proc.terminate()
    try:
        proc.wait(timeout=5)
    except subprocess.TimeoutExpired:
        proc.kill()