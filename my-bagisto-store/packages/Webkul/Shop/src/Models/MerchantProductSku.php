<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantProductSku extends Model
{
    protected $table = 'merchant_product_skus';

    protected $fillable = [
        'product_id',
        'seller_id',
        'shop_id',
        'sku_code',
        'name',
        'spec_json',
        'price_cents',
        'daily_rent_cents',
        'deposit_cents',
        'sellable_stock',
        'rentable_stock',
        'locked_stock',
        'maintenance_stock',
        'safety_stock',
        'status',
        'version',
    ];

    protected $casts = [
        'product_id'        => 'integer',
        'seller_id'         => 'integer',
        'shop_id'           => 'integer',
        'spec_json'         => 'array',
        'price_cents'       => 'integer',
        'daily_rent_cents'  => 'integer',
        'deposit_cents'     => 'integer',
        'sellable_stock'    => 'integer',
        'rentable_stock'    => 'integer',
        'locked_stock'      => 'integer',
        'maintenance_stock' => 'integer',
        'safety_stock'      => 'integer',
        'version'           => 'integer',
    ];
}
