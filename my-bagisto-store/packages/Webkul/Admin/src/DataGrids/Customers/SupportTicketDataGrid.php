<?php

namespace Webkul\Admin\DataGrids\Customers;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\SupportTicket;

/**
 * 客服工单列表（v3.4 步骤 10 的平台端受理台）。
 *
 * 在此之前：用户能开单（`POST /api/tickets`，带 24h SLA），
 * 但**平台端没有任何入口能看见它** —— 工单池是个黑洞，SLA 只写在表里没人管。
 */
class SupportTicketDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('support_ticket')
            ->leftJoin('customers', 'customers.id', '=', 'support_ticket.customer_id')
            ->select(
                'support_ticket.id',
                'support_ticket.ticket_no',
                'support_ticket.category',
                'support_ticket.priority',
                'support_ticket.status',
                'support_ticket.assignee_id',
                'support_ticket.sla_due_at',
                'support_ticket.created_at',
                'customers.email'
            )
            ->orderBy('support_ticket.id', 'desc');

        $this->addFilter('id', 'support_ticket.id');
        $this->addFilter('status', 'support_ticket.status');
        $this->addFilter('priority', 'support_ticket.priority');

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
            'index'      => 'email',
            'label'      => '用户',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'category',
            'label'      => '类别',
            'type'       => 'string',
            'searchable' => true,
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
                    SupportTicket::STATUS_OPEN       => ['待受理', 'badge-danger'],
                    SupportTicket::STATUS_PROCESSING => ['处理中', 'badge-pending'],
                    SupportTicket::STATUS_RESOLVED   => ['已解决', 'badge-success'],
                    SupportTicket::STATUS_CLOSED     => ['已关闭', ''],
                ];

                [$label, $tone] = $map[$row->status] ?? [$row->status, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
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
                $settled = in_array($row->status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED], true);

                // 已结单的不再报超时 —— 超时只对"还欠着用户"的单有意义
                if (! $settled && $due->isPast()) {
                    return '<span class="text-red-700 font-medium">已超时 '.$due->diffForHumans().'</span>';
                }

                return '<span class="text-xs">'.$due->format('Y-m-d H:i').'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => '开单时间',
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
