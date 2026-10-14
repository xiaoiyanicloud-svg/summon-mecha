<?php

namespace Webkul\Admin\DataGrids\Ops;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * 平台告警收件箱（定时任务产出的待办）。
 *
 * 为什么不能复用 Bagisto 的通知铃铛：它的 `notifications` 表是**订单专用**的
 * （核心列是 `order_id`，仓库层与界面都围绕订单构建）。本项目的告警不是订单事件，
 * 塞进去只会在那个界面里显示成一条无主的空记录。
 *
 * ⚠ 只看**当前登录管理员自己的**通知 —— 已读状态是每人一份的，
 *   共用一份会让"A 看过了"变成"B 也当作看过了"。
 */
class AdminAlertDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('notification')
            ->select('id', 'type', 'title', 'content', 'payload', 'read_at', 'created_at')
            ->where('recipient_type', 'admin')
            ->where('recipient_id', (int) auth()->guard('admin')->id())
            ->orderByDesc('id');

        $this->addFilter('id', 'id');
        $this->addFilter('type', 'type');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'created_at',
            'label'      => '时间',
            'type'       => 'datetime',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'title',
            'label'      => '告警',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => function ($row) {
                $payload = json_decode($row->payload ?? '{}', true) ?: [];
                $level = $payload['level'] ?? 'info';

                $tone = match ($level) {
                    'urgent' => 'badge-danger',
                    'warn'   => 'badge-pending',
                    default  => '',
                };

                $mark = $row->read_at ? '' : ' <span class="badge badge-md badge-active">未读</span>';

                return '<span class="badge badge-md '.$tone.'">'.e($row->title).'</span>'.$mark;
            },
        ]);

        $this->addColumn([
            'index'      => 'type',
            'label'      => '类型',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '备案到期', 'value' => 'compliance_due'],
                ['label' => '工单超时', 'value' => 'ticket_overdue'],
                ['label' => '事故未结案', 'value' => 'incident_open'],
                ['label' => '队列无人消费', 'value' => 'queue_stuck'],
                ['label' => '队列失败任务', 'value' => 'queue_failed'],
            ],
            'closure'    => function ($row) {
                $map = [
                    'compliance_due' => '备案到期',
                    'ticket_overdue' => '工单超时',
                    'incident_open'  => '事故未结案',
                    'queue_stuck'    => '队列无人消费',
                    'queue_failed'   => '队列失败任务',
                ];

                return e($map[$row->type] ?? $row->type);
            },
        ]);

        $this->addColumn([
            'index'      => 'content',
            'label'      => '内容',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => fn ($row) => nl2br(e(mb_substr((string) $row->content, 0, 160))),
        ]);

        $this->addColumn([
            'index'      => 'read_at',
            'label'      => '已读',
            'type'       => 'datetime',
            'sortable'   => true,
            'closure'    => fn ($row) => $row->read_at
                ? '<span class="text-xs text-gray-500">'.e($row->read_at).'</span>'
                : '<span class="text-red-700">未读</span>',
        ]);
    }

    public function prepareActions()
    {
        // 只提供「标记已读」—— 告警是系统产出的事实，不该被"删除"掉。
        // 待办清了就是清了，对得上账。
        $this->addAction([
            'icon'   => 'icon-done',
            'title'  => '标记已读',
            'method' => 'POST',
            'url'    => fn ($row) => route('admin.alerts.read', $row->id),
        ]);
    }
}
