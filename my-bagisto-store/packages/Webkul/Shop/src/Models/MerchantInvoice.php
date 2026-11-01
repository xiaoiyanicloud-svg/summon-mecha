<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantInvoice extends Model
{
    protected $table = 'merchant_invoices';

    protected $fillable = [
        'invoice_no',
        'seller_id',
        'shop_id',
        'settlement_id',
        'merchant_order_id',
        'direction',
        'invoice_type',
        'buyer_title',
        'buyer_tax_no',
        'amount_cents',
        'tax_rate',
        'invoice_code_number',
        'status',
        'issued_at',
    ];

    protected $casts = [
        'seller_id'         => 'integer',
        'shop_id'           => 'integer',
        'settlement_id'     => 'integer',
        'merchant_order_id' => 'integer',
        'amount_cents'      => 'integer',
        'issued_at'         => 'datetime',
    ];
}
