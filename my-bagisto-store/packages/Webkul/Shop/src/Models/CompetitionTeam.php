<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 参赛队伍（G3-03）。
 *
 * 队伍属于某个赛项；同一赛项内 `name_key` 唯一。队长是客户账号
 * `captain_customer_id`，但队伍本身是独立主体：队伍报名使用
 * `subject_key=t:{team_id}`，不随队长账号变化而改归属。
 */
class CompetitionTeam extends Model
{
    protected $table = 'competition_teams';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISBANDED = 'disbanded';

    protected $fillable = [
        'edition_id',
        'track_id',
        'captain_customer_id',
        'name',
        'name_key',
        'status',
        'row_version',
    ];

    protected $casts = [
        'edition_id'          => 'integer',
        'track_id'            => 'integer',
        'captain_customer_id' => 'integer',
        'row_version'         => 'integer',
    ];

    public function edition()
    {
        return $this->belongsTo(CompetitionEdition::class, 'edition_id');
    }

    public function track()
    {
        return $this->belongsTo(CompetitionTrack::class, 'track_id');
    }

    public function captain()
    {
        return $this->belongsTo(\Webkul\Customer\Models\Customer::class, 'captain_customer_id');
    }

    public function members()
    {
        return $this->hasMany(CompetitionTeamMember::class, 'team_id');
    }

    public function activeMembers()
    {
        return $this->members()->where('status', CompetitionTeamMember::STATUS_ACTIVE);
    }

    public function invites()
    {
        return $this->hasMany(CompetitionTeamInvite::class, 'team_id');
    }

    public function entries()
    {
        return $this->hasMany(CompetitionEntry::class, 'team_id');
    }
}
