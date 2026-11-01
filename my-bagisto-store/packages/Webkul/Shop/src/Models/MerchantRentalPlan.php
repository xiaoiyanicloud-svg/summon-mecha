<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantRentalPlan extends Model
{
    protected $table = 'merchant_rental_plans';

    protected $fillable = [
        'product_id',
        'seller_id',
        'shop_id',
        'plan_code',
        'name',
        'period_unit',
        'min_days',
        'max_days',
        'base_price_cents',
        'price_tiers',
        'deposit_rule',
        'contract_template_code',
        'enabled',
        'version',
    ];

    protected $casts = [
        'product_id'       => 'integer',
        'seller_id'        => 'integer',
        'shop_id'          => 'integer',
        'min_days'         => 'integer',
        'max_days'         => 'integer',
        'base_price_cents' => 'integer',
        'price_tiers'      => 'array',
        'deposit_rule'     => 'array',
        'enabled'          => 'boolean',
        'version'          => 'integer',
    ];
}
