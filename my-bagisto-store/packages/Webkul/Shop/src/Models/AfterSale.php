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

    public const TYPE_REPAIR = 'repair';

    public const TYPE_EXCHANGE = 'exchange';

    public const TYPE_RETURN_REFUND = 'return_refund';

    public const DOMAIN_SKILL = 'skill';

    public const DOMAIN_ORDER = 'order';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'target_domain',
        'order_id',
        'order_item_id',
        'entitlement_id',
        'customer_id',
        'type',
        'reason_category',
        'detail',
        'policy_basis',
        'status',
        'review_note',
        'appeal_detail',
        'appealed_at',
        'cancelled_at',
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
        'appealed_at'    => 'datetime',
        'cancelled_at'   => 'datetime',
    ];
}
