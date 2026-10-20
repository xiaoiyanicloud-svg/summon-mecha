<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 入场凭证（G2-09）。
 *
 * 数据库只存 token 的 SHA-256；原始 token 只在签发响应/二维码中出现。
 * 凭证绑定 attempt，取消、重报、重发都会吊销旧 token。
 */
class EventCheckinToken extends Model
{
    protected $table = 'event_checkin_tokens';

    protected $fillable = [
        'event_id',
        'registration_id',
        'attempt_id',
        'token_hash',
        'issued_at',
        'expires_at',
        'revoked_at',
        'issued_by',
        'issued_source',
    ];

    protected $casts = [
        'event_id'        => 'integer',
        'registration_id' => 'integer',
        'attempt_id'      => 'integer',
        'issued_at'       => UtcDateTime::class,
        'expires_at'      => UtcDateTime::class,
        'revoked_at'      => UtcDateTime::class,
        'issued_by'       => 'integer',
    ];

    public function attempt()
    {
        return $this->belongsTo(EventRegistrationAttempt::class, 'attempt_id');
    }

    public function isUsableAt(mixed $now = null): bool
    {
        $now = $now ? \Illuminate\Support\Carbon::parse($now, 'UTC') : now('UTC');

        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->greaterThan($now);
    }
}
