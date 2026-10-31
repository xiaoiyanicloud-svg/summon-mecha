<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\ProductProxy;

/**
 * 配件档案 —— 配件域独有的交易/服务信息。
 *
 * 只对「配件」分类下的商品有意义；其它商品没有档案也完全正常。
 * 不放进 Bagisto 属性族的原因见迁移文件头注释。
 */
class AccessoryProfile extends Model
{
    public const INSTALL_SELF = 'self';

    public const INSTALL_ENGINEER = 'engineer';

    public const INSTALL_STORE = 'store';

    public const INSTALL_METHODS = [
        self::INSTALL_SELF,
        self::INSTALL_ENGINEER,
        self::INSTALL_STORE,
    ];

    public const INSTALL_LABELS = [
        self::INSTALL_SELF     => '自助安装',
        self::INSTALL_ENGINEER => '需工程师',
        self::INSTALL_STORE    => '到店安装',
    ];

    protected $table = 'accessory_profiles';

    protected $fillable = [
        'product_id',
        'summary',
        'install_method',
        'package_contents',
        'warranty_months',
        'lead_time_days',
        'material',
        'official',
        'custom_service',
        'custom_price_from',
        'compatibility_note',
        'power_requirement',
    ];

    protected $casts = [
        'warranty_months'   => 'integer',
        'lead_time_days'    => 'integer',
        'official'          => 'boolean',
        'custom_service'    => 'boolean',
        'custom_price_from' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function installLabel(): string
    {
        return self::INSTALL_LABELS[$this->install_method] ?? $this->install_method;
    }
}
