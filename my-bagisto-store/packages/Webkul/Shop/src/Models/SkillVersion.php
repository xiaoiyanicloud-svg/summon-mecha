<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Product\Models\ProductProxy;

/**
 * 技能版本 —— 技能的一次发布。
 *
 * 为什么单独建表而不是塞进 products:一个技能商品可以有多个版本(1.0.0 / 1.2.0 / beta),
 * 而"版本"这根轴在 Bagisto 里无处安放。同时它承载授权规则(license_mode / max_activations
 * / valid_days),这些都不属于商品本身。
 *
 * seller_id 为 NULL 表示平台自营 —— 多商户预留位,现在恒为 NULL。
 */
class SkillVersion extends Model
{
    /**
     * 版本状态。
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * The table associated with the model.
     */
    protected $table = 'skill_versions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'product_id',
        'seller_id',
        'version',
        'channel',
        'delivery_channels',
        'risk_level',
        'status',
        'license_mode',
        'pricing_mode',
        'manifest_schema',
        'source_format',
        'imported_meta',
        'max_activations',
        'valid_days',
        'min_robot_version',
        'changelog',
        'install_guide',
        'released_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'delivery_channels' => 'array',
        'imported_meta'     => 'array',
        'max_activations'   => 'integer',
        'valid_days'        => 'integer',
        'released_at'       => 'datetime',
    ];

    /**
     * 所属技能商品。
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    /**
     * 该版本的交付物(0..N 个,这是「多载体」的落点)。
     */
    public function assets(): HasMany
    {
        return $this->hasMany(SkillAsset::class, 'skill_version_id')->orderBy('sort_order');
    }

    /**
     * 是否已发布 —— 只有 published 的版本才允许被下单锁定。
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
