<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\ProductProxy;

/**
 * 配件 × 机型 的适配关系。
 *
 * 机型真相源是 `device_model.model_code`，与技能兼容性同一口径。
 * `fit_level` 是**对外承诺等级**，不是内部备注：
 *   - direct      直装：原接口安装，无需改件
 *   - adapter     需转接：需另购转接件/支架
 *   - custom      需定制：需测绘/改件，先报价
 *   - unsupported 不适配：明确不卖，不进列表
 */
class AccessoryFitment extends Model
{
    public const FIT_DIRECT = 'direct';

    public const FIT_ADAPTER = 'adapter';

    public const FIT_CUSTOM = 'custom';

    public const FIT_UNSUPPORTED = 'unsupported';

    public const FIT_LEVELS = [
        self::FIT_DIRECT,
        self::FIT_ADAPTER,
        self::FIT_CUSTOM,
        self::FIT_UNSUPPORTED,
    ];

    /** 可对外展示/可售的等级（unsupported 只用于详情页明确提示）。 */
    public const SELLABLE_LEVELS = [
        self::FIT_DIRECT,
        self::FIT_ADAPTER,
        self::FIT_CUSTOM,
    ];

    public const FIT_LABELS = [
        self::FIT_DIRECT      => '直装',
        self::FIT_ADAPTER     => '需转接',
        self::FIT_CUSTOM      => '需定制',
        self::FIT_UNSUPPORTED => '不适配',
    ];

    /** 列表排序：越"能直接装"越靠前。 */
    public const FIT_SORT = [
        self::FIT_DIRECT      => 0,
        self::FIT_ADAPTER     => 1,
        self::FIT_CUSTOM      => 2,
        self::FIT_UNSUPPORTED => 3,
    ];

    public const SOURCE_VENDOR = 'vendor';

    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_MEASURED = 'measured';

    public const SOURCE_LABELS = [
        self::SOURCE_VENDOR   => '厂商声明',
        self::SOURCE_PLATFORM => '平台登记',
        self::SOURCE_MEASURED => '平台实测',
    ];

    protected $table = 'accessory_fitments';

    protected $fillable = [
        'product_id',
        'model_code',
        'fit_level',
        'adapter_sku',
        'note',
        'source',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function deviceModel(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class, 'model_code', 'model_code');
    }

    public function fitLabel(): string
    {
        return self::FIT_LABELS[$this->fit_level] ?? $this->fit_level;
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }
}
