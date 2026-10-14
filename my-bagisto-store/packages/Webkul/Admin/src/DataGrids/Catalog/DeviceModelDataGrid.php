<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\DeviceModel;

/**
 * 机型库后台列表（v3.4 步骤 5 的 Admin CRUD）。
 *
 * 在这之前机型只能靠 `DeviceModelSeeder` 或手写 SQL 维护 —— 而机型库是
 * **兼容性校验的输入**：加错一个通道声明，用户就会买到装不上的技能。
 */
class DeviceModelDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('device_model')
            ->select(
                'id', 'brand', 'model_code', 'model_name', 'vendor_code',
                'degrees_of_freedom', 'compute_platform', 'comm_channels',
                'status', 'sort_order'
            )
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');

        $this->addFilter('id', 'id');
        $this->addFilter('brand', 'brand');
        $this->addFilter('status', 'status');

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
            'index'      => 'model_name',
            'label'      => '机型',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'brand',
            'label'      => '厂商',
            'type'       => 'string',
            'filterable' => true,
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'model_code',
            'label'      => '机型编码',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'degrees_of_freedom',
            'label'      => '自由度',
            'type'       => 'integer',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'compute_platform',
            'label'      => '算力平台',
            'type'       => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'comm_channels',
            'label'      => '可用通道',
            'type'       => 'string',
            'closure'    => function ($row) {
                $channels = json_decode($row->comm_channels ?? '[]', true) ?: [];

                if ($channels === []) {
                    return '<span class="text-gray-400">无</span>';
                }

                $labels = ['agent' => '自动下发', 'manual' => '人工安装'];
                $out = [];

                foreach ($channels as $c) {
                    $out[] = '<span class="badge badge-md badge-active">'.($labels[$c] ?? $c).'</span>';
                }

                return implode(' ', $out);
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '分级',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '可交付', 'value' => DeviceModel::STATUS_ACTIVE],
                ['label' => '仅展示', 'value' => DeviceModel::STATUS_DISPLAY_ONLY],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                return $row->status === DeviceModel::STATUS_ACTIVE
                    ? '<span class="badge badge-md badge-success">可交付</span>'
                    : '<span class="badge badge-md badge-pending">仅展示</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'sort_order',
            'label'      => '排序',
            'type'       => 'integer',
            'sortable'   => true,
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-edit',
            'title'  => '编辑',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.catalog.device-models.edit', $row->id),
        ]);

        $this->addAction([
            'icon'   => 'icon-delete',
            'title'  => '删除',
            'method' => 'DELETE',
            'url'    => fn ($row) => route('admin.catalog.device-models.delete', $row->id),
        ]);
    }
}
