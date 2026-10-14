<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Services\Ops\AdminAlertService;

/**
 * 工单 SLA 超时升级（计划 §4.9）。
 *
 *   php artisan ops:escalate-overdue-tickets [--dry-run]
 *
 * 为什么需要它：`support_ticket.sla_due_at` 是**开单时就写好的**（24 小时），
 * 但在此之前没有任何东西在到期时动作 —— SLA 只是表里一个日期，
 * 超时与否要靠人正好打开列表才看得见。
 *
 * 本命令做两件事：
 *   ① **升级优先级**（low/normal → high）—— 让超时单在列表里自己冒出来
 *   ② 发一条平台告警 —— 让不去看列表的人也收到
 *
 * ⚠ 只升级、不自动结单：把工单自动关掉是最省事也最不负责任的做法 ——
 *   用户的问题没解决，只是从队列里消失了。
 */
class EscalateOverdueTickets extends Command
{
    protected $signature = 'ops:escalate-overdue-tickets {--dry-run : 只报告将要做什么}';

    protected $description = '把超过 SLA 的未结工单升级优先级并发平台告警';

    public function handle(AdminAlertService $alerts): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $overdue = SupportTicket::query()
            // 只处理"还欠着用户"的：已解决/已关闭的不再算超时
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->orderBy('sla_due_at')
            ->get();

        $this->line(sprintf('%s%d 张工单已超 SLA。', $dryRun ? '[DRY-RUN] ' : '', $overdue->count()));

        $sent = 0;

        foreach ($overdue as $ticket) {
            $hours = now()->diffInHours($ticket->sla_due_at);

            $title = sprintf('工单超时：%s（超 %d 小时）', $ticket->ticket_no, $hours);

            $content = sprintf(
                "工单号：%s\n类别：%s\n当前状态：%s\n优先级：%s\nSLA：%s\n超时：%d 小时",
                $ticket->ticket_no,
                $ticket->category ?: '（未分类）',
                $ticket->statusLabel(),
                $ticket->priority,
                $ticket->sla_due_at?->toDateTimeString(),
                $hours
            );

            if ($dryRun) {
                $this->line('   · '.$title);

                continue;
            }

            // ① 升级优先级（已经在 high/urgent 的不动 —— 再升没人可升）
            if (in_array($ticket->priority, ['low', 'normal'], true)) {
                $ticket->priority = 'high';
                $ticket->save();
            }

            // ② 告警
            $sent += $alerts->alert(
                'ticket_overdue',
                $title,
                $content,
                'ticket:overdue:'.$ticket->id,
                ['ticket_id' => $ticket->id, 'overdue_hours' => $hours],
                $hours >= 48 ? AdminAlertService::LEVEL_URGENT : AdminAlertService::LEVEL_WARN,
            );
        }

        $this->info($dryRun ? '（dry-run，未写入）' : sprintf('已发出 %d 条告警。', $sent));

        return self::SUCCESS;
    }
}
