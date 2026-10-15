<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\SkillReport;

/**
 * 举报列表（加固计划 §四 A1 的管理端那半截）。
 *
 * 默认视图按 id 倒序 = **最新举报在最上面**（与内容安全表同款）。
 * 只列未处置的最紧急 —— 但举报不该被隐藏，处置完的记录也要能翻到（可筛 status），
 * 因为"我们报过这个技能、当时判了不成立"本身就是事后要能查的凭据。
 *
 * ⚠ 技能名走 `product_flat`（与 SkillCatalogController / ProductDataGrid 同源；
 *   用 leftJoin 且**只在 target_type=skill 时**才接上 —— 评价举报的 target_id 是
 *   `skill_review.id`，若不加这个条件，评价 id 会撞到同号的商品上，显示成完全无关的技能名）。
 */
class SkillReportDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('skill_report')
            ->leftJoin('customers', 'skill_report.reporter_customer_id', '=', 'customers.id')
            ->leftJoin('product_flat', function ($join) {
                $join->on('product_flat.product_id', '=', 'skill_report.target_id')
                    ->where('skill_report.target_type', '=', SkillReport::TARGET_SKILL)
                    ->where('product_flat.channel', '=', core()->getCurrentChannelCode())
                    ->where('product_flat.locale', '=', app()->getLocale());
            })
            ->select(
                'skill_report.id',
                'skill_report.target_type',
                'skill_report.target_id',
                'skill_report.reason_category',
                'skill_report.detail',
                'skill_report.status',
                'skill_report.handler_id',
                'skill_report.handled_at',
                'skill_report.created_at',
                'customers.first_name as reporter_first_name',
                'customers.last_name as reporter_last_name',
                'product_flat.name as product_name',
            )
            ->orderBy('skill_report.id', 'desc');

        $this->addFilter('id', 'skill_report.id');
        $this->addFilter('status', 'skill_report.status');
        $this->addFilter('target_type', 'skill_report.target_type');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => 'ID',
            'type'       => 'integer',
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => '举报时间',
            'type'       => 'datetime',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'target_id',
            'label'      => '举报对象',
            'type'       => 'string',
            'closure'    => function ($row) {
                if ($row->target_type === SkillReport::TARGET_SKILL) {
                    return e($row->product_name ?: '技能 #'.$row->target_id);
                }

                return '<span class="text-gray-500">评价 #'.e($row->target_id).'</span>';
            },
        ]);

        $this->addColumn([
            'index'              => 'reason_category',
            'label'              => '理由',
            'type'               => 'string',
            'filterable'         => true,
            'filterable_options' => collect(SkillReport::REASON_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()
                ->all(),
            'closure'            => fn ($row) => e(
                SkillReport::REASON_LABELS[$row->reason_category] ?? (string) $row->reason_category
            ),
        ]);

        $this->addColumn([
            'index'   => 'detail',
            'label'   => '举报正文',
            'type'    => 'string',
            'closure' => function ($row) {
                $detail = trim((string) $row->detail);

                if ($detail === '') {
                    // 正文是选填的（能指出"哪里不对"就够立案）—— 空要说清是"没写"，不是"没加载"
                    return '<span class="text-gray-400 text-xs">未填写</span>';
                }

                return e(mb_strimwidth($detail, 0, 60, '…'));
            },
        ]);

        $this->addColumn([
            'index'   => 'reporter_first_name',
            'label'   => '举报人',
            'type'    => 'string',
            'closure' => function ($row) {
                $name = trim((string) $row->reporter_first_name.' '.(string) $row->reporter_last_name);

                return $name !== '' ? e($name) : '<span class="text-gray-400 text-xs">未知</span>';
            },
        ]);

        $this->addColumn([
            'index'              => 'status',
            'label'              => '状态',
            'type'               => 'string',
            'filterable'         => true,
            'filterable_options' => collect(SkillReport::STATUS_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()
                ->all(),
            'sortable'           => true,
            'closure'            => function ($row) {
                $map = [
                    SkillReport::STATUS_PENDING  => ['待处置', 'badge-danger'],
                    SkillReport::STATUS_ACCEPTED => ['举报成立', 'badge-success'],
                    SkillReport::STATUS_REJECTED => ['举报不成立', 'badge-pending'],
                ];

                [$label, $tone] = $map[$row->status] ?? [$row->status, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'   => 'handled_at',
            'label'   => '处置于',
            'type'    => 'datetime',
            'closure' => function ($row) {
                if (! $row->handled_at) {
                    return '<span class="text-gray-400 text-xs">—</span>';
                }

                return e(\Illuminate\Support\Carbon::parse($row->handled_at)->format('Y-m-d H:i'));
            },
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-view',
            'title'  => '查看 / 处置',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.catalog.skill-reports.show', $row->id),
        ]);
    }
}
