<?php

namespace Webkul\Admin\DataGrids\Ops;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * 开票登记台账（M9）。
 *
 * ⚠ 读的是**单数** `invoice` 表（中国税务发票/开票登记），
 *   不是 Bagisto 原生的 `invoices`（订单发货单）。两张表含义不同，别改错。
 *
 * ⚠ 本表在当前阶段**只承载"已登记待开具"**：真实开具（税控/PDF/回执）未接入，
 *   所以 `issued_at` / `file_path` 恒空。**刻意不提供"标记为已开具"的动作** ——
 *   手工点一下就多一批没有税务依据的"已开票"记录，比没有记录更坏。
 */
class InvoiceDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('invoice')
            ->select('id', 'customer_id', 'order_ids', 'type', 'title', 'tax_no', 'amount', 'status', 'issued_at', 'created_at')
            ->orderByDesc('id');

        $this->addFilter('id', 'id');
        $this->addFilter('status', 'status');
        $this->addFilter('customer_id', 'customer_id');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'    => 'created_at',
            'label'    => '登记时间',
            'type'     => 'datetime',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index'      => 'title',
            'label'      => '抬头',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'tax_no',
            'label'      => '税号',
            'type'       => 'string',
            'closure'    => fn ($row) => $row->tax_no
                ? e($row->tax_no)
                // 空税号 = "还没问过用户"，不是"用户是个人"。点明区别，避免被读成"个人票已确认"
                : '<span class="text-xs text-gray-500">未采集（无用户侧填单入口）</span>',
        ]);

        $this->addColumn([
            'index'      => 'type',
            'label'      => '抬头类型',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '个人', 'value' => 'personal'],
                ['label' => '企业', 'value' => 'company'],
            ],
            'closure'    => function ($row) {
                $map = ['personal' => '个人', 'company' => '企业'];

                return e($map[$row->type] ?? $row->type);
            },
        ]);

        $this->addColumn([
            'index'    => 'amount',
            'label'    => '金额',
            'type'     => 'string',
            'sortable' => true,
            'closure'  => fn ($row) => '¥'.number_format((float) $row->amount, 2),
        ]);

        $this->addColumn([
            'index'      => 'order_ids',
            'label'      => '订单',
            'type'       => 'string',
            'closure'    => function ($row) {
                $ids = array_map('intval', json_decode($row->order_ids ?? '[]', true) ?: []);

                return $ids === [] ? '—' : '订单 #'.implode(' / #', $ids);
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '状态',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '已登记待开具', 'value' => 'applied'],
                ['label' => '已开具', 'value' => 'issued'],
            ],
            'closure'    => function ($row) {
                // ⚠ 文案要点明"待开具"：只写"已登记"会被读成"票已经开好了"
                return $row->status === 'issued'
                    ? '<span class="badge badge-md badge-active">已开具</span>'
                    : '<span class="badge badge-md badge-pending">已登记待开具</span>';
            },
        ]);

        $this->addColumn([
            'index'    => 'issued_at',
            'label'    => '开具时间',
            'type'     => 'datetime',
            'sortable' => true,
            'closure'  => fn ($row) => $row->issued_at
                ? e($row->issued_at)
                : '<span class="text-xs text-gray-500">未开具（平台未接入开票服务商）</span>',
        ]);
    }

    public function prepareActions()
    {
        // 刻意**没有动作**：这一版只做到"登记"，而"标记为已开具"会造出没有税务依据的
        // 记录（见类注释）；"补开登记"要等有直挂订单号列 + 唯一键之后才安全
        // （见 InvoiceService 类注释的遗留边界）。
        // 空实现不是遗漏 —— 是这一版的能力边界。
    }
}
