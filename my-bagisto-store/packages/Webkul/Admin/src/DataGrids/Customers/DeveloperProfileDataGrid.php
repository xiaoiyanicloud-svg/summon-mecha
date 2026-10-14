<?php

namespace Webkul\Admin\DataGrids\Customers;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\DeveloperProfile;

/**
 * 开发者主体列表（v3.4 步骤 9 的平台端侧）。
 *
 * 开发者 = 一行 `customers` + 一行 `developer_profile`，所以这里 join 回 customers
 * 才能看到"人"。join 的是 customers 主表（姓名不在此表，姓名在 customers 上）。
 */
class DeveloperProfileDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('developer_profile')
            ->leftJoin('customers', 'customers.id', '=', 'developer_profile.customer_id')
            ->leftJoin('sellers', 'sellers.id', '=', 'developer_profile.seller_id')
            ->select(
                'developer_profile.id',
                'developer_profile.dev_type',
                'developer_profile.realname_status',
                'developer_profile.status',
                'developer_profile.created_at',
                'customers.first_name',
                'customers.last_name',
                'customers.email',
                'sellers.name as seller_name'
            )
            ->orderBy('developer_profile.id', 'desc');

        $this->addFilter('id', 'developer_profile.id');
        $this->addFilter('realname_status', 'developer_profile.realname_status');
        $this->addFilter('dev_type', 'developer_profile.dev_type');

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
            'index'      => 'email',
            'label'      => '账号',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'first_name',
            'label'      => '联系人',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => fn ($row) => e(trim(($row->last_name ?? '').($row->first_name ?? '')) ?: '—'),
        ]);

        $this->addColumn([
            'index'      => 'dev_type',
            'label'      => '主体类型',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '个人', 'value' => DeveloperProfile::DEV_TYPE_PERSONAL],
                ['label' => '企业', 'value' => DeveloperProfile::DEV_TYPE_COMPANY],
            ],
            'closure'    => fn ($row) => $row->dev_type === DeveloperProfile::DEV_TYPE_COMPANY ? '企业' : '个人',
        ]);

        $this->addColumn([
            'index'      => 'seller_name',
            'label'      => '店铺主体',
            'type'       => 'string',
            'closure'    => fn ($row) => e($row->seller_name ?? '—'),
        ]);

        $this->addColumn([
            'index'      => 'realname_status',
            'label'      => '实名审核',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '待审', 'value' => DeveloperProfile::REALNAME_PENDING],
                ['label' => '已通过', 'value' => DeveloperProfile::REALNAME_APPROVED],
                ['label' => '已驳回', 'value' => DeveloperProfile::REALNAME_REJECTED],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                $map = [
                    DeveloperProfile::REALNAME_PENDING  => ['待审', 'badge-pending'],
                    DeveloperProfile::REALNAME_APPROVED => ['已通过', 'badge-success'],
                    DeveloperProfile::REALNAME_REJECTED => ['已驳回', 'badge-danger'],
                ];

                [$label, $tone] = $map[$row->realname_status] ?? [$row->realname_status, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '账号状态',
            'type'       => 'string',
            'closure'    => fn ($row) => $row->status === DeveloperProfile::STATUS_ACTIVE
                ? '<span class="badge badge-md badge-success">正常</span>'
                : '<span class="badge badge-md badge-danger">已停用</span>',
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-view',
            'title'  => '查看 / 审核',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.customers.developers.show', $row->id),
        ]);
    }
}
