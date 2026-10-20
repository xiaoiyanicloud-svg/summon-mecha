<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 赛事报名主体（G2-02）。
 *
 * 一行代表“同一活动同一身份”的稳定主体；每一轮报名在
 * `event_registration_attempts` 里保存不可覆盖的快照。
 *
 * 历史行（G0/G1 之前）保留原 `name/phone/city/note`，标记
 * `identity_kind=legacy`，`scope_id/subject_key` 为空：
 * **不得**按手机号自动认领、不得并入新流程的本人报名列表。
 */
class EventRegistration extends Model
{
    protected $table = 'event_registrations';

    public const IDENTITY_LEGACY = 'legacy';

    public const IDENTITY_CUSTOMER = 'customer';

    /**
     * G3-03：队伍报名的稳定主体。`customer_id` 记录提交当时的队长，
     * 但归属由 `team_id` + `subject_key=t:{team_id}` 决定，不随队长移交改归属。
     */
    public const IDENTITY_TEAM = 'team';

    protected $fillable = [
        'event_id',
        'customer_id',
        'name',
        'phone',
        'city',
        'note',

        // G2
        'scope_id',
        'team_id',
        'subject_key',
        'public_no',
        'current_attempt_id',
        'identity_kind',
        'row_version',
    ];

    protected $casts = [
        'event_id'           => 'integer',
        'customer_id'        => 'integer',
        'scope_id'           => 'integer',
        'team_id'            => 'integer',
        'current_attempt_id' => 'integer',
        'row_version'        => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function scope()
    {
        return $this->belongsTo(EventRegistrationScope::class, 'scope_id');
    }

    /**
     * G3-03 队伍报名归属。个人/历史报名为 NULL。
     */
    public function team()
    {
        return $this->belongsTo(CompetitionTeam::class, 'team_id');
    }

    public function currentAttempt()
    {
        return $this->belongsTo(EventRegistrationAttempt::class, 'current_attempt_id');
    }

    public function attempts()
    {
        return $this->hasMany(EventRegistrationAttempt::class, 'registration_id');
    }
}
