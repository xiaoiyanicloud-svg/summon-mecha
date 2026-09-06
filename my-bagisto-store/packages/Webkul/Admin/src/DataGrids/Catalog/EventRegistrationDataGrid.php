<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * 赛事报名(EventRegistration)后台列表 —— 闭环转化数据,供运营跟进。
 */
class EventRegistrationDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('event_registrations')
            ->leftJoin('events', 'events.id', '=', 'event_registrations.event_id')
            ->select(
                'event_registrations.id',
                'events.name as event_name',
                'event_registrations.name',
                'event_registrations.phone',
                'event_registrations.city',
                'event_registrations.created_at'
            )
            ->orderBy('event_registrations.id', 'desc');

        $this->addFilter('id', 'event_registrations.id');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => 'ID',
            'type'       => 'integer',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'event_name',
            'label'      => '赛事',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'name',
            'label'      => '联系人',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'phone',
            'label'      => '手机号',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'city',
            'label'      => '城市',
            'type'       => 'string',
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => '报名时间',
            'type'       => 'string',
            'sortable'   => true,
        ]);
    }
}
