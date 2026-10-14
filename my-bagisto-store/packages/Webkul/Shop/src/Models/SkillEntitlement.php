<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Webkul\Product\Models\ProductProxy;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItemProxy;
use Webkul\Sales\Models\OrderProxy;

/**
 * 技能授权 —— 「谁拥有什么技能」的唯一事实源。
 *
 * 为什么不用 Bagisto 原生的 downloadable_link_purchased:
 *   1. 它的状态机绑死发票(只有 InvoiceRepository 会把状态置 available),而本项目的
 *      TOB 下单管道根本不建发票 → 永远停在 pending → 用户永远下不了。这是个死结。
 *   2. 它的 customer_id 是 NOT NULL + 外键,而 TOB 管道允许游客单(is_guest / customer_id=null),
 *      一旦有条目进去会直接抛错并回滚整个建单事务。
 *   3. 没有 device_id / license_key / expires_at / 版本 任何一根轴。
 * 但继承了它的两个好设计:文件落 private 盘、"购买记录即授权记录"。
 *
 * 产生时机:监听 checkout.order.save.after(见 Listeners/SkillDelivery.php),不写在
 * TobOrderController 里 —— 这样后台手工建单、将来其它下单源都能覆盖。
 *
 * 幂等:order_item_id 上有唯一键。监听器在建单事务内触发,且 OrderRepository 失败会重试
 * 最多 2 次,所以 deliver() 必须幂等,这里靠唯一键兜底。
 */
class SkillEntitlement extends Model
{
    /**
     * 授权状态。
     */
    public const STATUS_PENDING_BIND = 'pending_bind';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    /**
     * The table associated with the model.
     */
    protected $table = 'skill_entitlements';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'customer_id',
        'order_id',
        'order_item_id',
        'product_id',
        'skill_version_id',
        'device_id',
        'seller_id',
        'status',
        'license_key',
        'delivery_channel',
        'activations',
        'max_activations',
        'granted_at',
        'expires_at',
        'revoked_at',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'activations'     => 'integer',
        'max_activations' => 'integer',
        'granted_at'      => 'datetime',
        'expires_at'      => 'datetime',
        'revoked_at'      => 'datetime',
        'meta'            => 'array',
    ];

    /**
     * 技能商品。
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    /**
     * 下单时锁定的版本;NULL 表示"跟随最新"。
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'skill_version_id');
    }

    /**
     * 绑定的设备;NULL 表示尚未绑定。
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /**
     * 来源订单。
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    /**
     * 来源订单条目。
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItemProxy::modelClass(), 'order_item_id');
    }

    /**
     * 是否仍然有效(未撤销、未过期)。
     */
    public function isUsable(): bool
    {
        if (in_array($this->status, [self::STATUS_REVOKED, self::STATUS_EXPIRED], true)) {
            return false;
        }

        return ! ($this->expires_at && $this->expires_at->isPast());
    }

    /**
     * 来源订单是否已支付(processing / completed)。
     *
     * ⚠ 授权是在**建单**时就落库的(见 Listeners/SkillDelivery,挂 checkout.order.save.after),
     *   但交付物(技能包)的付费墙必须以**支付成功**为准 —— 否则"建单未付"即可下包
     *   (等价于把付费墙挂在建单而不是支付成功)。
     *
     * order_id 为空、或订单纯为 pending(未支付)/canceled/closed 的,一律按**未支付**处理:
     *   宁可拒绝,也不放行。这是下载前的硬闸,与 isUsable() 正交(后者管撤销/过期)。
     */
    public function isOrderPaid(): bool
    {
        $order = $this->order;

        if (! $order) {
            return false;
        }

        return in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true);
    }

    /**
     * 还能再激活几次;NULL 表示不限。
     */
    public function remainingActivations(): ?int
    {
        if ($this->max_activations === null) {
            return null;
        }

        return max(0, $this->max_activations - $this->activations);
    }

    /**
     * 生成一个交付凭证。P0 只是落库给用户看,P1 设备拉取会用它做身份。
     */
    public static function newLicenseKey(): string
    {
        return Str::random(32);
    }
}
