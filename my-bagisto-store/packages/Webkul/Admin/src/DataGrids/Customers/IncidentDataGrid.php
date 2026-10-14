<?php

namespace Webkul\Admin\DataGrids\Customers;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\Incident;

/**
 * 事故记录列表（计划 §4.19）。
 *
 * 排序刻意把**未结案的排在前面**：已结案的事故是历史，
 * 未结案的是平台当前的风险敞口 —— 后者才是每天要盯的。
 */
class IncidentDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('incident')
            ->leftJoin('customers', 'customers.id', '=', 'incident.customer_id')
            ->select(
                'incident.id',
                'incident.incident_no',
                'incident.severity',
                'incident.status',
                'incident.responsibility',
                'incident.occurred_at',
                'incident.created_at',
                'incident.skill_id',
                'incident.customer_id',
                'customers.email'
            )
            // 未结案的排前面（resolved/closed 视为已结）
            ->orderByRaw("FIELD(incident.status, 'reported', 'investigating', 'attributed', 'resolved', 'closed')")
            ->orderByDesc('incident.id');

        $this->addFilter('id', 'incident.id');
        $this->addFilter('status', 'incident.status');
        $this->addFilter('severity', 'incident.severity');
        $this->addFilter('responsibility', 'incident.responsibility');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'incident_no',
            'label'      => '事故编号',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'severity',
            'label'      => '严重程度',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => collect(Incident::SEVERITY_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()->all(),
            'sortable'   => true,
            'closure'    => function ($row) {
                $map = [
                    Incident::SEVERITY_MINOR    => ['轻微', 'badge-pending'],
                    Incident::SEVERITY_MODERATE => ['一般', 'badge-active'],
                    Incident::SEVERITY_SEVERE   => ['严重', 'badge-danger'],
                    Incident::SEVERITY_CRITICAL => ['重大', 'badge-danger'],
                ];

                [$label, $tone] = $map[$row->severity] ?? [($row->severity ?: '未填'), ''];

                // 严重及以上要一眼看得见 —— 那是应急响应的触发线
                $mark = in_array($row->severity, [Incident::SEVERITY_SEVERE, Incident::SEVERITY_CRITICAL], true)
                    ? ' ⚠'
                    : '';

                return '<span class="badge badge-md '.$tone.'">'.e($label.$mark).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '处理状态',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => collect(Incident::STATUS_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()->all(),
            'sortable'   => true,
            'closure'    => function ($row) {
                $map = [
                    Incident::STATUS_REPORTED      => ['待受理', 'badge-danger'],
                    Incident::STATUS_INVESTIGATING => ['调查中', 'badge-pending'],
                    Incident::STATUS_ATTRIBUTED    => ['已定责', 'badge-active'],
                    Incident::STATUS_RESOLVED      => ['已解决', 'badge-success'],
                    Incident::STATUS_CLOSED        => ['已结案', ''],
                ];

                [$label, $tone] = $map[$row->status] ?? [$row->status, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'responsibility',
            'label'      => '责任认定',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => collect(Incident::RESPONSIBILITY_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()->all(),
            'closure'    => fn ($row) => e(Incident::RESPONSIBILITY_LABELS[$row->responsibility] ?? '尚未认定'),
        ]);

        $this->addColumn([
            'index'      => 'email',
            'label'      => '报案人',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => fn ($row) => e($row->email ?? '—'),
        ]);

        $this->addColumn([
            'index'      => 'occurred_at',
            'label'      => '发生时间',
            'type'       => 'datetime',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => '上报时间',
            'type'       => 'datetime',
            'sortable'   => true,
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-view',
            'title'  => '受理',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.customers.incidents.show', $row->id),
        ]);
    }
}
