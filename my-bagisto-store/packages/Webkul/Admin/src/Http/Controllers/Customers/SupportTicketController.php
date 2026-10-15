<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Customers\SupportTicketDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;
use Webkul\Shop\Services\Incident\IncidentService;
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
    public function __construct(
        protected OperationLogger $oplog,
        protected IncidentService $incidents,
    ) {}

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

        // B-10:若本工单已被定性为事故,在详情页给出互链
        $incident = $ticket->incident;

        return view('admin::customers.tickets.show', compact('ticket', 'customer', 'incident'));
    }

    /**
     * 定性为事故(B-10)。
     *
     * 计划要求的链:**用户投诉 → 工单 → 若定性事故 → incident**。这里是把工单
     * 升级成一起事故的**唯一入口**:走与用户报案同一个 `IncidentService::report()`
     * (证据链照样在"这一刻"固化),并在 incident 侧记下肇事工单 `ticket_id`。
     *
     * 两条硬约束:
     *   ① 工单必须**有归属客户** —— `incident.customer_id` 是 NOT NULL,无主工单装不下;
     *   ② **不能重复定性** —— 一张工单最多对应一起事故,否则追溯链会分叉。
     */
    public function escalate(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        if (! $ticket->customer_id) {
            session()->flash('error', '该工单没有关联客户,无法定性为事故。');

            return redirect()->route('admin.customers.tickets.show', $id);
        }

        if ($ticket->incident) {
            session()->flash('error', '该工单已被定性为事故('.$ticket->incident->incident_no.'),不能重复定性。');

            return redirect()->route('admin.customers.tickets.show', $id);
        }

        $data = $request->validate([
            'severity'    => 'required|in:'.implode(',', array_keys(Incident::SEVERITY_LABELS)),
            'description' => 'required|string|max:2000',
            'occurred_at' => 'nullable|date|before_or_equal:now',
        ]);

        $incident = $this->incidents->report($ticket->customer_id, [
            // 工单上的 skill_id 就是商品 id(开单时校验过归属),直接带过去
            'skill_id'    => $ticket->skill_id,
            'severity'    => $data['severity'],
            'occurred_at' => $data['occurred_at'] ?? null,
            'description' => $data['description'],
            'ticket_id'   => $ticket->id,
        ]);

        // 工单侧留一条系统留言 + 状态推进 —— 否则工单看上去"没人管"了
        TicketMessage::create([
            'ticket_id'  => $ticket->id,
            'actor_type' => 'system',
            'actor_id'   => null,
            'content'    => "本工单已定性为事故，转事故追溯流程处理（事故编号 {$incident->incident_no}）。后续处理进展见事故记录。",
            'created_at' => now(),
        ]);

        if ($ticket->status === SupportTicket::STATUS_OPEN) {
            $ticket->status = SupportTicket::STATUS_PROCESSING;
        }
        $ticket->save();

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'ticket.escalate_incident',
            'support_ticket',
            $ticket->id,
            [
                'incident_id' => $incident->id,
                'incident_no' => $incident->incident_no,
                'severity'    => $incident->severity,
            ],
            $request->ip()
        );

        session()->flash('success', "已定性为事故 {$incident->incident_no}。");

        return redirect()->route('admin.customers.incidents.show', $incident->id);
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
