<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Shop\Services\Ecosystem\EcosystemOutboxProcessor;

/**
 * 生态 outbox worker（G1-09 / ADR-0001 G1-D07）。
 *
 *   php artisan ecosystem:process-outbox [--limit=20] [--dry-run]
 *
 * ⚠ 本命令只跑**一轮有界批次**，不常驻、不无限循环。定时触发由
 *   `bootstrap/app.php` 注册（`everyMinute` + `withoutOverlapping`），
 *   默认开关 `ecosystem_outbox.worker_enabled=false`。
 *   **注册不等于在跑**：没有 `schedule:work` / crontab 时它不会自动执行，
 *   需要运维手工跑本命令或启动调度器。
 *
 * `--dry-run` 只读统计待领取与过期租约，不领取、不恢复、不结算。
 * 手工执行不受 worker_enabled 限制（这是运维显式动作），但会打印开关状态。
 */
class ProcessEcosystemOutbox extends Command
{
    protected $signature = 'ecosystem:process-outbox
        {--limit= : 本轮最多领取条数（默认 batch_size，上限 20）}
        {--dry-run : 只统计 pending/到期与过期租约，不做任何写操作}';

    protected $description = '领取并消费生态 outbox 的一轮有界批次（租约 / 重试 / dead）';

    public function handle(EcosystemOutboxProcessor $processor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?: 0);

        $this->line(sprintf(
            'worker_enabled=%s（false 时调度器不会自动触发；本命令为手工执行）',
            config('ecosystem_outbox.worker_enabled', false) ? 'true' : 'false',
        ));

        try {
            $summary = $processor->run($limit, $dryRun);
        } catch (Throwable $exception) {
            $this->error('outbox worker 执行失败：'.$exception::class.' '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line(sprintf(
                '[DRY-RUN] 到期待领取 %d 条；过期租约 %d 条。未写库。',
                $summary['pending_due'],
                $summary['expired_leases'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '恢复过期租约 %d；领取 %d；sent %d / retried %d / failed %d / dead %d / lease_lost %d / errors %d。',
            $summary['recovered'],
            $summary['claimed'],
            $summary['sent'],
            $summary['retried'],
            $summary['failed'],
            $summary['dead'],
            $summary['lease_lost'],
            $summary['errors'],
        ));

        if ($summary['failed'] > 0) {
            $this->warn('存在达到重试上限的失败记录（failed）；需在运营工作台确认原因后人工重排，不会自动复活。');
        }

        if ($summary['lease_lost'] > 0) {
            $this->warn('存在旧 worker 回报被拒绝（lease_lost）；结果已丢弃，请检查是否有进程执行超过 lease_seconds。');
        }

        if ($summary['errors'] > 0) {
            $this->warn('存在未能写入失败状态的记录；租约到期后会由恢复扫描兜底。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
