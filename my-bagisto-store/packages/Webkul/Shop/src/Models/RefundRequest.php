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

    /**
     * 退款单状态(M7 起在此集中定义,不再散落字面量)。
     *
     *   pending —— 已受理、已冻结授权,**钱还没出去**(等网关回执)
     *   success —— 网关确认已退款
     *   failed  —— 网关拒绝/异常。钱没出去,不计入「已退累计」,可人工重打款
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * 计入「已退金额累计」的状态:已成功 + 在途(退款中)。
     * `failed` 刻意不计入 —— 钱没出去,不该占用实付额度。
     */
    public const COUNTED_STATUSES = [self::STATUS_SUCCESS, self::STATUS_PENDING];

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
