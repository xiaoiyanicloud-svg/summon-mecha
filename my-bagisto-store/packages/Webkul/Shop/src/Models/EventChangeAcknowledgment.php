<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 变更确认记录（G2-08）。
 *
 * 只有真实响应才写 accepted/declined；未响应不是同意，
 * 到期由扫描按已公布政策处理。
 */
class EventChangeAcknowledgment extends Model
{
    protected $table = 'event_change_acknowledgments';

    public const RESPONSE_ACCEPTED = 'accepted';

    public const RESPONSE_DECLINED = 'declined';

    public const RESPONSE_NO_RESPONSE = 'no_response';

    protected $fillable = [
        'change_id',
        'registration_id',
        'attempt_id',
        'response',
        'responded_at',
        'note',
    ];

    protected $casts = [
        'change_id'       => 'integer',
        'registration_id' => 'integer',
        'attempt_id'      => 'integer',
        'responded_at'    => UtcDateTime::class,
    ];

    public function change()
    {
        return $this->belongsTo(EventChange::class, 'change_id');
    }

    public function attempt()
    {
        return $this->belongsTo(EventRegistrationAttempt::class, 'attempt_id');
    }
}
