<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Concerns\RejectsExportBypass;
use Webkul\DataGrid\DataGrid;

/**
 * 赛事(Event)后台列表。
 */
class EventDataGrid extends DataGrid
{
    use RejectsExportBypass;

    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('events')
            ->select('id', 'name', 'type', 'status', 'date', 'city', 'hot', 'teams', 'sort')
            ->orderBy('sort', 'desc')->orderBy('id', 'asc');

        $this->addFilter('id', 'id');

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
            'index'      => 'name',
            'label'      => '赛事名称',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'type',
            'label'      => '类型',
            'type'       => 'string',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '状态',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '报名中', 'value' => 'registering'],
                ['label' => '进行中', 'value' => 'ongoing'],
                ['label' => '即将开始', 'value' => 'upcoming'],
                ['label' => '已结束', 'value' => 'ended'],
            ],
            'sortable'   => true,
            'closure'    => function ($value) {
                $map = [
                    'registering' => ['报名中', 'badge-success'],
                    'ongoing'     => ['进行中', 'badge-active'],
                    'upcoming'    => ['即将开始', 'badge-pending'],
                    'ended'       => ['已结束', 'badge-danger'],
                ];

                return '<span class="badge badge-md '.($map[$value->status][1] ?? '').'">'.($map[$value->status][0] ?? $value->status).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'date',
            'label'      => '时间',
            'type'       => 'string',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'city',
            'label'      => '城市',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'hot',
            'label'      => '热度',
            'type'       => 'integer',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'teams',
            'label'      => '参赛队伍',
            'type'       => 'integer',
            'sortable'   => true,
        ]);
    }

    public function prepareActions()
    {
        if (bouncer()->hasPermission('catalog.events.edit')) {
            $this->addAction([
                'icon'   => 'icon-edit',
                'title'  => '编辑',
                'method' => 'GET',
                'url'    => function ($row) {
                    return route('admin.catalog.events.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('catalog.events.delete')) {
            $this->addAction([
                'icon'   => 'icon-delete',
                'title'  => '删除',
                'method' => 'DELETE',
                'url'    => function ($row) {
                    return route('admin.catalog.events.delete', $row->id);
                },
            ]);
        }
    }
}
