<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

/**
 * 存量整理：同一客户存在多张进行中的工单时，保留最近更新的一张，其余关闭并标记「已合并」。
 *
 * 背景（2026-10-27）：
 *   此前用户每点一次“发送”就会新建一张工单，同一客户可以同时挂着 4 张 open/processing，
 *   后台左栏一个客户占四行，客服在几十人咨询时分不清哪些是同一个人的同一个问题。
 *   用户侧已改为「同一客户只续接一张进行中会话」；本命令负责清存量。
 *
 * 刻意不做的事：
 *   · **不删消息、不移动消息、不改事故关联** —— 历史工单保留原样，只是关闭并留一条说明；
 *   · **不处理已关联事故的重复单** —— 事故追溯链不能因为整理队列而断，列进报告人工处理。
 *
 * 幂等：只处理 open/processing，关闭后不会再被选中。默认 dry-run，必须显式 --execute 才写库。
 */
class MergeDuplicateActiveTickets extends Command
{
    protected $signature = 'support:merge-duplicate-active {--execute : 真正写库；默认只打印 dry-run}';

    protected $description = '同一客户多张进行中工单：保留最近更新的一张，其余关闭并写入合并说明';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $groups = SupportTicket::query()
            ->selectRaw('customer_id, COUNT(*) as total')
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('customer_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('没有同一客户多张进行中会话的情况。');

            return self::SUCCESS;
        }

        $rows = [];
        $planned = 0;
        $closed = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            $customer = Customer::query()->find($group->customer_id);
            $name = trim((string) ($customer?->first_name ?? '').' '.(string) ($customer?->last_name ?? ''));
            $name = $name !== '' ? $name : ($customer?->email ?? '客户 #'.$group->customer_id);

            $tickets = SupportTicket::query()
                ->where('customer_id', $group->customer_id)
                ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            /** @var SupportTicket $keep */
            $keep = $tickets->shift();

            foreach ($tickets as $duplicate) {
                $blocked = $duplicate->incident()->exists() ? '已关联事故，需人工确认' : null;

                $rows[] = [
                    $group->customer_id,
                    $name,
                    $keep->ticket_no,
                    $duplicate->ticket_no,
                    $duplicate->status,
                    $blocked ?: '关闭并合并',
                ];

                if ($blocked) {
                    $skipped++;

                    continue;
                }

                $planned++;

                if ($execute && $this->closeAsMerged($duplicate, $keep)) {
                    $closed++;
                }
            }
        }

        $this->table(
            ['客户ID', '客户', '保留会话', '将关闭', '原状态', '处理'],
            $rows
        );

        if ($execute) {
            $this->info("已关闭 {$closed} 张重复进行中会话（计划 {$planned}，跳过 {$skipped}）。");
        } else {
            $this->warn("dry-run：计划关闭 {$planned} 张，跳过 {$skipped} 张。确认后加 --execute 执行。");
        }

        return self::SUCCESS;
    }

    protected function closeAsMerged(SupportTicket $duplicate, SupportTicket $keep): bool
    {
        return DB::transaction(function () use ($duplicate, $keep) {
            $ticket = SupportTicket::lockForUpdate()->find($duplicate->id);

            if (! $ticket || ! in_array($ticket->status, [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING], true)) {
                return false;
            }

            $ticket->status = SupportTicket::STATUS_CLOSED;
            $ticket->ended_at = now();
            $ticket->held_at = null;
            $ticket->held_reason = null;
            $ticket->save();

            TicketMessage::create([
                'ticket_id'  => $ticket->id,
                'actor_type' => TicketMessage::ACTOR_SYSTEM,
                'actor_id'   => null,
                'content'    => "本会话已合并至你当前的会话（单号 {$keep->ticket_no}），请在新会话中继续沟通。",
                'created_at' => now(),
                // 合并通知是解释而不是待办：标已读，避免历史单上冒出 3 个未读红点。
                'read_at'    => now(),
            ]);

            OperationLog::create([
                'actor_type'  => OperationLog::ACTOR_SYSTEM,
                'actor_id'    => null,
                'action'      => 'ticket.merge_duplicate',
                'target_type' => 'support_ticket',
                'target_id'   => $ticket->id,
                'payload'     => [
                    'keep_ticket_id' => $keep->id,
                    'keep_ticket_no' => $keep->ticket_no,
                ],
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
