<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\ContentSecurityRecord;

/**
 * 内容安全检测记录列表（v3.4 计划 §4.16）。
 *
 * ⚠ 默认视图只列**需要人看的**（review / block 且未处置）——
 *   机器判 pass 的记录不需要任何人再看一遍，把它们混进同一个列表
 *   会让队列永远清不完，真正需要处置的被淹掉。
 */
class ContentSecurityDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('content_security_record')
            ->select(
                'id', 'target_type', 'target_id', 'provider', 'scan_type',
                'result', 'labels', 'raw', 'disposition', 'disposition_note',
                'disposition_by', 'disposition_at', 'scanned_at'
            )
            ->orderBy('id', 'desc');

        $this->addFilter('id', 'id');
        $this->addFilter('result', 'result');
        $this->addFilter('disposition', 'disposition');

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
            'index'      => 'scanned_at',
            'label'      => '扫描时间',
            'type'       => 'datetime',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'target_type',
            'label'      => '检测对象',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => fn ($row) => e($row->target_type.' #'.$row->target_id),
        ]);

        $this->addColumn([
            'index'      => 'provider',
            'label'      => '驱动',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'result',
            'label'      => '机器判定',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '通过', 'value' => ContentSecurityRecord::RESULT_PASS],
                ['label' => '需人工看', 'value' => ContentSecurityRecord::RESULT_REVIEW],
                ['label' => '拦截', 'value' => ContentSecurityRecord::RESULT_BLOCK],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                $map = [
                    ContentSecurityRecord::RESULT_PASS   => ['通过', 'badge-success'],
                    ContentSecurityRecord::RESULT_REVIEW => ['需人工看', 'badge-pending'],
                    ContentSecurityRecord::RESULT_BLOCK  => ['拦截', 'badge-danger'],
                ];

                [$label, $tone] = $map[$row->result] ?? [$row->result, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'labels',
            'label'      => '命中',
            'type'       => 'string',
            'closure'    => function ($row) {
                $hits = json_decode($row->labels ?? '[]', true) ?: [];

                if ($hits === []) {
                    // degraded 的记录没有命中 —— 说清楚"为什么没有"，否则看起来像"干净"
                    $raw = json_decode($row->raw ?? '{}', true) ?: [];

                    return ! empty($raw['degraded'])
                        ? '<span class="text-amber-700 text-xs">未扫成（'.e($raw['message'] ?? '原因未知').'）</span>'
                        : '<span class="text-gray-400">—</span>';
                }

                return '<span class="text-red-700">'.e(implode('、', $hits)).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'disposition',
            'label'      => '人工处置',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '待处置', 'value' => ContentSecurityRecord::DISPOSITION_PENDING],
                ['label' => '已放行', 'value' => ContentSecurityRecord::DISPOSITION_CLEARED],
                ['label' => '已按违规处置', 'value' => ContentSecurityRecord::DISPOSITION_ACTIONED],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                $map = [
                    ContentSecurityRecord::DISPOSITION_PENDING  => ['待处置', 'badge-danger'],
                    ContentSecurityRecord::DISPOSITION_CLEARED  => ['已放行', 'badge-success'],
                    ContentSecurityRecord::DISPOSITION_ACTIONED => ['已按违规处置', 'badge-pending'],
                ];

                [$label, $tone] = $map[$row->disposition] ?? [$row->disposition, ''];

                // pass 的记录不需要人处置 —— 标出来，免得被当成"漏处理的"
                if ($row->result === ContentSecurityRecord::RESULT_PASS
                    && $row->disposition === ContentSecurityRecord::DISPOSITION_PENDING
                ) {
                    return '<span class="text-gray-400 text-xs">无需处置</span>';
                }

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-view',
            'title'  => '查看 / 处置',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.catalog.content-security.show', $row->id),
        ]);
    }
}
