<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 客服在线状态。
 *
 * 在线不能靠“登录过”推断：后台页面关闭、网络中断、客服离开接待台都会让状态过期。
 * 因此所有在线判断都必须同时满足 is_online=true 且 last_seen_at 在 TTL 内。
 */
class SupportAgentState extends Model
{
    public const ONLINE_TTL_SECONDS = 60;

    protected $table = 'support_agent_state';

    protected $fillable = [
        'admin_id',
        'is_online',
        'max_concurrent',
        'last_seen_at',
    ];

    protected $casts = [
        'admin_id'       => 'integer',
        'is_online'      => 'boolean',
        'max_concurrent' => 'integer',
        'last_seen_at'   => 'datetime',
    ];

    public function scopeFresh(Builder $query): Builder
    {
        return $query
            ->where('is_online', true)
            ->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_TTL_SECONDS));
    }

    public function isFresh(): bool
    {
        return $this->is_online
            && $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds(self::ONLINE_TTL_SECONDS));
    }
}