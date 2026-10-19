<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Concerns\RejectsExportBypass;
use Webkul\DataGrid\DataGrid;

/**
 * 赛事报名(EventRegistration)后台列表 —— 闭环转化数据,供运营跟进。
 *
 * G0-04:报名数据含 PII,只有 permission_type=all 的超级管理员可看明文;
 * custom 角色(哪怕持有 catalog.events.registrations)只能看遮罩值。
 * 导出旁路由 RejectsExportBypass 在 process() 入口统一封堵。
 */
class EventRegistrationDataGrid extends DataGrid
{
    use RejectsExportBypass;

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
        $canViewRawPii = $this->canViewRawPii();

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
            // custom 角色不允许按姓名搜索,避免把列表当作 PII 查询器(枚举/确认)。
            'searchable' => $canViewRawPii,
        ]);

        $this->addColumn([
            'index'      => 'phone',
            'label'      => '手机号',
            'type'       => 'string',
            // 同上:手机号搜索对 custom 角色关闭。
            'searchable' => $canViewRawPii,
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

    /**
     * 过滤参数也必须在查询前清理:searchable=false 只影响 "all" 搜索,
     * 显式 filters[name]=... 仍会被共享 DataGrid 直接用于 where。
     */
    protected function processRequestedFilters(array $requestedFilters)
    {
        if (! $this->canViewRawPii()) {
            unset($requestedFilters['name'], $requestedFilters['phone']);
        }

        parent::processRequestedFilters($requestedFilters);
    }

    /**
     * G0-04 残余旁路:sortable=false 只影响前端展示,共享 DataGrid 仍会把
     * sort[column] 直接塞进 orderBy。custom 角色按姓名/手机号排序等于泄露
     * 两者的大小顺序,这里在进 query 前丢弃,回退默认 id desc。
     */
    protected function processRequestedSorting($requestedSort)
    {
        if (! $this->canViewRawPii()) {
            $requestedColumn = $requestedSort['column'] ?? null;

            if (in_array($requestedColumn, [
                'name',
                'phone',
                'event_registrations.name',
                'event_registrations.phone',
            ], true)) {
                $requestedSort = [];
            }
        }

        parent::processRequestedSorting($requestedSort);
    }

    /**
     * 列表 JSON 的最终输出层遮罩。
     *
     * 不用 Column closure 冒充安全(导出路径不会执行 closure);这里在
     * 共享 DataGrid 组装 records 之前替换原始值,保证 JSON 本身不含明文。
     */
    protected function formatRecords($records): mixed
    {
        if (! $this->canViewRawPii()) {
            foreach ($records as $record) {
                $record->name = $this->maskName($record->name ?? '');
                $record->phone = $this->maskPhone($record->phone ?? '');
            }
        }

        return parent::formatRecords($records);
    }

    /**
     * G0 只允许 permission_type=all 的超级管理员查看明文。
     */
    protected function canViewRawPii(): bool
    {
        $admin = auth()->guard('admin')->user();

        return $admin !== null
            && $admin->role !== null
            && $admin->role->permission_type === 'all';
    }

    /**
     * 张三 -> 张*;空值保持空字符串。
     */
    protected function maskName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        $length = mb_strlen($name);

        if ($length <= 1) {
            return '*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', $length - 1);
    }

    /**
     * 13900000001 -> 139****0001;空值保持空字符串。
     */
    protected function maskPhone(?string $phone): string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return '';
        }

        $length = mb_strlen($phone);

        if ($length <= 7) {
            return str_repeat('*', $length);
        }

        return mb_substr($phone, 0, 3)
            .str_repeat('*', $length - 7)
            .mb_substr($phone, -4);
    }
}
