<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Product\Models\ProductProxy;

/**
 * 设备 —— 用户实际拥有的那台机器。
 *
 * 在此之前项目里没有「设备」这个实体:只有"机型"(rent/buy 分类下的商品),没有"实例"。
 * 技能要"用到机器上",缺的就是这一层。
 *
 * 几个刻意的设计:
 *  - customer_id 非空:技能交易强制登录(已与业务确认),所以设备也一律挂账号,
 *    不做"本机标识"那套。这也顺带避开了 OrderController::guestIndex() 那个
 *    whereNull('customer_id') 会返回所有游客订单的坑。
 *  - 唯一键是 (customer_id, sn) 而不是 sn:SN 由用户自填,平台无法验证真伪,
 *    全局唯一会让人抢注别人的 SN。
 *  - last_seen_at / firmware_version / model_code 现在是空的,是给 Phase 1
 *    设备拉取通道预留的 —— 届时设备会带 license_key 来轮询并上报这些字段。
 */
class Device extends Model
{
    /**
     * 绑定状态(生命周期)。
     */
    public const STATUS_BOUND = 'bound';

    public const STATUS_REVOKED = 'revoked';

    /**
     * 核验状态(平台对"这台机器真实存在"的把握程度)。
     *
     * ⚠ 与绑定状态是两根正交的轴:设备可以在册(bound)但未核验(registered)。
     *   现在平台没有任何渠道验证机器真实存在,用户填什么 SN 都算数 ——
     *   所以默认一律是 registered,界面上也必须如实这么说,不能叫"已绑定"。
     */
    public const VERIFY_REGISTERED = 'registered';

    public const VERIFY_VERIFIED = 'verified';

    /**
     * The table associated with the model.
     */
    protected $table = 'devices';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'customer_id',
        'sn',
        'product_id',
        'model_code',
        'display_name',
        'seller_id',
        'bind_status',
        'verify_status',
        'verified_at',
        'verified_via',
        'bound_at',
        'last_seen_at',
        'firmware_version',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'bound_at'     => 'datetime',
        'verified_at'  => 'datetime',
        'last_seen_at' => 'datetime',
        'meta'         => 'array',
    ];

    /**
     * 设备归属用户。
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'customer_id');
    }

    /**
     * 设备型号(机型商品);用户未选机型时为 NULL。
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    /**
     * 这台设备已获得的技能授权。
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(SkillEntitlement::class, 'device_id');
    }

    /**
     * 是否处于正常绑定状态。
     */
    public function isBound(): bool
    {
        return $this->bind_status === self::STATUS_BOUND;
    }

    /**
     * 平台是否已核验这台机器真实存在。
     *
     * 现在恒为 false —— 没有任何厂商通道能验证。等接口开通后由通道写入。
     */
    public function isVerified(): bool
    {
        return $this->verify_status === self::VERIFY_VERIFIED;
    }

    /**
     * 核验状态的中文说明 —— 界面统一用它,避免各处写法不一致。
     */
    public function verifyLabel(): string
    {
        return $this->isVerified() ? '已核验' : '未核验';
    }
}
