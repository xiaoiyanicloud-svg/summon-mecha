<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 运行指标计数器（A3）。
 *
 * 设计只有一条硬约束：**键集闭合**。
 *
 *   指标系统最常见的坏味道不是"少埋点"，而是**基数爆炸** —— 有人把 `device_id`、
 *   `task_no`、`skill_id` 塞进标签，于是每个设备/每个任务都长出一条时间序列，
 *   监控后端几周后被撑爆，而代码里看不出任何异常。这里用最笨也最有效的办法堵住它：
 *   **只允许 METRIC_KEYS 里的 9 个键**，写别的键直接抛异常。
 *   没有动态键 → 基数恒定 → 不需要靠"Code Review 时记得别写错"来保障。
 *
 * 另一个隐含约束：**累加必须原子**。用 `INSERT ... ON DUPLICATE KEY UPDATE x = x + ?`，
 * 而不是"读出来 +1 再写回去" —— 后者在并发下会丢计数，而且丢得悄无声息。
 *
 * 设备端上报的增量走 `applyAgentBatch()`：按 `(did, batch_id)` 去重后再累加。
 * 没有这一步，Agent 的"失败重发"会变成"重复计数"，计数器只虚高不回落。
 */
class DeployMetricCounter
{
    /** 设备端（Agent）自己产生、随心跳增量上报的计数。 */
    public const AGENT_KEYS = [
        // WBS A3.2 字面项：设备真正收到一条指令（无论最终是否执行）。
        'command_received_total',
        // WBS A3.2 字面项：同 task_id/command_seq 的重复投递被幂等挡住。
        'command_duplicate_total',
        'agent_restart_total',
        'staging_orphan_total',
        // ⚠ 不是 `package_signature_failed_total`：**Agent 侧根本不校验包签名**
        //   （包只校验 SHA-256，签名在指令信封上）。把不存在的生产者伪装成已埋点，
        //   比留一个 null 更糟 —— 参见 GaOperationalMetrics 里那条显式的 not_instrumented。
        'command_signature_failed_total',
        'package_hash_mismatch_total',
        'task_lease_conflict_total',
        'outbox_flush_failed_total',
    ];

    /** 平台侧自己埋点累加的计数。 */
    public const PLATFORM_KEYS = [
        'idempotency_hit_total',
        'reconcile_drift_total',
        'fact_auth_rejected_total',
        // WBS A3.2 字面项：同 event_id 异载荷（Inbox 409 冲突）。
        'event_conflict_total',
    ];

    /** 设备端上报的瞬时值（gauge，不是累计计数）。 */
    public const GAUGE_KEYS = [
        // WBS A3.2 字面项：设备 outbox 里最老未确认事件的年龄（秒）。
        'outbox_pending_age_seconds',
    ];

    /** 全部键（= AGENT_KEYS ∪ PLATFORM_KEYS，共 12 个）。键集闭合，禁止在此之外新增。 */
    public const METRIC_KEYS = [
        ...self::AGENT_KEYS,
        ...self::PLATFORM_KEYS,
    ];

    public const TABLE = 'deploy_metric_counters';

    public const BATCH_TABLE = 'deploy_agent_metric_batches';

    public const GAUGE_TABLE = 'deploy_agent_metric_gauges';

    /**
     * 累加一个平台侧计数。
     *
     * @throws InvalidArgumentException 键不在白名单 / 增量非正数
     */
    public function increment(string $key, int $by = 1): int
    {
        if (! in_array($key, self::METRIC_KEYS, true)) {
            throw new InvalidArgumentException(
                "未知指标键 '{$key}'：只允许闭合键集内的 12 个键（见 DeployMetricCounter::METRIC_KEYS），拒绝创建动态键。"
            );
        }

        if ($by <= 0) {
            throw new InvalidArgumentException('指标增量必须为正整数（计数器只增不减）。');
        }

        return $this->bump($key, $by);
    }

    /**
     * 读取全部 9 个键的当前值；从未累加过的键返回 0（不是 null —— null 是"没埋点"，0 是"确实是 0"）。
     *
     * @return array<string, int>
     */
    public function counters(): array
    {
        $rows = DB::table(self::TABLE)->pluck('metric_value', 'metric_key')->all();

        $out = [];
        foreach (self::METRIC_KEYS as $key) {
            $out[$key] = isset($rows[$key]) ? (int) $rows[$key] : 0;
        }

        return $out;
    }

    public function total(string $key): int
    {
        if (! in_array($key, self::METRIC_KEYS, true)) {
            throw new InvalidArgumentException("未知指标键 '{$key}'。");
        }

        $value = DB::table(self::TABLE)->where('metric_key', $key)->value('metric_value');

        return $value === null ? 0 : (int) $value;
    }

