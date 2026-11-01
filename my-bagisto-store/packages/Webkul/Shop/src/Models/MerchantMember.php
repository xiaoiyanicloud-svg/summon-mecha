<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\Customer;

/**
 * 商家店铺成员与 RBAC 角色绑定。
 */
class MerchantMember extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_CATALOG_OPS = 'catalog_ops';

    public const ROLE_WAREHOUSE = 'warehouse';

    public const ROLE_FINANCE = 'finance';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $table = 'merchant_members';

    protected $fillable = [
        'shop_id',
        'seller_id',
        'customer_id',
        'role',
        'permissions',
        'status',
    ];

    protected $casts = [
        'shop_id'     => 'integer',
        'seller_id'   => 'integer',
        'customer_id' => 'integer',
        'permissions' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MerchantShop::class, 'shop_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 解析当前成员合并后的权限码集合。
     *
     * @return array<int, string>
     */
    public function resolvedPermissions(): array
    {
        $rolePerms = (array) config('portal_backends.roles.'.$this->role.'.permissions', []);
        $customPerms = is_array($this->permissions) ? $this->permissions : [];

        return array_values(array_unique(array_merge($rolePerms, $customPerms)));
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $perms = $this->resolvedPermissions();

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public function getRoleCodeAttribute(): ?string
    {
        return $this->role;
    }

    public function setRoleCodeAttribute(?string $value): void
    {
        $this->attributes['role'] = $value ?: self::ROLE_OWNER;
    }
}
