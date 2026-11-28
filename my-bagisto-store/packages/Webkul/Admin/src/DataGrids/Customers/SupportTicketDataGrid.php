<?php

namespace Webkul\Admin\DataGrids\Customers;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\SupportTicket;

/**
 * 客服工单列表（v3.4 步骤 10 的平台端受理台）。
 */
class SupportTicketDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('support_ticket')
            ->leftJoin('customers', 'customers.id', '=', 'support_ticket.customer_id')
            ->leftJoin('admins', 'admins.id', '=', 'support_ticket.assignee_id')
            ->select(
                'support_ticket.id',
                'support_ticket.ticket_no',
                'support_ticket.service_mode',
                'support_ticket.queued_at',
                'support_ticket.category',
                'support_ticket.priority',
                'support_ticket.status',
                'support_ticket.assignee_id',
                'support_ticket.held_at',
                'support_ticket.sla_due_at',
                'support_ticket.updated_at',
                'support_ticket.created_at',
                'customers.email',
                'admins.name as assignee_name'
            )
            ->orderByRaw("CASE WHEN support_ticket.service_mode = 'online' AND support_ticket.status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('support_ticket.updated_at')
            ->orderByDesc('support_ticket.id');

        $this->addFilter('id', 'support_ticket.id');
        $this->addFilter('service_mode', 'support_ticket.service_mode');
        $this->addFilter('category', 'support_ticket.category');
        $this->addFilter('status', 'support_ticket.status');
        $this->addFilter('priority', 'support_ticket.priority');
        $this->addFilter('assignee_name', 'admins.name');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'ticket_no',
            'label'      => '工单号',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'service_mode',
            'label'      => '模式',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '在线客服', 'value' => SupportTicket::SERVICE_ONLINE],
                ['label' => '留言工单', 'value' => SupportTicket::SERVICE_ASYNC],
            ],
            'closure'    => function ($row) {
                return $row->service_mode === SupportTicket::SERVICE_ONLINE
                    ? '<span class="badge badge-md badge-active">在线</span>'
                    : '<span class="badge badge-md">留言</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'email',
            'label'      => '用户',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'              => 'category',
            'label'              => '问题分类',
            'type'               => 'string',
            'searchable'         => true,
            'filterable'         => true,
            'filterable_options' => array_map(
                fn (string $label, string $value) => ['label' => $label, 'value' => $value],
                SupportTicket::CATEGORY_LABELS,
                array_keys(SupportTicket::CATEGORY_LABELS)
            ),
            'closure'            => function ($row) {
                $label = SupportTicket::CATEGORY_LABELS[$row->category] ?? ($row->category ?: '其他');
                $tone = [
                    SupportTicket::CATEGORY_DELIVERY => 'badge-active',
                    SupportTicket::CATEGORY_SKILL    => 'badge-info',
                    SupportTicket::CATEGORY_DEVICE   => 'badge-pending',
                    SupportTicket::CATEGORY_PAYMENT  => 'badge-danger',
                    SupportTicket::CATEGORY_OTHER    => '',
                ][$row->category] ?? '';

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'priority',
            'label'      => '优先级',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '低', 'value' => 'low'],
                ['label' => '普通', 'value' => 'normal'],
                ['label' => '高', 'value' => 'high'],
                ['label' => '紧急', 'value' => 'urgent'],
            ],
            'closure'    => function ($row) {
                $map = [
                    'low'    => ['低', 'badge-pending'],
                    'normal' => ['普通', ''],
                    'high'   => ['高', 'badge-active'],
                    'urgent' => ['紧急', 'badge-danger'],
                ];

                [$label, $tone] = $map[$row->priority] ?? [$row->priority, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '状态',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '待受理', 'value' => SupportTicket::STATUS_OPEN],
                ['label' => '处理中', 'value' => SupportTicket::STATUS_PROCESSING],
                ['label' => '已解决', 'value' => SupportTicket::STATUS_RESOLVED],
                ['label' => '已关闭', 'value' => SupportTicket::STATUS_CLOSED],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                $map = [
                    SupportTicket::STATUS_OPEN       => [$row->service_mode === SupportTicket::SERVICE_ONLINE ? '排队中' : '待受理', 'badge-danger'],
                    SupportTicket::STATUS_PROCESSING => [$row->service_mode === SupportTicket::SERVICE_ONLINE ? '已接入' : '处理中', 'badge-pending'],
                    SupportTicket::STATUS_RESOLVED   => ['已解决', 'badge-success'],
                    SupportTicket::STATUS_CLOSED     => ['已关闭', ''],
                ];

                [$label, $tone] = $map[$row->status] ?? [$row->status, ''];

                $held = ! empty($row->held_at)
                    ? ' <span class="badge badge-md badge-pending">挂起</span>'
                    : '';

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>'.$held;
            },
        ]);

        $this->addColumn([
            'index'      => 'assignee_name',
            'label'      => '受理人',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => function ($row) {
                if (empty($row->assignee_name)) {
                    return '<span class="text-xs text-gray-400">待认领</span>';
                }

                return '<span class="text-xs font-medium text-gray-700 dark:text-gray-200">'.e($row->assignee_name).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'sla_due_at',
            'label'      => 'SLA 期限',
            'type'       => 'datetime',
            'sortable'   => true,
            'closure'    => function ($row) {
                if (! $row->sla_due_at) {
                    return '—';
                }

                $due = \Illuminate\Support\Carbon::parse($row->sla_due_at);
                $settled = in_array($row->status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED], true)
                    || ! empty($row->held_at);

                if (! $settled && $due->isPast()) {
                    return '<span class="text-red-700 font-medium">已超时 '.$due->diffForHumans().'</span>';
                }

                return '<span class="text-xs">'.$due->format('Y-m-d H:i').'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'updated_at',
            'label'      => '最近更新',
            'type'       => 'datetime',
            'sortable'   => true,
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-view',
            'title'  => '处理',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.customers.tickets.show', $row->id),
        ]);
    }
}