    /**
     * 应用一批设备端 gauge（瞬时值，**不是**累计计数）。
     *
     * 语义与计数完全不同：gauge 是"现在是多少"，重复上报**覆盖**而不是相加。
     * Agent 的 `outbox_pending_age_seconds` 每次心跳都会变，累加它没有任何意义。
     *
     * @param  array<string, int>  $gauges
     */
    public function applyAgentGauges(string $did, array $gauges): void
    {
        if ($did === '' || $gauges === []) {
            return;
        }

        foreach ($gauges as $key => $value) {
            if (! in_array($key, self::GAUGE_KEYS, true)) {
                throw new InvalidArgumentException(
                    "未知 gauge 键 '{$key}'：只允许 ".implode(', ', self::GAUGE_KEYS)
                );
            }
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('gauge 必须是非负整数。');
            }
        }

        foreach ($gauges as $key => $value) {
            // T1：必须**原子覆盖**。`updateOrInsert` 是"先查再写"，两个并发心跳同时
            // 发现行不存在时会双双 INSERT，撞唯一键 → 500。upsert 在 MySQL 上编译成
            // `INSERT ... ON DUPLICATE KEY UPDATE`，并发下只有一个写入生效，值仍是覆盖语义。
            DB::table(self::GAUGE_TABLE)->upsert(
                [[
                    'did'          => $did,
                    'metric_key'   => $key,
                    'metric_value' => $value,
                    'updated_at'   => now(),
                ]],
                ['did', 'metric_key'],
                ['metric_value', 'updated_at'],
            );
        }
    }

    /** 某个 gauge 在所有设备上的最大值；从未上报过返回 null（null ≠ 0）。 */
    public function gaugeMax(string $key): ?int
    {
        if (! in_array($key, self::GAUGE_KEYS, true)) {
            throw new InvalidArgumentException("未知 gauge 键 '{$key}'。");
        }

        $value = DB::table(self::GAUGE_TABLE)->where('metric_key', $key)->max('metric_value');

        return $value === null ? null : (int) $value;
    }

    /**
     * 应用一批设备端增量。
     *
     * 幂等语义：同一 `(did, batch_id)` 只生效一次；重复上报返回 false（调用方仍应回 200，
     * 让 Agent 正常 ack —— 它已经把增量发出去了，重发是我们的问题不是它的）。
     *
     * @param  array<string, int>  $counters  key => delta
     * @return bool 本次是否真的累加（false = 重复批次，已忽略）
     */
    public function applyAgentBatch(string $did, string $batchId, array $counters): bool
    {
        if ($did === '' || $batchId === '') {
            throw new InvalidArgumentException('设备增量批次必须带 did 与 batch_id。');
        }

        foreach (array_keys($counters) as $key) {
            if (! in_array($key, self::AGENT_KEYS, true)) {
                throw new InvalidArgumentException(
                    "设备端不得上报 '{$key}'：只接受 AGENT_KEYS 内的键（平台侧键由平台自己埋点）。"
                );
            }
        }

        foreach ($counters as $delta) {
            if (! is_int($delta) || $delta < 0) {
                throw new InvalidArgumentException('设备端增量必须是非负整数。');
            }
        }

        return DB::transaction(function () use ($did, $batchId, $counters) {
            $inserted = DB::table(self::BATCH_TABLE)->insertOrIgnore([
                'did'        => $did,
                'batch_id'   => $batchId,
                'applied_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            foreach ($counters as $key => $delta) {
                if ($delta > 0) {
                    $this->bump($key, $delta);
                }
            }

            // 台账清理：只保留 30 天，避免无限增长（去重窗口远大于任何合理重试间隔）。
            DB::table(self::BATCH_TABLE)
                ->where('applied_at', '<', now()->subDays(30))
                ->delete();

            return true;
        });
    }

    /**
     * 原子累加。MySQL: `ON DUPLICATE KEY UPDATE`；其它驱动退化为"行锁读改写"（同事务内仍不丢计数）。
     */
    protected function bump(string $key, int $by): int
    {
        $now = now();

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'INSERT INTO `'.self::TABLE.'` (`metric_key`, `metric_value`, `updated_at`) VALUES (?, ?, ?) '
                .'ON DUPLICATE KEY UPDATE `metric_value` = `metric_value` + VALUES(`metric_value`), `updated_at` = VALUES(`updated_at`)',
                [$key, $by, $now]
            );

            return $this->total($key);
        }

        return DB::transaction(function () use ($key, $by, $now) {
            $existing = DB::table(self::TABLE)->where('metric_key', $key)->lockForUpdate()->first();

            if ($existing) {
                DB::table(self::TABLE)->where('metric_key', $key)->update([
                    'metric_value' => (int) $existing->metric_value + $by,
                    'updated_at'   => $now,
                ]);

                return (int) $existing->metric_value + $by;
            }

            DB::table(self::TABLE)->insert(['metric_key' => $key, 'metric_value' => $by, 'updated_at' => $now]);

            return $by;
        });
    }
}
