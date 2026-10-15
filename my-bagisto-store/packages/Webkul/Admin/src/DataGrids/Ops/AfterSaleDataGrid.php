<?php

namespace Webkul\Admin\DataGrids\Ops;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * 售后/退款台账（B-1）。
 *
 * 为什么需要它：`after_sale` 表**已有真实申请**，但平台端此前**没有任何页面** ——
 *   `AfterSaleService::approve()` 全仓零调用方，用户点完退款就永远停在"已受理"。
 *   本表是"平台端审批入口"的列表那一半。
 *
 * ⚠ 与 Bagisto 原生退款（订单退款单 `refunds`）不是一回事：
 *   那张是电商订单退款，这张是**虚拟商品（技能）的售后申请**（D10 政策）。
 *
 * ⚠ 本表**只展示 + 跳详情处置**，不在列表行上放"批准"按钮 —— 退款是资金动作，
 *   必须进详情页看清金额与政策依据、并二次确认（见 `ops/after-sales/show.blade.php`）。
 */
class AfterSaleDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('after_sale')
            ->leftJoin('customers', 'after_sale.customer_id', '=', 'customers.id')
            ->select(
                'after_sale.id',
                'after_sale.customer_id',
                'after_sale.order_id',
                'after_sale.order_item_id',
                'after_sale.entitlement_id',
                'after_sale.type',
                'after_sale.reason_category',
                'after_sale.policy_basis',
                'after_sale.status',
                'after_sale.reviewer_id',
                'after_sale.reviewed_at',
                'after_sale.created_at',
                'customers.first_name as customer_first_name',
                'customers.last_name as customer_last_name',
            )
            ->orderByDesc('after_sale.id');

        $this->addFilter('id', 'after_sale.id');
        $this->addFilter('status', 'after_sale.status');
        $this->addFilter('type', 'after_sale.type');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'    => 'id',
            'label'    => 'ID',
            'type'     => 'integer',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'    => 'created_at',
            'label'    => '申请时间',
            'type'     => 'datetime',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index'   => 'customer_first_name',
            'label'   => '客户',
            'type'    => 'string',
            'closure' => function ($row) {
                $name = trim((string) $row->customer_first_name.' '.(string) $row->customer_last_name);

                $who = $name !== '' ? e($name) : '<span class="text-gray-400 text-xs">未知</span>';

                return $who.' <span class="text-xs text-gray-500">#'.(int) $row->customer_id.'</span>';
            },
        ]);

        $this->addColumn([
            'index'   => 'entitlement_id',
            'label'   => '授权 / 订单条目',
            'type'    => 'string',
            'closure' => fn ($row) => '授权 #'.(int) $row->entitlement_id
                .' <span class="text-xs text-gray-500">· 条目 #'.(int) $row->order_item_id.'</span>',
        ]);

        $this->addColumn([
            'index'   => 'policy_basis',
            'label'   => '政策依据',
            'type'    => 'string',
            'closure' => function ($row) {
                $map = [
                    'not_installed' => ['未下发·可退', 'badge-success'],
                    'activated'     => ['已激活·需人工审', 'badge-pending'],
                ];

                [$label, $tone] = $map[$row->policy_basis] ?? [$row->policy_basis ?: '—', ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'              => 'status',
            'label'              => '状态',
            'type'               => 'string',
            'filterable'         => true,
            'sortable'           => true,
            'filterable_options' => [
                ['label' => '已受理（待批）', 'value' => 'applied'],
                ['label' => '人工审核中', 'value' => 'reviewing'],
                ['label' => '已批准（退款中）', 'value' => 'approved'],
                ['label' => '已驳回', 'value' => 'rejected'],
                ['label' => '已退款', 'value' => 'refunded'],
            ],
            'closure'    => function ($row) {
                $map = [
                    'applied'   => ['已受理（待批）', 'badge-pending'],
                    'reviewing' => ['人工审核中', 'badge-pending'],
                    'approved'  => ['已批准（退款中）', 'badge-pending'],
                    'rejected'  => ['已驳回', 'badge-danger'],
                    'refunded'  => ['已退款', 'badge-success'],
                ];

                [$label, $tone] = $map[$row->status] ?? [$row->status, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'   => 'reviewed_at',
            'label'   => '复核',
            'type'    => 'datetime',
            'sortable' => true,
            'closure' => fn ($row) => $row->reviewed_at
                ? e($row->reviewed_at).' <span class="text-xs text-gray-500">by #'.(int) $row->reviewer_id.'</span>'
                : '<span class="text-xs text-gray-500">未复核</span>',
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-view',
            'title'  => '查看 / 审批',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.after-sales.show', $row->id),
        ]);
    }
}
