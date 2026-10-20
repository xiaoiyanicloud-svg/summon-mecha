<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 报名轮次（G2-02）。
 *
 * 每个 registration 的每一轮报名都是一条不可覆盖的 attempt：
 * 联系人密文、同意快照、规则/隐私版本、处理人与时间全部保留。
 * 旧轮次终态后重报创建 `attempt_no + 1`，不复用旧行。
 */
class EventRegistrationAttempt extends Model
{
    protected $table = 'event_registration_attempts';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const ACTIVE_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN];

    public const SEAT_STATUSES = [self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN];

    protected $fillable = [
        'registration_id',
        'event_id',
        'scope_id',
        'owner_customer_id',
        'attempt_no',
        'status',
        'review_mode',
        'form_version',
        'form_snapshot',
        'rules_version_id',
        'rules_fingerprint',
        'privacy_version',
        'privacy_fingerprint',
        'consent_accepted_at',
        'contact_name_enc',
        'contact_phone_enc',
        'contact_phone_hash',
        'contact_phone_last4',
        'contact_city',
        'note_enc',
        'request_key',
        'submitted_at',
        'decided_at',
        'decided_by',
        'decision_reason_code',
        'decision_note',
        'canceled_at',
        'cancel_reason_code',
        'canceled_by',
        'checked_in_at',
        'checked_in_by',
        'checkin_source',
        'row_version',
    ];

    protected $casts = [
        'attempt_no'          => 'integer',
        'form_version'        => 'integer',
        'form_snapshot'       => 'array',
        'rules_version_id'    => 'integer',
        'consent_accepted_at' => UtcDateTime::class,
        'submitted_at'        => UtcDateTime::class,
        'decided_at'          => UtcDateTime::class,
        'decided_by'          => 'integer',
        'canceled_at'         => UtcDateTime::class,
        'canceled_by'         => 'integer',
        'checked_in_at'       => UtcDateTime::class,
        'checked_in_by'       => 'integer',
        'row_version'         => 'integer',
    ];

    public function registration()
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function scope()
    {
        return $this->belongsTo(EventRegistrationScope::class, 'scope_id');
    }

    public function checkinTokens()
    {
        return $this->hasMany(EventCheckinToken::class, 'attempt_id');
    }

    public function changeAcks()
    {
        return $this->hasMany(EventChangeAcknowledgment::class, 'attempt_id');
    }

    public function isActive(): bool
    {
        return in_array((string) $this->status, self::ACTIVE_STATUSES, true);
    }

    public function occupiesSeat(): bool
    {
        return in_array((string) $this->status, self::SEAT_STATUSES, true);
    }
}
