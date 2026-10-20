<?php

/*
|--------------------------------------------------------------------------
| 生态 outbox 注册表（ADR-0001 G1-D07）
|--------------------------------------------------------------------------
|
| 这是 `ecosystem_outbox.aggregate_type` / `event_type` 的**单一事实源**。
| `Services\Ecosystem\OutboxWriter` 在落库前按本表校验；不在表内的类型直接拒绝。
|
| G1 冻结范围：
|   - 聚合只有 event / content；
|   - 事件只有发布、暂缓、恢复、撤稿四类 × 两个域。
|     `*.restored` 是 G1-08B 的 ADR A-03 落地项：暂缓后的恢复必须可被消费方
|     识别为独立事实，不能复用 `*.published`。
|
| G2 扩展（G2-12）：
|   - 聚合新增 registration（报名主体）；
|   - 事件覆盖提交/确认/拒绝/取消/重报/变更/活动取消/提醒。
|     消费侧在 `EcosystemOutboxProcessor` 内按 registration 分支核验并生成站内信；
|     未接短信/微信/支付，`sent` 只代表事实已消费，不代表用户已收到。
|
*/
return [
    'aggregate_types' => [
        'event',
        'content',
        'registration',
        // G3-08 / G3-09：成绩与申诉只做事实核验 + 站内信，不接短信/邮件。
        'competition_result',
        'competition_appeal',
    ],

    'event_types' => [
        'event.published',
        'event.withheld',
        'event.restored',
        'event.retracted',
        'content.published',
        'content.withheld',
        'content.restored',
        'content.retracted',
        'registration.submitted',
        'registration.confirmed',
        'registration.rejected',
        'registration.canceled',
        'registration.reapplied',
        'registration.change_requested',
        'registration.change_acknowledged',
        'registration.change_expired',
        'registration.checked_in',
        'registration.event_canceled',
        'registration.reminder',
        'competition_result.provisional',
        'competition_result.final',
        'competition_appeal.accepted',
        'competition_appeal.decided',
        'competition_appeal.withdrawn',
    ],

    /*
    |--------------------------------------------------------------------------
    | G1-09 worker（领取 / 租约 / 重试）
    |--------------------------------------------------------------------------
    |
    | ⚠ `worker_enabled=false` 是**默认值**：本仓库当前没有常驻 scheduler / cron，
    |   “注册了定时任务”不等于“任务在跑”。开启前必须同时确认：
    |     · 部署侧已启动 `schedule:work` 或 crontab；
    |     · 已用 `ecosystem:process-outbox --dry-run`（手工）演练并确认摘要可读；
    |       需要限定批次时用 `--limit=N`（N 不超过 batch_size）；该命令没有 `--once` 参数。
    |     · outbox 表中没有长期 dead 记录。
    |
    | `lease_seconds` 必须明显大于单批 handler 的最长执行时间。租约过期后，
    | 恢复扫描会把 processing 置回 pending，旧 worker 的结算会因 token 不匹配被拒绝。
    |
    | `batch_size` 上限 20：MySQL 5.7 没有 SKIP LOCKED，领取用 FOR UPDATE
    | 持有行锁到事务提交；批次越大，锁持有时间越长。不要照抄 MySQL 8 写法。
    |
    | `retry_backoff_seconds` 按 attempts 取下标（1-based → 下标 0），
    | 超出列表后沿用最后一项；不做无限指数增长，避免一次故障把重试推到几天后。
    */
    'worker_enabled'        => (bool) env('ECOSYSTEM_OUTBOX_WORKER_ENABLED', false),
    'lease_seconds'         => (int) env('ECOSYSTEM_OUTBOX_LEASE_SECONDS', 300),
    'batch_size'            => (int) env('ECOSYSTEM_OUTBOX_BATCH_SIZE', 20),
    'max_attempts'          => (int) env('ECOSYSTEM_OUTBOX_MAX_ATTEMPTS', 5),
    'retry_backoff_seconds' => [30, 120, 600, 1800, 3600],
];
