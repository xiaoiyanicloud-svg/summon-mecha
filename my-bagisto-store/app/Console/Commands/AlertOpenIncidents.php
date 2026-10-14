<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Services\Ops\AdminAlertService;

/**
 * 未结案事故提醒（计划 §4.19）。
 *
 *   php artisan ops:alert-open-incidents [--dry-run]
 *
 * 为什么严重事故的时限比一般事故短得多：
 *   技能是在真实机器人上跑的。一起"重大"事故如果没有在**当天**被平台看见，
 *   可能已经有第二个人在用同一个技能了。所以严重/重大的时限是 4 小时，
 *   其余是 48 小时。
 *
 * ⚠ 本命令**只提醒，不自动处置**。事故的责任认定与赔偿是人的判断，
 *   自动化到那一步就是把法律责任交给 cron。
 */
class AlertOpenIncidents extends Command
{
    protected $signature = 'ops:alert-open-incidents {--dry-run : 只报告将要做什么}';

    protected $description = '给长时间未结案的事故发平台告警（严重事故时限更短）';

    /**
     * 各严重程度的响应时限（小时）。超过就提醒。
     */
    public const SLA_HOURS = [
        Incident::SEVERITY_CRITICAL => 4,
        Incident::SEVERITY_SEVERE   => 4,
        Incident::SEVERITY_MODERATE => 48,
        Incident::SEVERITY_MINOR    => 48,
    ];

    /**
     * 未填严重程度时的兜底时限（小时）——按最宽松的处理，但**不跳过**。
     */
    public const DEFAULT_SLA_HOURS = 48;

    public function handle(AdminAlertService $alerts): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $open = Incident::query()
            ->whereNotIn('status', [Incident::STATUS_RESOLVED, Incident::STATUS_CLOSED])
            ->orderBy('created_at')
            ->get();

        $this->line(sprintf('%s%d 起事故未结案。', $dryRun ? '[DRY-RUN] ' : '', $open->count()));

        $sent = 0;

        foreach ($open as $incident) {
            $limit = self::SLA_HOURS[$incident->severity] ?? self::DEFAULT_SLA_HOURS;
            $openHours = (int) $incident->created_at->diffInHours(now());

            // 还在时限内 —— 不打扰。提醒过早会让运营对告警脱敏。
            if ($openHours < $limit) {
                continue;
            }

            $title = sprintf(
                '事故未结案：%s（%s，已 %d 小时）',
                $incident->incident_no,
                $incident->severityLabel(),
                $openHours
            );

            $content = sprintf(
                "事故编号：%s\n严重程度：%s\n处理状态：%s\n责任认定：%s\n发生时间：%s\n上报时间：%s\n\n%s",
                $incident->incident_no,
                $incident->severityLabel(),
                $incident->statusLabel(),
                $incident->responsibilityLabel(),
                $incident->occurred_at?->toDateTimeString() ?? '（未填）',
                $incident->created_at?->toDateTimeString(),
                mb_substr((string) $incident->description, 0, 200)
            );

            if ($dryRun) {
                $this->line('   · '.$title);

                continue;
            }

            $sent += $alerts->alert(
                'incident_open',
                $title,
                $content,
                'incident:open:'.$incident->id,
                [
                    'incident_id'    => $incident->id,
                    'severity'       => $incident->severity,
                    'open_hours'     => $openHours,
                    'sla_hours'      => $limit,
                ],
                $incident->requiresEscalation()
                    ? AdminAlertService::LEVEL_URGENT
                    : AdminAlertService::LEVEL_WARN,
            );
        }

        $this->info($dryRun ? '（dry-run，未写入）' : sprintf('已发出 %d 条告警。', $sent));

        return self::SUCCESS;
    }
}
