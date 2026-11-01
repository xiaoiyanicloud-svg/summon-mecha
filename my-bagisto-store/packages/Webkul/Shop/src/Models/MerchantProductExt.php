<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Product\Models\ProductProxy;

/**
 * 商家商品扩展模型（1:1 关联 products.id，承接租售类型、整数分价格/押金、双轨库存与审核上下架状态机）。
 */
class MerchantProductExt extends Model
{
    public const TYPE_SALE_ROBOT = 'sale_robot';

    public const TYPE_RENTAL_ROBOT = 'rental_robot';

    public const TYPE_ACCESSORY = 'accessory';

    public const PRODUCT_TYPES = [
        self::TYPE_SALE_ROBOT,
        self::TYPE_RENTAL_ROBOT,
        self::TYPE_ACCESSORY,
    ];

    public const TYPE_LABELS = [
        self::TYPE_SALE_ROBOT   => '机器人整机售卖',
        self::TYPE_RENTAL_ROBOT => '机器人整机租赁',
        self::TYPE_ACCESSORY    => '机器人配件',
    ];

    public const FULFILLMENT_SALE = 'sale';

    public const FULFILLMENT_RENTAL = 'rental';

    public const FULFILLMENT_BOTH = 'both';

    public const REVIEW_DRAFT = 'draft';

    public const REVIEW_IN_REVIEW = 'in_review';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_LABELS = [
        self::REVIEW_DRAFT     => '草稿',
        self::REVIEW_IN_REVIEW => '待审核',
        self::REVIEW_APPROVED  => '审核通过',
        self::REVIEW_REJECTED  => '已驳回',
    ];

    public const SHELF_OFF = 'off_shelf';

    public const SHELF_ON = 'on_shelf';

    public const SHELF_LABELS = [
        self::SHELF_OFF => '已下架',
        self::SHELF_ON  => '在售上架',
    ];

    protected $table = 'merchant_product_exts';

    protected $fillable = [
        'product_id',
        'seller_id',
        'shop_id',
        'product_type',
        'fulfillment_type',
        'compatible_models',
        'param_specs',
        'media_items',
        'rental_plans_snapshot',
        'deposit_rule_snapshot',
        'price_cents',
        'daily_rent_cents',
        'deposit_cents',
        'min_rent_days',
        'sellable_stock',
        'rentable_stock',
        'locked_stock',
        'maintenance_stock',
        'safety_stock',
        'review_status',
        'shelf_status',
        'reject_reason',
        'submitted_at',
        'reviewed_at',
        'on_shelf_at',
        'version',
    ];

    protected $casts = [
        'product_id'            => 'integer',
        'seller_id'             => 'integer',
        'shop_id'               => 'integer',
        'compatible_models'     => 'array',
        'param_specs'           => 'array',
        'media_items'           => 'array',
        'rental_plans_snapshot' => 'array',
        'deposit_rule_snapshot' => 'array',
        'price_cents'           => 'integer',
        'daily_rent_cents'      => 'integer',
        'deposit_cents'         => 'integer',
        'min_rent_days'         => 'integer',
        'sellable_stock'        => 'integer',
        'rentable_stock'        => 'integer',
        'locked_stock'          => 'integer',
        'maintenance_stock'     => 'integer',
        'safety_stock'          => 'integer',
        'submitted_at'          => 'datetime',
        'reviewed_at'           => 'datetime',
        'on_shelf_at'           => 'datetime',
        'version'               => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MerchantShop::class, 'shop_id');
    }

    public function skus(): HasMany
    {
        return $this->hasMany(MerchantProductSku::class, 'product_id', 'product_id');
    }

    public function rentalPlans(): HasMany
    {
        return $this->hasMany(MerchantRentalPlan::class, 'product_id', 'product_id');
    }
}
