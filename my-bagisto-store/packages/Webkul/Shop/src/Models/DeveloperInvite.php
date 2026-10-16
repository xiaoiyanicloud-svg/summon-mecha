<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 开发者邀请码 —— S-11a（数据层）。
 *
 * 语义：邀请码是**落地路由 + 归属 + 来源留痕**，**不是准入闸门** ——
 * 真正的准入闸门是「实名 + 协议」。所以这里**不判断**邀请码与"能不能入驻"的关系，
 * 只负责"这个码还能不能被领"。
 *
 * 可领的三条件（与 `DeveloperInviteService::claim()` 的原子 UPDATE 完全一致）：
 *   1. `status = active`
 *   2. `expires_at` 为空或**仍在将来**
 *   3. `times_used < max_uses`
 */
class DeveloperInvite extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'developer_invite';

    protected $fillable = [
        'code',
        'max_uses',
        'times_used',
        'expires_at',
        'status',
        'created_by',
        'note',
    ];

    protected $casts = [
        'max_uses'   => 'integer',
        'times_used' => 'integer',
        'created_by' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(DeveloperInviteUsage::class, 'invite_id');
    }

    /**
     * 码是否已过有效期（expires_at 为 null 视为永不过期）。
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(Carbon::now());
    }

    /**
     * 码是否已被领完（剩余次数 = 0）。
     */
    public function isExhausted(): bool
    {
        return $this->times_used >= $this->max_uses;
    }

    /**
     * 码当前是否可领（只读判断，供展示用）。
     *
     * ⚠ 真正领取走 `DeveloperInviteService::claim()` 的**原子 UPDATE**；
     *   这里的判断存在 TOCTOU 窗口，**不可**用来代替 claim 的成败判定。
     */
    public function isRedeemable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ! $this->isExpired()
            && ! $this->isExhausted();
    }
}
