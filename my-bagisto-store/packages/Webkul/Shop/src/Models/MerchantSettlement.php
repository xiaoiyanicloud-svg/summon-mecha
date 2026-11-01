<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantSettlement extends Model
{
    protected $table = 'merchant_settlements';

    protected $fillable = [
        'settlement_no',
        'seller_id',
        'shop_id',
        'period_label',
        'orders_count',
        'gross_amount_cents',
        'damage_income_cents',
        'platform_fee_cents',
        'refund_deduct_cents',
        'net_amount_cents',
        'status',
        'confirmed_at',
        'paid_at',
    ];

    protected $casts = [
        'seller_id'           => 'integer',
        'shop_id'             => 'integer',
        'orders_count'        => 'integer',
        'gross_amount_cents'  => 'integer',
        'damage_income_cents' => 'integer',
        'platform_fee_cents'  => 'integer',
        'refund_deduct_cents' => 'integer',
        'net_amount_cents'    => 'integer',
        'confirmed_at'        => 'datetime',
        'paid_at'             => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MerchantSettlementItem::class, 'settlement_id');
    }
}
