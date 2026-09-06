<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * 场景(Scene)后台列表 —— 照抄分类 DataGrid,去掉多语言 join。
 */
class SceneDataGrid extends DataGrid
{
    /**
     * Primary column.
     */
    protected $primaryColumn = 'id';

    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('scenes')
            ->select('id', 'name', 'category', 'status', 'sort', 'is_hot', 'consult_count')
            ->orderBy('sort', 'desc')->orderBy('id', 'asc');

        $this->addFilter('id', 'id');

        return $queryBuilder;
    }

    /**
     * Add columns.
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => trans('admin::app.catalog.scenes.index.datagrid.id'),
            'type'       => 'integer',
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'name',
            'label'      => trans('admin::app.catalog.scenes.index.datagrid.name'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'category',
            'label'      => trans('admin::app.catalog.scenes.index.datagrid.category'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'sort',
            'label'      => trans('admin::app.catalog.scenes.index.datagrid.sort'),
            'type'       => 'integer',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'consult_count',
            'label'      => trans('admin::app.catalog.scenes.index.datagrid.consult-count'),
            'type'       => 'integer',
            'sortable'   => true,
            'closure'    => function ($value) {
                $val = $value->consult_count ?? 0;

                if (! bouncer()->hasPermission('catalog.scenes.edit')) {
                    return (string) $val;
                }

                return '<input type="number" min="0" value="'.$val.'" '
                    .'data-route="'.route('admin.catalog.scenes.consult-count', $value->id).'" '
                    .'onchange="window.__sceneConsult && window.__sceneConsult(this)" '
                    .'style="width:90px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;" />';
            },
        ]);

        $this->addColumn([
            'index'      => 'is_hot',
            'label'      => trans('admin::app.catalog.scenes.index.datagrid.is_hot'),
            'type'       => 'boolean',
            'sortable'   => true,
            'closure'    => function ($value) {
                $hot        = (bool) $value->is_hot;
                $hotTxt     = trans('admin::app.catalog.scenes.index.datagrid.hot');
                $coldTxt    = trans('admin::app.catalog.scenes.index.datagrid.not-hot');
                $label      = $hot ? $hotTxt : $coldTxt;
                $badgeCls   = $hot ? 'badge-success' : 'badge-danger';

                if (! bouncer()->hasPermission('catalog.scenes.edit')) {
                    return '<span class="badge badge-md '.$badgeCls.'">'.$label.'</span>';
                }

                return '<button type="button" class="badge badge-md '.$badgeCls.' cursor-pointer transition hover:opacity-80" '
                    .'data-route="'.route('admin.catalog.scenes.hot', $value->id).'" '
                    .'data-current="'.($hot ? 1 : 0).'" '
                    .'data-on-text="'.$hotTxt.'" data-off-text="'.$coldTxt.'" '
                    .'onclick="window.__sceneHotToggle && window.__sceneHotToggle(this)">'.$label.'</button>';
            },
        ]);

        $this->addColumn([
            'index'              => 'status',
            'label'              => trans('admin::app.catalog.scenes.index.datagrid.status'),
            'type'               => 'boolean',
            'filterable'         => true,
            'filterable_options' => [
                [
                    'label' => trans('admin::app.catalog.scenes.index.datagrid.active'),
                    'value' => 1,
                ],
                [
                    'label' => trans('admin::app.catalog.scenes.index.datagrid.inactive'),
                    'value' => 0,
                ],
            ],
            'sortable' => true,
            'closure'  => function ($value) {
                $active       = (bool) $value->status;
                $activeTxt    = trans('admin::app.catalog.scenes.index.datagrid.active');
                $inactiveTxt  = trans('admin::app.catalog.scenes.index.datagrid.inactive');
                $label        = $active ? $activeTxt : $inactiveTxt;
                $badgeCls     = $active ? 'badge-success' : 'badge-danger';

                // 无编辑权限：仅展示文本徽标，不可切换
                if (! bouncer()->hasPermission('catalog.scenes.edit')) {
                    return '<span class="badge badge-md '.$badgeCls.'">'.$label.'</span>';
                }

                // 可点击开关：AJAX 切换（index.blade 的 __sceneToggle），不整页刷新
                return '<button type="button" class="badge badge-md '.$badgeCls.' cursor-pointer transition hover:opacity-80" '
                    .'data-route="'.route('admin.catalog.scenes.status', $value->id).'" '
                    .'data-current="'.($active ? 1 : 0).'" '
                    .'data-on-text="'.$activeTxt.'" data-off-text="'.$inactiveTxt.'" '
                    .'title="'.$label.' → '.($active ? $inactiveTxt : $activeTxt).'" '
                    .'onclick="window.__sceneToggle && window.__sceneToggle(this)">'.$label.'</button>';
            },
        ]);
    }

    /**
     * Prepare actions.
     */
    public function prepareActions()
    {
        if (bouncer()->hasPermission('catalog.scenes.edit')) {
            $this->addAction([
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.catalog.scenes.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => function ($row) {
                    return route('admin.catalog.scenes.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('catalog.scenes.delete')) {
            $this->addAction([
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.catalog.scenes.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => function ($row) {
                    return route('admin.catalog.scenes.delete', $row->id);
                },
            ]);
        }
    }

    /**
     * Prepare mass actions (批量操作).
     */
    public function prepareMassActions()
    {
        if (bouncer()->hasPermission('catalog.scenes.delete')) {
            $this->addMassAction([
                'title'  => trans('admin::app.catalog.scenes.index.datagrid.delete'),
                'url'    => route('admin.catalog.scenes.mass_delete'),
                'method' => 'POST',
            ]);
        }

        if (bouncer()->hasPermission('catalog.scenes.edit')) {
            $this->addMassAction([
                'title'   => trans('admin::app.catalog.scenes.index.datagrid.update-status'),
                'url'     => route('admin.catalog.scenes.mass_update'),
                'method'  => 'POST',
                'options' => [
                    [
                        'label' => trans('admin::app.catalog.scenes.index.datagrid.active'),
                        'value' => 1,
                    ],
                    [
                        'label' => trans('admin::app.catalog.scenes.index.datagrid.inactive'),
                        'value' => 0,
                    ],
                ],
            ]);
        }
    }
}
