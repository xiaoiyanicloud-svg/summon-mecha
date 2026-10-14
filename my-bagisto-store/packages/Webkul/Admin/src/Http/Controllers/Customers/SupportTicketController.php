<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Customers\SupportTicketDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 客服工单受理台（v3.4 步骤 10 的平台端侧）。
 *
 * 在此之前：用户侧能开单（`POST /api/tickets`，SLA 24h 已写库），
 * 但**平台端没有列表、没有详情、没有回复入口** —— 工单进了一个没人看的池子。
 * `TicketController` 的注释当时也如实写了"平台侧处理界面属第 10 步"。
 *
 * 职责边界（本轮）：
 *   - **平台受理**：平台客服接单、回复、改状态、指派给自己的同事。
 *   - **不派给开发者**：`support_ticket.developer_id` 字段在，但"把工单转给技能作者、
 *     作者在开发者门户里回复"这条链还没做（属第 9 步后段）。所以这里不做"转派给开发者"，
 *     免得出现一个按钮点了没反应的假功能。
 */
class SupportTicketController extends Controller
{
    public function __construct(protected OperationLogger $oplog) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(SupportTicketDataGrid::class)->process();
        }

        return view('admin::customers.tickets.index');
    }

    public function show(int $id)
    {
        $ticket = SupportTicket::with(['messages'])->find($id);

        if (! $ticket) {
            session()->flash('error', '工单不存在。');

            return redirect()->route('admin.customers.tickets.index');
        }

        $customer = $ticket->customer_id
            ? \Webkul\Customer\Models\Customer::find($ticket->customer_id)
            : null;

        return view('admin::customers.tickets.show', compact('ticket', 'customer'));
    }

    /**
     * 平台方回复。
     *
     * 两个副作用是刻意的：
     *   ① 首次回复自动把状态从「待受理」推到「处理中」—— 运营不该还要额外点一次状态；
     *   ② 无主的单自动指派给当前管理员 —— 否则"谁在处理"永远查不出来。
     */
    public function reply(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $adminId = auth()->guard('admin')->user()->id;

        TicketMessage::create([
            'ticket_id'  => $ticket->id,
            'actor_type' => 'admin',
            'actor_id'   => $adminId,
            'content'    => $data['content'],
            'created_at' => now(),
        ]);

        if ($ticket->status === SupportTicket::STATUS_OPEN) {
            $ticket->status = SupportTicket::STATUS_PROCESSING;
        }

        // 只在"还没人认领"时自动指派 —— 不抢别人已经在处理的单
        if (! $ticket->assignee_id) {
            $ticket->assignee_id = $adminId;
        }

        $ticket->save();

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $adminId,
            'ticket.reply',
            'support_ticket',
            $ticket->id,
            ['status' => $ticket->status],
            $request->ip()
        );

        session()->flash('success', '已回复。');

        return redirect()->route('admin.customers.tickets.show', $ticket->id);
    }

    /**
     * 改状态。
     *
     * ⚠ **结单必须同时给用户一个交代**：把状态直接推进到「已解决」却不回复，
     *   用户端只会看到状态变了、没有任何说明。所以这里不额外造规则去拦
     *   （运营有时确实只是整理积压单），但界面上把"最后一条消息是谁发的"显示出来。
     */
    public function updateStatus(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'status'   => 'required|in:'.implode(',', [
                SupportTicket::STATUS_OPEN,
                SupportTicket::STATUS_PROCESSING,
                SupportTicket::STATUS_RESOLVED,
                SupportTicket::STATUS_CLOSED,
            ]),
            'priority' => 'nullable|in:low,normal,high,urgent',
        ]);

        $ticket->status = $data['status'];

        if (! empty($data['priority'])) {
            $ticket->priority = $data['priority'];
        }

        $ticket->save();

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'ticket.status',
            'support_ticket',
            $ticket->id,
            ['status' => $ticket->status, 'priority' => $ticket->priority],
            $request->ip()
        );

        session()->flash('success', '工单状态已更新。');

        return redirect()->route('admin.customers.tickets.show', $ticket->id);
    }
}
