<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * 技能类目后台列表（v3.4 步骤 6 的 Admin CRUD）。
 */
class SkillCategoryDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('skill_category')
            ->select('id', 'parent_id', 'code', 'name', 'sort_order', 'status')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');

        $this->addFilter('id', 'id');
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
            'index'      => 'name',
            'label'      => '名称',
            'type'       => 'string',
            'sortable'   => true,
            'closure'    => function ($row) {
                $name = json_decode($row->name ?? '{}', true) ?: [];

                return e($name['zh_CN'] ?? $name['en'] ?? '（未命名）');
            },
        ]);

        $this->addColumn([
            'index'      => 'code',
            'label'      => '编码',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'parent_id',
            'label'      => '上级类目',
            'type'       => 'integer',
            'closure'    => function ($row) {
                if (! $row->parent_id) {
                    return '<span class="text-gray-400">顶级</span>';
                }

                $parent = DB::table('skill_category')->where('id', $row->parent_id)->value('name');
                $name = json_decode($parent ?? '{}', true) ?: [];

                return e($name['zh_CN'] ?? $name['en'] ?? ('#'.$row->parent_id));
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '状态',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '启用', 'value' => 'active'],
                ['label' => '停用', 'value' => 'inactive'],
            ],
            'sortable'   => true,
            'closure'    => fn ($row) => $row->status === 'active'
                ? '<span class="badge badge-md badge-success">启用</span>'
                : '<span class="badge badge-md badge-pending">停用</span>',
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
            'url'    => fn ($row) => route('admin.catalog.skill-categories.edit', $row->id),
        ]);

        $this->addAction([
            'icon'   => 'icon-delete',
            'title'  => '删除',
            'method' => 'DELETE',
            'url'    => fn ($row) => route('admin.catalog.skill-categories.delete', $row->id),
        ]);
    }
}
