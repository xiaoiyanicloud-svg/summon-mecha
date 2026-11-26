"""Control-plane latency and saturation probe for the support-matrix endpoint.

This uses PHP's built-in server and therefore measures a development-like
single-process baseline, not production FPM. Report it as such.
"""

import argparse
import concurrent.futures
import json
import os
import pathlib
import socket
import statistics
import subprocess
import time
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
LARAVEL = ROOT / "my-bagisto-store"


def percentile(values, ratio):
    ordered = sorted(values)
    return ordered[int(ratio * (len(ordered) - 1))]


def one_request(url):
    req = urllib.request.Request(url, headers={"Connection": "close"})
    start = time.perf_counter()
    with urllib.request.urlopen(req, timeout=30) as response:
        body = response.read()
        if response.status != 200:
            raise RuntimeError(f"HTTP {response.status}")
        payload = json.loads(body.decode("utf-8"))
        if not payload.get("success") or len(payload.get("data", {}).get("models", [])) != 5:
            raise RuntimeError("unexpected support matrix payload")
    return (time.perf_counter() - start) * 1000


def run_level(url, concurrency, requests):
    started = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
        futures = [pool.submit(one_request, url) for _ in range(requests)]
        latencies = [future.result() for future in futures]
    elapsed = time.perf_counter() - started
    return {
        "concurrency": concurrency,
        "requests": requests,
        "p50_ms": round(statistics.median(latencies), 3),
        "p95_ms": round(percentile(latencies, 0.95), 3),
        "p99_ms": round(percentile(latencies, 0.99), 3),
        "max_ms": round(max(latencies), 3),
        "throughput_rps": round(requests / elapsed, 3),
        "errors": 0,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--main-requests", type=int, default=100)
    parser.add_argument("--level-requests", type=int, default=20)
    parser.add_argument("--levels", default="1,2,4,8")
    parser.add_argument("--output", type=pathlib.Path)
    args = parser.parse_args()

    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = os.environ.copy()
    env.update({"APP_ENV": "testing", "DB_DATABASE": "summon_mecha_step10_test", "LOG_CHANNEL": "stderr"})
    creationflags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    proc = subprocess.Popen(
        ["php", "-d", "opcache.enable_cli=1", "-d", "opcache.validate_timestamps=0", "-S", f"127.0.0.1:{port}", "-t", "public"],
        cwd=LARAVEL,
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        creationflags=creationflags,
    )
    url = f"http://127.0.0.1:{port}/api/robot-support-matrix"
    try:
        for _ in range(50):
            try:
                one_request(url)
                break
            except Exception:
                time.sleep(0.1)
        else:
            raise RuntimeError("server not ready")

        for _ in range(5):
            one_request(url)

        baseline = run_level(url, 1, args.main_requests)
        levels = []
        for concurrency in [int(value) for value in args.levels.split(",") if value.strip()]:
            levels.append(run_level(url, concurrency, args.level_requests))

        baseline_throughput = levels[0]["throughput_rps"] if levels else 0
        saturation = None
        previous_throughput = None
        for row in levels:
            if previous_throughput is not None and row["throughput_rps"] < previous_throughput * 1.10:
                saturation = row
                break
            previous_throughput = row["throughput_rps"]

        result = {
            "environment": "php_builtin_server_development_like",
            "php_version": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True).strip(),
            "baseline": baseline,
            "concurrency_levels": levels,
            "saturation_point": saturation,
            "threshold_ms": 300,
            "passed": baseline["p95_ms"] < 300 and baseline["p99_ms"] < 300 and baseline["errors"] == 0,
        }
        output = json.dumps(result, ensure_ascii=False, indent=2)
        if args.output:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(output + "\n", encoding="utf-8")
        print(output)
        return 0 if result["passed"] else 1
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()


if __name__ == "__main__":
    raise SystemExit(main())