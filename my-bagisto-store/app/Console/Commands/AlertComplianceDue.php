<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Models\ComplianceFiling;
use Webkul\Shop\Services\Ops\AdminAlertService;

/**
 * 备案到期 / 年检提醒（计划 §4.17）。
 *
 *   php artisan ops:alert-compliance-due [--dry-run]
 *
 * 为什么需要定时任务而不是"打开台账页看一眼"：备案漏掉年检会**整套失效**，
 * 而失效是不打招呼的。台账页只在有人打开时才起作用 —— 没人打开的那一周，
 * 恰恰是最需要提醒的一周。
 *
 * 幂等：同一份备案的去重键 `compliance:due:{id}`，静默期见 AdminAlertService。
 */
class AlertComplianceDue extends Command
{
    protected $signature = 'ops:alert-compliance-due {--dry-run : 只报告将要做什么}';

    protected $description = '给即将到期/需年检的备案发平台告警';

    public function handle(AdminAlertService $alerts): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // ⚠ 在 PHP 侧用模型的 isExpiringSoon() 判，而不是在 SQL 里重写一遍日期规则 ——
        //   规则只该有一处。备案表是低量级的（个位数到几十行），全取没问题。
        $due = ComplianceFiling::query()
            ->where('status', ComplianceFiling::STATUS_FILED)
            ->orderBy('id')
            ->get()
            ->filter(fn (ComplianceFiling $f) => $f->isExpiringSoon());

        $this->line(sprintf(
            '%s%d 份已备案记录中，%d 份即将到期/需年检。',
            $dryRun ? '[DRY-RUN] ' : '',
            ComplianceFiling::where('status', ComplianceFiling::STATUS_FILED)->count(),
            $due->count()
        ));

        $sent = 0;

        foreach ($due as $filing) {
            $nextDue = $filing->nextDueAt();
            $isOverdue = $nextDue && $nextDue->isPast();

            $title = sprintf(
                '%s%s：%s',
                $isOverdue ? '已逾期' : '即将到期',
                $filing->typeLabel(),
                $filing->name
            );

            $content = sprintf(
                "%s\n备案号：%s\n主管单位：%s\n下一个期限：%s（%s）\n\n期限已过或临近，请尽快办理。",
                $filing->name,
                $filing->filing_no ?: '（未填）',
                $filing->authority ?: '（未填）',
                $nextDue?->toDateString() ?? '（未设置）',
                $isOverdue ? '已逾期' : '距今 '.now()->diffInDays($nextDue).' 天'
            );

            if ($dryRun) {
                $this->line('   · '.$title);

                continue;
            }

            $created = $alerts->alert(
                'compliance_due',
                $title,
                $content,
                'compliance:due:'.$filing->id,
                ['filing_id' => $filing->id, 'filing_type' => $filing->filing_type],
                $isOverdue ? AdminAlertService::LEVEL_URGENT : AdminAlertService::LEVEL_WARN,
            );

            $sent += $created;

            if ($created === 0) {
                $this->line('   · '.$title.'（已有未读或静默期内，跳过）');
            }
        }

        $this->info($dryRun ? '（dry-run，未写入）' : sprintf('已发出 %d 条告警。', $sent));

        return self::SUCCESS;
    }
}
