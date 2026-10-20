<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 队伍成员关系（G3-03）。
 *
 * 只有 `status=active` 的成员计入有效成员/资格人数。
 * 邀请中、拒绝、离开、移除都不是有效成员。
 */
class CompetitionTeamMember extends Model
{
    protected $table = 'competition_team_members';

    public const ROLE_CAPTAIN = 'captain';

    public const ROLE_MEMBER = 'member';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_LEFT = 'left';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'team_id',
        'customer_id',
        'role',
        'status',
        'invited_by_customer_id',
        'invited_at',
        'responded_at',
        'joined_at',
        'left_at',
        'row_version',
    ];

    protected $casts = [
        'team_id'                 => 'integer',
        'customer_id'             => 'integer',
        'invited_by_customer_id'  => 'integer',
        'invited_at'              => UtcDateTime::class,
        'responded_at'            => UtcDateTime::class,
        'joined_at'               => UtcDateTime::class,
        'left_at'                 => UtcDateTime::class,
        'row_version'             => 'integer',
    ];

    public function team()
    {
        return $this->belongsTo(CompetitionTeam::class, 'team_id');
    }

    public function customer()
    {
        return $this->belongsTo(\Webkul\Customer\Models\Customer::class, 'customer_id');
    }

    public function isActive(): bool
    {
        return (string) $this->status === self::STATUS_ACTIVE;
    }
}
