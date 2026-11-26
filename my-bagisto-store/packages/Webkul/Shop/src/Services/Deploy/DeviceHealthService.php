<?php

namespace Webkul\Shop\Services\Deploy;

use InvalidArgumentException;
use Webkul\Shop\Models\DeviceBinding;

/**
 * 设备健康快照（WBS A2）。
 *
 * 存 `device_binding.meta.health`，不新建列：健康是**瞬时快照**，随每次心跳覆盖，
 * 不参与任何唯一约束/索引；为它加一张表只会制造第二份真相。
 *
 * 三条硬约束：
 *   1. 只收白名单字段 —— 否则 Agent 传什么就存什么，meta 迟早被撑爆；
 *   2. `error_counters` 的键必须属于 `DeployMetricCounter::AGENT_KEYS`，
 *      与 A3 的闭合键集同源（否则"错误码趋势"会变成高基数标签）；
 *   3. 覆盖语义：本次没报的字段不会保留旧值 —— 健康是"现在怎么样"，
 *      不是"曾经怎么样"；保留旧值会把一台已经采不到温度的设备显示成"温度正常"。
 */
class DeviceHealthService
{
    public const META_KEY = 'health';

    /** 允许上报的字段（与 AgentController 的校验规则保持同一份清单）。 */
    public const FIELDS = [
        'battery_pct',
        'temperature_c',
        'cpu_pct',
        'memory_used_mb',
        'memory_total_mb',
        'disk_free_mb',
        'disk_total_mb',
        'uptime_seconds',
    ];

    /**
     * "错误码趋势"只收**失败信号**。
     *
     * 与设备侧 `HealthSampler.ERROR_COUNTER_KEYS` 同一份清单；`command_received_total` /
     * `agent_restart_total` 这类正常计数不属于错误，混进来会让告警口径失真。
     */
    public const ERROR_COUNTER_KEYS = [
        'command_signature_failed_total',
        'package_hash_mismatch_total',
        'task_lease_conflict_total',
        'outbox_flush_failed_total',
    ];

    /**
     * 把一次健康快照写进 binding.meta。
     *
     * @param  array<string, mixed>  $health
     * @return array<string, mixed> 实际落库的快照
     *
     * @throws InvalidArgumentException 错误计数键不在 A3 闭合键集内
     */
    public function apply(DeviceBinding $binding, array $health): array
    {
        $snapshot = [];

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $health) && $health[$field] !== null && $health[$field] !== '') {
                $snapshot[$field] = $field === 'temperature_c' || $field === 'cpu_pct'
                    ? round((float) $health[$field], 1)
                    : (int) $health[$field];
            }
        }

        if (array_key_exists('error_counters', $health) && is_array($health['error_counters'])) {
            $allowed = array_flip(self::ERROR_COUNTER_KEYS);
            $counters = [];

            foreach ($health['error_counters'] as $key => $value) {
                if (! isset($allowed[$key])) {
                    throw new InvalidArgumentException(
                        "未知错误计数键 '{$key}'：只接受失败类计数（见 DeviceHealthService::ERROR_COUNTER_KEYS），"
                        .'正常计数（如 command_received_total）不属于错误趋势。'
                    );
                }
                $counters[$key] = (int) $value;
            }

            $snapshot['error_counters'] = $counters;
        }

        $snapshot['sampled_at'] = (int) ($health['sampled_at'] ?? now()->timestamp);
        $snapshot['reported_at'] = now()->toDateTimeString();

        $meta = is_array($binding->meta) ? $binding->meta : [];
        $meta[self::META_KEY] = $snapshot;
        $binding->meta = $meta;

        return $snapshot;
    }

    /**
     * 读取最近一次健康快照（没有则 null）。
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(DeviceBinding $binding): ?array
    {
        $meta = is_array($binding->meta) ? $binding->meta : [];
        $health = $meta[self::META_KEY] ?? null;

        return is_array($health) ? $health : null;
    }
}
