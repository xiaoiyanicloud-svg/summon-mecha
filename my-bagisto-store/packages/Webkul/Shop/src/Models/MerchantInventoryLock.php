<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantInventoryLock extends Model
{
    protected $table = 'merchant_inventory_locks';

    protected $fillable = [
        'seller_id',
        'shop_id',
        'product_id',
        'sku_id',
        'warehouse_id',
        'action_type',
        'qty_change',
        'sellable_after',
        'rentable_after',
        'locked_after',
        'maintenance_after',
        'reference_no',
        'reason',
        'actor_id',
    ];

    protected $casts = [
        'seller_id'         => 'integer',
        'shop_id'           => 'integer',
        'product_id'        => 'integer',
        'sku_id'            => 'integer',
        'warehouse_id'      => 'integer',
        'qty_change'        => 'integer',
        'sellable_after'    => 'integer',
        'rentable_after'    => 'integer',
        'locked_after'      => 'integer',
        'maintenance_after' => 'integer',
        'actor_id'          => 'integer',
    ];
}
