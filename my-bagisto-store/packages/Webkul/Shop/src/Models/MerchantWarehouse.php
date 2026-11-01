<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantWarehouse extends Model
{
    protected $table = 'merchant_warehouses';

    protected $fillable = [
        'seller_id',
        'shop_id',
        'code',
        'name',
        'region',
        'address',
        'contact_name',
        'contact_phone',
        'is_default',
        'status',
    ];

    protected $casts = [
        'seller_id'  => 'integer',
        'shop_id'    => 'integer',
        'is_default' => 'boolean',
    ];
}
