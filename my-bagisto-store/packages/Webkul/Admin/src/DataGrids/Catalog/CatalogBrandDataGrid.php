<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Webkul\DataGrid\DataGrid;

/**
 * 后台「品牌管理」数据表格。
 *
 * 维护前列品牌 Logo、唯一编码、热门 TOP、启停状态、关联机型库品牌与属性选项映射。
 */
class CatalogBrandDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('catalog_brands as cb')
            ->select(
                'cb.id',
                'cb.code',
                'cb.name',
                'cb.name_en',
                'cb.device_brand',
                'cb.logo_path',
                'cb.is_hot',
                'cb.status',
                'cb.sort_order',
                'cb.attribute_option_id'
            );

        if (Schema::hasTable('device_model')) {
            $queryBuilder->addSelect(DB::raw(
                '(SELECT COUNT(*) FROM device_model dm WHERE dm.brand = cb.device_brand) as models_count'
            ));
        } else {
            $queryBuilder->addSelect(DB::raw('0 as models_count'));
        }

        $queryBuilder->orderBy('cb.sort_order', 'asc')->orderBy('cb.id', 'asc');

        $this->addFilter('id', 'cb.id');
        $this->addFilter('name', 'cb.name');
        $this->addFilter('code', 'cb.code');
        $this->addFilter('device_brand', 'cb.device_brand');
        $this->addFilter('is_hot', 'cb.is_hot');
        $this->addFilter('status', 'cb.status');

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
            'index'      => 'logo_path',
            'label'      => 'Logo',
            'type'       => 'string',
            'searchable' => false,
            'sortable'   => false,
            'closure'    => function ($row) {
                if (! $row->logo_path) {
                    $initial = mb_substr((string) $row->name, 0, 2);

                    return '<span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600 dark:bg-gray-800 dark:text-gray-300">'
                        .e($initial).'</span>';
                }

                $url = Storage::url($row->logo_path);

                return '<img src="'.e($url).'" alt="'.e($row->name).'" class="h-10 w-10 rounded-lg border border-gray-200 object-contain bg-white p-0.5" />';
            },
        ]);

        $this->addColumn([
            'index'      => 'name',
            'label'      => '品牌名称',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
            'closure'    => function ($row) {
                $en = $row->name_en ? '<div class="text-xs text-gray-500">'.e($row->name_en).'</div>' : '';

                return '<div class="font-medium text-gray-900 dark:text-white">'.e($row->name).'</div>'.$en;
            },
        ]);

        $this->addColumn([
            'index'      => 'code',
            'label'      => '编码',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
            'closure'    => fn ($row) => '<code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">'.e($row->code).'</code>',
        ]);

        $this->addColumn([
            'index'      => 'device_brand',
            'label'      => '机型库厂商',
            'type'       => 'string',
            'filterable' => true,
            'searchable' => true,
            'closure'    => function ($row) {
                if (! $row->device_brand) {
                    return '<span class="text-xs text-gray-400">未映射</span>';
                }

                return '<span class="badge badge-md badge-info">'.e($row->device_brand).'（'.(int) $row->models_count.' 机型）</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'is_hot',
            'label'      => '热门 TOP',
            'type'       => 'boolean',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '热门', 'value' => 1],
                ['label' => '常规', 'value' => 0],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                $on = (bool) $row->is_hot;
                $url = route('admin.catalog.brands.toggle_hot', $row->id);
                $cls = $on ? 'badge badge-md badge-warning cursor-pointer transition hover:opacity-80'
                    : 'badge badge-md badge-secondary cursor-pointer transition hover:opacity-80';
                $label = $on ? '热门 TOP' : '常规';

                return '<button type="button" onclick="window.__brandToggleHot(this)" data-route="'.e($url).'" class="'.$cls.'">'.$label.'</button>';
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '状态',
            'type'       => 'boolean',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '启用', 'value' => 1],
                ['label' => '停用', 'value' => 0],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                $on = (bool) $row->status;
                $url = route('admin.catalog.brands.toggle_status', $row->id);
                $cls = $on ? 'badge badge-md badge-success cursor-pointer transition hover:opacity-80'
                    : 'badge badge-md badge-danger cursor-pointer transition hover:opacity-80';
                $label = $on ? '启用' : '停用';

                return '<button type="button" onclick="window.__brandToggleStatus(this)" data-route="'.e($url).'" data-current="'.($on ? '1' : '0').'" class="'.$cls.'">'.$label.'</button>';
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
        if (bouncer()->hasPermission('catalog.brands.edit')) {
            $this->addAction([
                'icon'   => 'icon-edit',
                'title'  => '编辑品牌',
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.catalog.brands.edit', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('catalog.brands.delete')) {
            $this->addAction([
                'icon'   => 'icon-delete',
                'title'  => '删除品牌',
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.catalog.brands.delete', $row->id),
            ]);
        }
    }
}
