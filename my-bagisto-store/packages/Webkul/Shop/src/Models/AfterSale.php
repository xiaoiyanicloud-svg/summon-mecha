<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 售后/退款单(v3.4 步骤 4,虚拟商品政策)。
 *
 * 虚拟商品售后规则(计划 D10):
 *   试用     —— 不支持退款
 *   买断·未下发 —— 可退
 *   买断·已激活 —— 原则上不退,可申诉
 */
class AfterSale extends Model
{
    protected $table = 'after_sale';

    public const TYPE_REFUND = 'refund';

    public const TYPE_COMPLAINT = 'complaint';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'entitlement_id',
        'customer_id',
        'type',
        'reason_category',
        'detail',
        'policy_basis',
        'status',
        'reviewer_id',
        'reviewed_at',
    ];

    protected $casts = [
        'order_id'       => 'integer',
        'order_item_id'  => 'integer',
        'entitlement_id' => 'integer',
        'customer_id'    => 'integer',
        'reviewer_id'    => 'integer',
        'reviewed_at'    => 'datetime',
    ];
}
