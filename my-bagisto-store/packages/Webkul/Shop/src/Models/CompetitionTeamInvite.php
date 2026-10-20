<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 队伍邀请码（G3-03）。
 *
 * 原始令牌只在创建时返回一次；库内只存 sha256。邀请一次性、有过期时间。
 * 不按手机号/客户 ID 搜索，避免客户枚举与 PII 侧信道。
 */
class CompetitionTeamInvite extends Model
{
    protected $table = 'competition_team_invites';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'team_id',
        'token_hash',
        'status',
        'expires_at',
        'created_by_customer_id',
        'accepted_by_customer_id',
        'accepted_at',
    ];

    protected $casts = [
        'team_id'                => 'integer',
        'expires_at'             => UtcDateTime::class,
        'created_by_customer_id' => 'integer',
        'accepted_by_customer_id'=> 'integer',
        'accepted_at'            => UtcDateTime::class,
    ];

    public function team()
    {
        return $this->belongsTo(CompetitionTeam::class, 'team_id');
    }
}
