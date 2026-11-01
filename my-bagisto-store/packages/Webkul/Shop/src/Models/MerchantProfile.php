<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\Customer;

/**
 * 商家主体档案 —— 与 DeveloperProfile 平行共存，挂载于 customers.id。
 */
class MerchantProfile extends Model
{
    public const TYPE_COMPANY = 'company';

    public const TYPE_PERSONAL = 'personal';

    public const REALNAME_PENDING = 'pending';

    public const REALNAME_APPROVED = 'approved';

    public const REALNAME_REJECTED = 'rejected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'merchant_profiles';

    protected $fillable = [
        'customer_id',
        'default_seller_id',
        'coop_application_id',
        'invite_code',
        'merchant_type',
        'company_name',
        'credit_code',
        'contact_name',
        'contact_phone',
        'realname_status',
        'status',
        'timezone',
        'agreement_signed_at',
        'cert_payload',
    ];

    protected $casts = [
        'customer_id'         => 'integer',
        'default_seller_id'   => 'integer',
        'coop_application_id' => 'integer',
        'agreement_signed_at' => 'datetime',
        'cert_payload'        => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function defaultSeller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'default_seller_id');
    }

    public function shops(): HasMany
    {
        return $this->hasMany(MerchantShop::class, 'merchant_profile_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRealnameApproved(): bool
    {
        return $this->realname_status === self::REALNAME_APPROVED;
    }
}
