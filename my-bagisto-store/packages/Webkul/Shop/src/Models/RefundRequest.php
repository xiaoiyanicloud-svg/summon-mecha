<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 退款请求(v3.4 步骤 4)。
 *
 * ⚠ 微信退款调用待支付资质就绪后接入(PaymentGateway::refund)。
 *   本表先承载退款单的状态流转。
 */
class RefundRequest extends Model
{
    protected $table = 'refund_request';

    protected $fillable = [
        'after_sale_id',
        'wechat_refund_no',
        'amount',
        'status',
        'callback_at',
    ];

    protected $casts = [
        'after_sale_id' => 'integer',
        'amount'        => 'float',
        'callback_at'   => 'datetime',
    ];
}
