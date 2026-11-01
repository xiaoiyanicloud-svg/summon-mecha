<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantSettlementItem extends Model
{
    protected $table = 'merchant_settlement_items';

    protected $fillable = [
        'settlement_id',
        'seller_id',
        'merchant_order_id',
        'order_no',
        'fulfillment_type',
        'gross_cents',
        'damage_income_cents',
        'platform_fee_cents',
        'net_cents',
    ];

    protected $casts = [
        'settlement_id'       => 'integer',
        'seller_id'           => 'integer',
        'merchant_order_id'   => 'integer',
        'gross_cents'         => 'integer',
        'damage_income_cents' => 'integer',
        'platform_fee_cents'  => 'integer',
        'net_cents'           => 'integer',
    ];
}
