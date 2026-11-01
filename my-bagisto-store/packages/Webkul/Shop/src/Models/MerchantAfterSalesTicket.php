<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantAfterSalesTicket extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'merchant_after_sales_tickets';

    protected $fillable = [
        'ticket_no',
        'seller_id',
        'shop_id',
        'merchant_order_id',
        'product_id',
        'ticket_type',
        'device_sn',
        'requester_name',
        'reason',
        'status',
        'resolution_notes',
        'refund_cents',
        'compensation_cents',
        'locks_maintenance_stock',
        'maintenance_released',
        'handled_by',
        'resolved_at',
        'version',
    ];

    protected $casts = [
        'seller_id'               => 'integer',
        'shop_id'                 => 'integer',
        'merchant_order_id'       => 'integer',
        'product_id'              => 'integer',
        'refund_cents'            => 'integer',
        'compensation_cents'      => 'integer',
        'locks_maintenance_stock' => 'boolean',
        'maintenance_released'    => 'boolean',
        'handled_by'              => 'integer',
        'resolved_at'             => 'datetime',
        'version'                 => 'integer',
    ];
}
