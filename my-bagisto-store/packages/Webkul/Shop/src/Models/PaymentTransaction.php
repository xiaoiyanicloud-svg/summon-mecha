<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 支付流水(M6) —— 一笔支付一条记录,`out_trade_no` 唯一。
 *
 * 它是回调幂等的锚点,也是将来与网关对账的源数据。
 * 此前订单被翻成 processing 后,商户订单号/交易号/金额全部丢弃,留不下任何凭据。
 */
class PaymentTransaction extends Model
{
    protected $table = 'payment_transaction';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'order_id',
        'out_trade_no',
        'transaction_id',
        'gateway',
        'amount',
        'status',
        'paid_at',
        'raw',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'amount'   => 'float',
        'paid_at'  => 'datetime',
        'raw'      => 'array',
    ];
}
