<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 报名范围（G2-01）。
 *
 * 一个活动首期一个 `default` scope。容量三态与时间窗由
 * `Services\Ecosystem\RegistrationPolicy` / `RegistrationScopeService` 统一解释，
 * 不要在页面或控制器里复制判断。
 */
class EventRegistrationScope extends Model
{
    protected $table = 'event_registration_scopes';

    protected $fillable = [
        'event_id',
        'scope_key',
        'unit',
        'capacity',
        'capacity_confirmed_at',
        'confirmed_count',
        'review_mode',
        'registration_opens_at',
        'registration_closes_at',
        'review_closes_at',
        'cancel_policy',
        'reapply_policy',
        'max_attempts',
        'change_ack_required',
        'decline_releases_seat',
        'no_response_action',
        'form_version',
        'form_schema',
        'privacy_version',
        'privacy_summary',
        'rule_version_id',
        'status',
        'policy_confirmed_at',
        'policy_confirmed_by',
        'row_version',
    ];

    protected $casts = [
        'capacity'              => 'integer',
        'capacity_confirmed_at' => UtcDateTime::class,
        'confirmed_count'       => 'integer',
        'registration_opens_at' => UtcDateTime::class,
        'registration_closes_at'=> UtcDateTime::class,
        'review_closes_at'      => UtcDateTime::class,
        'max_attempts'          => 'integer',
        'change_ack_required'   => 'boolean',
        'decline_releases_seat' => 'boolean',
        'form_version'          => 'integer',
        'form_schema'           => 'array',
        'rule_version_id'       => 'integer',
        'policy_confirmed_at'   => UtcDateTime::class,
        'policy_confirmed_by'   => 'integer',
        'row_version'           => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class, 'scope_id');
    }

    public function ruleVersion()
    {
        return $this->belongsTo(EventRuleVersion::class, 'rule_version_id');
    }
}
