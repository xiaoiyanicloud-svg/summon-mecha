<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 商家店铺模型 —— 每个店铺 1:1 绑定一个 sellers.id（多租户强隔离锚点）。
 */
class MerchantShop extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const SCOPE_SALE_ROBOT = 'sale_robot';

    public const SCOPE_RENTAL_ROBOT = 'rental_robot';

    public const SCOPE_ACCESSORY = 'accessory';

    protected $table = 'merchant_shops';

    protected $fillable = [
        'seller_id',
        'merchant_profile_id',
        'code',
        'name',
        'description',
        'business_scopes',
        'timezone',
        'status',
    ];

    protected $casts = [
        'seller_id'           => 'integer',
        'merchant_profile_id' => 'integer',
        'business_scopes'     => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MerchantProfile::class, 'merchant_profile_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(MerchantMember::class, 'shop_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getShopCodeAttribute(): ?string
    {
        return $this->code;
    }

    public function getShopNameAttribute(): ?string
    {
        return $this->name;
    }

    public function getBusinessTypesAttribute(): array
    {
        return is_array($this->business_scopes) ? $this->business_scopes : [];
    }
}
