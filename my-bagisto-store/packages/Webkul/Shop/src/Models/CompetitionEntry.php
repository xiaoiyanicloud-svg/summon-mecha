<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 参赛条目（G3-04）。
 *
 * `roster_snapshot` / `eligibility_snapshot` / `robot_declaration` 是提交时冻结的
 * 事实；后续队伍改名、换人、规则更新都不回溯覆盖。需要变更时新建修订流程
 * （G3-10 不在本批）。
 */
class CompetitionEntry extends Model
{
    protected $table = 'competition_entries';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_DISQUALIFIED = 'disqualified';

    public const ACTIVE_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_CONFIRMED];

    protected $fillable = [
        'entry_no',
        'edition_id',
        'track_id',
        'team_id',
        'registration_id',
        'attempt_id',
        'captain_customer_id',
        'status',
        'roster_snapshot',
        'eligibility_snapshot',
        'robot_declaration',
        'rules_version_id',
        'rules_fingerprint',
        'frozen_at',
        'frozen_by_customer_id',
        'row_version',
    ];

    protected $casts = [
        'edition_id'             => 'integer',
        'track_id'               => 'integer',
        'team_id'                => 'integer',
        'registration_id'        => 'integer',
        'attempt_id'             => 'integer',
        'captain_customer_id'    => 'integer',
        'roster_snapshot'        => 'array',
        'eligibility_snapshot'   => 'array',
        'robot_declaration'      => 'array',
        'rules_version_id'       => 'integer',
        'frozen_at'              => UtcDateTime::class,
        'frozen_by_customer_id'  => 'integer',
        'row_version'            => 'integer',
    ];

    public function edition()
    {
        return $this->belongsTo(CompetitionEdition::class, 'edition_id');
    }

    public function track()
    {
        return $this->belongsTo(CompetitionTrack::class, 'track_id');
    }

    public function team()
    {
        return $this->belongsTo(CompetitionTeam::class, 'team_id');
    }

    public function registration()
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function attempt()
    {
        return $this->belongsTo(EventRegistrationAttempt::class, 'attempt_id');
    }

    public function submission()
    {
        return $this->hasOne(CompetitionSubmission::class, 'entry_id');
    }

    public function isActive(): bool
    {
        return in_array((string) $this->status, self::ACTIVE_STATUSES, true);
    }
}
