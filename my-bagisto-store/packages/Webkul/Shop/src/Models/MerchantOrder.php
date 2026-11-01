<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantOrder extends Model
{
    public const STATUS_PENDING_SHIPMENT = 'pending_shipment';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_IN_USE = 'in_use';
    public const STATUS_RETURNED_INSPECTING = 'returned_inspecting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'merchant_orders';

    protected $fillable = [
        'order_no',
        'bagisto_order_id',
        'seller_id',
        'shop_id',
        'product_id',
        'sku_id',
        'rental_plan_id',
        'fulfillment_type',
        'product_name',
        'sku_code',
        'buyer_name',
        'buyer_phone',
        'buyer_id',
        'shipping_address',
        'qty',
        'rental_days',
        'rental_start_at',
        'rental_end_at',
        'unit_price_cents',
        'subtotal_cents',
        'deposit_cents',
        'damage_deduct_cents',
        'overdue_fee_cents',
        'refund_deposit_cents',
        'status',
        'cancel_reason',
        'cancel_party',
        'cancelled_at',
        'contract_no',
        'contract_status',
        'contract_signed_at',
        'device_sn',
        'carrier',
        'tracking_no',
        'shipped_at',
        'return_carrier',
        'return_tracking_no',
        'returned_at',
        'inspection_result',
        'inspection_notes',
        'inspected_at',
        'completed_at',
        'settled',
        'version',
    ];

    protected $casts = [
        'bagisto_order_id'     => 'integer',
        'seller_id'            => 'integer',
        'shop_id'              => 'integer',
        'product_id'           => 'integer',
        'sku_id'               => 'integer',
        'rental_plan_id'       => 'integer',
        'qty'                  => 'integer',
        'rental_days'          => 'integer',
        'unit_price_cents'     => 'integer',
        'subtotal_cents'       => 'integer',
        'deposit_cents'        => 'integer',
        'damage_deduct_cents'  => 'integer',
        'overdue_fee_cents'    => 'integer',
        'refund_deposit_cents' => 'integer',
        'buyer_id'             => 'integer',
        'rental_start_at'      => 'datetime',
        'rental_end_at'        => 'datetime',
        'contract_signed_at'   => 'datetime',
        'shipped_at'           => 'datetime',
        'returned_at'          => 'datetime',
        'inspected_at'         => 'datetime',
        'completed_at'         => 'datetime',
        'cancelled_at'         => 'datetime',
        'settled'              => 'boolean',
        'version'              => 'integer',
    ];
}
