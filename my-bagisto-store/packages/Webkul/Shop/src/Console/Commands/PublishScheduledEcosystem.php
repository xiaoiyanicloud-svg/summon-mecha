<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Shop\Services\Ecosystem\ScheduledPublicationScanner;

/**
 * 到期发布扫描（G1-09 / G1-08B `publishScheduled`）。
 *
 *   php artisan ecosystem:publish-scheduled [--limit=50] [--dry-run]
 *
 * ⚠ 本命令只跑一轮有界扫描，不常驻。定时触发由 `bootstrap/app.php` 注册
 *   （`everyMinute` + `withoutOverlapping`），默认
 *   `ecosystem_publishing.scheduler_enabled=false`。**注册不等于已运行**。
 *
 * 域发布开关（`event_publish_enabled` / `content_publish_enabled` + B-04/B-07）
 * 为 false 时整域跳过：运营关开关不是“该条排期失败”，不能回退排期。
 *
 * 退出码：
 *   · 0：全部候选已发布，或业务失败已安全回退（有告警、有审计）；
 *   · 1：存在瞬时数据库错误或未预期错误 —— 行保持 scheduled，下一轮重试。
 */
class PublishScheduledEcosystem extends Command
{
    protected $signature = 'ecosystem:publish-scheduled
        {--limit=50 : 本轮最多扫描的到期对象数}
        {--dry-run : 只统计到期候选，不发布、不回退}';

    protected $description = '扫描到期的活动 / 文章排期并执行系统发布（失败安全回退）';

    public function handle(ScheduledPublicationScanner $scanner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?: 50);

        $this->line(sprintf(
            'scheduler_enabled=%s（false 时调度器不会自动触发；本命令为手工执行）',
            config('ecosystem_publishing.scheduler_enabled', false) ? 'true' : 'false',
        ));

        try {
            $summary = $scanner->run($limit, $dryRun);
        } catch (Throwable $exception) {
            $this->error('到期发布扫描失败：'.$exception::class.' '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach (['event', 'content'] as $domain) {
            if (! empty($summary['domains'][$domain]['skipped_disabled'])) {
                $this->line(sprintf(
                    '%s域发布开关关闭，已整域跳过。',
                    $domain === 'event' ? '活动' : '内容',
                ));
            }
        }

        if ($dryRun) {
            $this->line(sprintf(
                '[DRY-RUN] 到期候选 %d 条（活动 %d / 文章 %d）。未发布、未回退。',
                $summary['candidates'],
                $summary['domains']['event']['candidates'],
                $summary['domains']['content']['candidates'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '到期候选 %d；发布 %d / 安全回退 %d / 跳过 %d / 瞬时失败 %d / 未预期错误 %d。',
            $summary['candidates'],
            $summary['published'],
            $summary['aborted'],
            $summary['skipped'],
            $summary['transient'],
            $summary['errors'],
        ));

        if ($summary['aborted'] > 0) {
            $this->warn('存在安全回退：已写 schedule_aborted 审计并告警，请运营核对发布前置。');
        }

        if ($summary['transient'] > 0 || $summary['errors'] > 0) {
            $this->warn('存在未解决失败：相关行保持 scheduled，下一轮会重试。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
