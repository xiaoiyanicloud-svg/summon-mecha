<?php

namespace Webkul\Admin\DataGrids\Customers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\DeveloperInvite;

/**
 * 开发者邀请码列表（S-11b）。
 *
 * 口径（见 `DeveloperInviteController` 类注释）：
 *   - 码**明文展示**：运营要复制后发给对方；码不是身份凭据；
 *   - 「已用 / 上限」是**领取情况**，不做成"已领取"却看不到是谁领的假象
 *     —— 明细页不在本卡范围，但至少要一眼看出"用没用过"。
 */
class DeveloperInviteDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('developer_invite')
            ->leftJoin('admins', 'admins.id', '=', 'developer_invite.created_by')
            ->select(
                'developer_invite.id',
                'developer_invite.code',
                'developer_invite.max_uses',
                'developer_invite.times_used',
                'developer_invite.expires_at',
                'developer_invite.status',
                'developer_invite.note',
                'developer_invite.created_at',
                'admins.name as creator_name'
            )
            ->orderBy('developer_invite.id', 'desc');

        $this->addFilter('id', 'developer_invite.id');
        $this->addFilter('code', 'developer_invite.code');
        $this->addFilter('status', 'developer_invite.status');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'code',
            'label'      => '邀请码',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
            // 明文 + select-all：点一下即可整段选中复制（不做"遮起来"）
            'closure'    => fn ($row) => '<code class="select-all font-mono">'.e($row->code).'</code>',
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => '状态',
            'type'       => 'string',
            'filterable' => true,
            'filterable_options' => [
                ['label' => '有效', 'value' => DeveloperInvite::STATUS_ACTIVE],
                ['label' => '已作废', 'value' => DeveloperInvite::STATUS_REVOKED],
            ],
            'sortable'   => true,
            'closure'    => function ($row) {
                [$label, $tone] = $this->displayStatus($row);

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'times_used',
            'label'      => '已用 / 上限',
            'type'       => 'string',
            'closure'    => function ($row) {
                $used = (int) $row->times_used;
                $max = (int) $row->max_uses;

                if ($used <= 0) {
                    $hint = '未使用';
                } elseif ($used >= $max) {
                    $hint = '已用完';
                } else {
                    $hint = '部分使用';
                }

                return e($used.' / '.$max.' · '.$hint);
            },
        ]);

        $this->addColumn([
            'index'      => 'expires_at',
            'label'      => '有效期',
            'type'       => 'string',
            'sortable'   => true,
            'closure'    => function ($row) {
                if ($row->expires_at === null) {
                    return '不过期';
                }

                $expiresAt = Carbon::parse($row->expires_at);

                return e($expiresAt->format('Y-m-d H:i'))
                    .($expiresAt->lessThanOrEqualTo(Carbon::now())
                        ? ' <span class="text-red-600">（已过期）</span>'
                        : '');
            },
        ]);

        $this->addColumn([
            'index'      => 'note',
            'label'      => '备注',
            'type'       => 'string',
            'searchable' => true,
            'closure'    => fn ($row) => e($row->note !== null && $row->note !== '' ? $row->note : '—'),
        ]);

        $this->addColumn([
            'index'      => 'creator_name',
            'label'      => '创建人',
            'type'       => 'string',
            'closure'    => fn ($row) => e($row->creator_name ?? '—'),
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => '创建时间',
            'type'       => 'datetime',
            'sortable'   => true,
            'closure'    => fn ($row) => e(Carbon::parse($row->created_at)->format('Y-m-d H:i')),
        ]);
    }

    public function prepareActions()
    {
        if (! bouncer()->hasPermission('customers.developer-invites.manage')) {
            return;
        }

        $this->addAction([
            'index'  => 'revoke',
            'icon'   => 'icon-delete',
            'title'  => '作废',
            'method' => 'POST',
            'url'    => fn ($row) => route('admin.customers.developer-invites.revoke', $row->id),
        ]);
    }

    /**
     * 展示状态：作废 > 过期 > 用完 > 有效。
     *
     * 与 `DeveloperInvite::isRedeemable()` 的判定顺序一致 —— 但这里只是**展示**，
     * 真正能不能领仍以 `DeveloperInviteService::claim()` 的原子 UPDATE 为准。
     *
     * @return array{0: string, 1: string} [文案, badge 类]
     */
    protected function displayStatus(object $row): array
    {
        if ($row->status !== DeveloperInvite::STATUS_ACTIVE) {
            return ['已作废', 'badge-danger'];
        }

        if ($row->expires_at !== null && Carbon::parse($row->expires_at)->lessThanOrEqualTo(Carbon::now())) {
            return ['已过期', 'badge-pending'];
        }

        if ((int) $row->times_used >= (int) $row->max_uses) {
            return ['已用完', 'badge-pending'];
        }

        return ['有效', 'badge-success'];
    }
}
