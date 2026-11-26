"""设备健康采样（WBS A2 / 健康度与资源上报）。

只采**能证明来源**的指标，采不到就给 None —— 绝不编造 0 或默认值：

  · 电池：`/sys/class/power_supply/*/capacity`（Linux）
  · 温度：`/sys/class/thermal/thermal_zone*/temp`（毫摄氏度，取最高值）
  · CPU：`/proc/stat` 两次采样差分（首次无基线 → None）
  · 内存：`/proc/meminfo` 的 MemTotal / MemAvailable
  · 磁盘：`shutil.disk_usage(install_dir)`
  · 运行时长：`/proc/uptime`，否则用进程启动时间
  · 错误码趋势：`AgentMetrics` 的 8 个设备侧计数器（只带非零项）

所有探针都是"尽力而为"：读不到、格式坏、权限不足一律返回 None，绝不因为采样失败
影响心跳主链路。平台侧把 None 当"未上报"，不会当 0。

边界：
  · 电池 0–100；温度 -40–150℃；CPU 0–100；内存/磁盘非负；超出范围按 None 处理。
  · Windows 开发机没有 /proc、/sys：只上报磁盘与运行时长，其余为 None。
"""

from __future__ import annotations

import os
import shutil
import time
from typing import Any, Dict, Optional

#: "错误码趋势"只收**失败信号**；command_received/restart 这类正常计数不属于错误。
ERROR_COUNTER_KEYS = frozenset({
    "command_signature_failed_total",
    "package_hash_mismatch_total",
    "task_lease_conflict_total",
    "outbox_flush_failed_total",
})


class HealthSampler:
    def __init__(
        self,
        install_dir: str,
        proc_root: str = "/proc",
        sys_root: str = "/sys",
        metrics: Optional[Any] = None,
    ):
        self.install_dir = os.path.abspath(install_dir)
        self.proc_root = proc_root
        self.sys_root = sys_root
        self.metrics = metrics
        self._started_at = time.time()
        self._last_cpu: Optional[tuple] = None

    # ── 对外 ────────────────────────────────────────────────────────────────

    def sample(self) -> Dict[str, Any]:
        """返回一次健康快照。任何单项失败都不影响其它项，也不抛异常。"""
        battery = self._battery_pct()
        temperature = self._temperature_c()
        cpu = self._cpu_pct()
        memory = self._memory_mb()
        disk = self._disk_mb()

        return {
            "sampled_at": int(time.time()),
            "battery_pct": battery,
            "temperature_c": temperature,
            "cpu_pct": cpu,
            "memory_used_mb": memory[0],
            "memory_total_mb": memory[1],
            "disk_free_mb": disk[0],
            "disk_total_mb": disk[1],
            "uptime_seconds": self._uptime_seconds(),
            "error_counters": self._error_counters(),
        }

    # ── 探针 ────────────────────────────────────────────────────────────────

    def _battery_pct(self) -> Optional[int]:
        root = os.path.join(self.sys_root, "class", "power_supply")
        try:
            entries = sorted(os.listdir(root))
        except OSError:
            return None

        # 优先 BAT*；没有就按名字顺序取第一个能读的
        entries.sort(key=lambda name: (0 if name.upper().startswith("BAT") else 1, name))

        for name in entries:
            value = self._read_int(os.path.join(root, name, "capacity"))
            if value is not None and 0 <= value <= 100:
                return value

        return None

    def _temperature_c(self) -> Optional[float]:
        root = os.path.join(self.sys_root, "class", "thermal")
        try:
            zones = sorted(os.listdir(root))
        except OSError:
            return None

        readings = []
        for zone in zones:
            if not zone.startswith("thermal_zone"):
                continue
            milli = self._read_int(os.path.join(root, zone, "temp"))
            if milli is None:
                continue
            celsius = milli / 1000.0
            if -40.0 <= celsius <= 150.0:
                readings.append(celsius)

        if not readings:
            return None

        return round(max(readings), 1)

    def _cpu_pct(self) -> Optional[float]:
        """两次 `/proc/stat` 差分。第一次调用只建立基线，返回 None。"""
        line = self._read_first_line(os.path.join(self.proc_root, "stat"), prefix="cpu ")
        if line is None:
            return None

        parts = line.split()
        if len(parts) < 5:
            return None

        try:
            values = [int(part) for part in parts[1:]]
        except ValueError:
            return None

        total = sum(values)
        idle = values[3] + (values[4] if len(values) > 4 else 0)  # idle + iowait
        current = (total, idle)

        previous = self._last_cpu
        self._last_cpu = current

        if previous is None:
            return None

        total_delta = total - previous[0]
        idle_delta = idle - previous[1]
        if total_delta <= 0:
            return None

        busy = 100.0 * (total_delta - idle_delta) / total_delta

        return round(min(100.0, max(0.0, busy)), 1)

    def _memory_mb(self) -> tuple[Optional[int], Optional[int]]:
        path = os.path.join(self.proc_root, "meminfo")
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                lines = handle.readlines()
        except OSError:
            return (None, None)

        values: Dict[str, int] = {}
        for line in lines:
            key, _, rest = line.partition(":")
            parts = rest.split()
            if parts and parts[0].isdigit():
                values[key.strip()] = int(parts[0])  # kB

        total_kb = values.get("MemTotal")
        available_kb = values.get("MemAvailable")

        if total_kb is None:
            return (None, None)

        used_kb = total_kb - available_kb if available_kb is not None else None

        return (
            int(used_kb / 1024) if used_kb is not None and used_kb >= 0 else None,
            int(total_kb / 1024),
        )

    def _disk_mb(self) -> tuple[Optional[int], Optional[int]]:
        try:
            usage = shutil.disk_usage(self.install_dir)
        except OSError:
            return (None, None)

        return (int(usage.free / 1024 / 1024), int(usage.total / 1024 / 1024))

    def _uptime_seconds(self) -> int:
        path = os.path.join(self.proc_root, "uptime")
        try:
            with open(path, "r", encoding="utf-8") as handle:
                first = handle.read().split()[0]
            return max(0, int(float(first)))
        except (OSError, ValueError, IndexError):
            return max(0, int(time.time() - self._started_at))

    def _error_counters(self) -> Dict[str, int]:
        if self.metrics is None:
            return {}

        try:
            snapshot = self.metrics.snapshot()
        except Exception:
            return {}

        return {
            key: int(value)
            for key, value in snapshot.items()
            if key in ERROR_COUNTER_KEYS and int(value) > 0
        }

    # ── 基础读取 ────────────────────────────────────────────────────────────

    @staticmethod
    def _read_int(path: str) -> Optional[int]:
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                text = handle.read().strip()
        except OSError:
            return None

        try:
            return int(text)
        except ValueError:
            return None

    @staticmethod
    def _read_first_line(path: str, prefix: str) -> Optional[str]:
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                for line in handle:
                    if line.startswith(prefix):
                        return line.strip()
        except OSError:
            return None

        return None
