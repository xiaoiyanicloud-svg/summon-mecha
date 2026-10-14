<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\SkillTag;

/**
 * 技能标签后台列表（v3.4 步骤 6 的 Admin CRUD）。
 */
class SkillTagDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('skill_tag')
            ->select('id', 'name', 'type', 'sort_order')
            ->orderBy('type', 'asc')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');

        $this->addFilter('id', 'id');
        $this->addFilter('type', 'type');

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
            'label'      => '标签',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'type',
            'label'      => '分组',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => collect(SkillTag::TYPE_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()
                ->all(),
            'sortable'   => true,
            'closure'    => fn ($row) => e(SkillTag::TYPE_LABELS[$row->type] ?? $row->type),
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
            'url'    => fn ($row) => route('admin.catalog.skill-tags.edit', $row->id),
        ]);

        $this->addAction([
            'icon'   => 'icon-delete',
            'title'  => '删除',
            'method' => 'DELETE',
            'url'    => fn ($row) => route('admin.catalog.skill-tags.delete', $row->id),
        ]);
    }
}
