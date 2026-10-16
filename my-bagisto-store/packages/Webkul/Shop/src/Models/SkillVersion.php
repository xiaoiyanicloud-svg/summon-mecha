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
     * 版本状态 —— **版本生命周期**，与「审核到哪一步了」正交。
     *
     * 2026-10-16 收敛（裁决 Q2）：
     *   - 「审核判决」的唯一事实是 `audit_record`（分层、含驳回理由）；
     *   - 本字段只表达版本走到生命周期的哪一段，且**只允许由 `SkillVersionState` 这个统一入口写**。
     *
     * 为什么新增 in_review / rejected 两个状态：此前只有 draft/published/archived 三个常量，
     * 且**提审时不写 status** —— 于是"提审后"在库里看起来还是 draft，L2 驳回与"从没提审过"
     * 在 status 上根本分不出，还导致"已发布版本可重复提审却不进审核队列"的黑洞。
     */
    public const STATUS_DRAFT = 'draft';

    /** 已提审、正在审核中（未出终局结论，或 L2 已通过但尚未发布）。 */
    public const STATUS_IN_REVIEW = 'in_review';

    /** 被驳回或待补充材料（L0 机审不过 / L2 驳回 / L2 待补充）。 */
    public const STATUS_REJECTED = 'rejected';

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
        // 开发者声明的权限（D-8 改判版）—— **只做可见化**，不做任何强制/比对
        'permissions',
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
        'permissions'       => 'array',
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
