<?php

namespace Webkul\Admin\DataGrids\Settings;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\ComplianceFiling;

/**
 * 合规备案台账列表（计划 §4.17）。
 *
 * 排序把**快到期的排前面**：台账存在的意义就是提前看见到期，
 * 按录入时间排会让一条三个月后要年检的记录沉在列表底下。
 */
class ComplianceFilingDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('compliance_filing')
            ->select(
                'id', 'filing_type', 'name', 'filing_no', 'authority', 'scope',
                'filed_at', 'expires_at', 'annual_review_at', 'status', 'attachments'
            )
            // 有年检日期或到期日期的排前面，且早的优先；两者都空的沉底
            ->orderByRaw('COALESCE(annual_review_at, expires_at) IS NULL ASC')
            ->orderByRaw('COALESCE(annual_review_at, expires_at) ASC')
            ->orderByDesc('id');

        $this->addFilter('id', 'id');
        $this->addFilter('filing_type', 'filing_type');
        $this->addFilter('status', 'status');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'filing_type',
            'label'      => '备案类型',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => collect(ComplianceFiling::TYPE_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()->all(),
            'sortable'   => true,
            'closure'    => fn ($row) => e(ComplianceFiling::TYPE_LABELS[$row->filing_type] ?? $row->filing_type),
        ]);

        $this->addColumn([
            'index'      => 'name',
            'label'      => '名称 / 主体',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'filing_no',
            'label'      => '备案号',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => fn ($row) => e($row->filing_no ?: '—'),
        ]);

        $this->addColumn([
            'index'      => 'authority',
            'label'      => '主管单位',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => fn ($row) => e($row->authority ?: '—'),
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '状态',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => collect(ComplianceFiling::STATUS_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()->all(),
            'sortable'   => true,
            'closure'    => function ($row) {
                $map = [
                    ComplianceFiling::STATUS_PENDING   => ['待提交', 'badge-pending'],
                    ComplianceFiling::STATUS_SUBMITTED => ['审批中', 'badge-active'],
                    ComplianceFiling::STATUS_FILED     => ['已备案', 'badge-success'],
                    ComplianceFiling::STATUS_REJECTED  => ['被驳回', 'badge-danger'],
                    ComplianceFiling::STATUS_EXPIRED   => ['已过期', 'badge-danger'],
                    ComplianceFiling::STATUS_REVOKED   => ['已作废', ''],
                ];

                [$label, $tone] = $map[$row->status] ?? [$row->status, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'annual_review_at',
            'label'      => '年检',
            'type'       => 'date',
            'sortable'   => true,
            'closure'    => fn ($row) => e($row->annual_review_at ?: '—'),
        ]);

        $this->addColumn([
            'index'      => 'expires_at',
            'label'      => '到期',
            'type'       => 'date',
            'sortable'   => true,
            'closure'    => function ($row) {
                if (! $row->expires_at) {
                    return '—';
                }

                // 用模型来判"即将到期"，而不是在 DataGrid 里重写一遍规则 ——
                // 规则只该有一处（ComplianceFiling::isExpiringSoon）。
                $filing = (new ComplianceFiling)->newFromBuilder($row);

                return $filing->isExpiringSoon()
                    ? '<span class="text-red-700 font-medium">'.e($row->expires_at).' ⚠</span>'
                    : '<span class="text-xs">'.e($row->expires_at).'</span>';
            },
        ]);
    }

    public function prepareActions()
    {
        // ⚠ 只有「编辑」，**没有「删除」** —— 备案记录是"确实办过这件事"的凭据，
        //   能被抹掉的台账不是台账。作废请改状态为「已作废」并写明原因。
        $this->addAction([
            'icon'   => 'icon-edit',
            'title'  => '编辑',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.settings.compliance.edit', $row->id),
        ]);
    }
}
