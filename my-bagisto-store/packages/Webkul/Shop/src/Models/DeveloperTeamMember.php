<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\Customer;

/**
 * 开发者团队成员 —— v3.4 步骤 9。
 *
 * 本轮只在入驻时写一行 `role = owner`（把"谁是这个开发者的主账号"落库）；
 * 完整的 RBAC（admin/dev/finance/support + app_scope 按应用授权）留位，未实现。
 */
class DeveloperTeamMember extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_DEV = 'dev';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_SUPPORT = 'support';

    protected $table = 'developer_team_member';

    protected $fillable = [
        'developer_id',
        'customer_id',
        'role',
        'app_scope',
        'status',
    ];

    protected $casts = [
        'developer_id' => 'integer',
        'customer_id'  => 'integer',
        'app_scope'    => 'array',
    ];

    public function developer(): BelongsTo
    {
        return $this->belongsTo(DeveloperProfile::class, 'developer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
