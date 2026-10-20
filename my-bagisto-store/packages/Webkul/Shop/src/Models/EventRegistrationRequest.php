<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 幂等请求事实（G2-03）。
 *
 * 同一 actor + action + request_key 只能对应一个载荷指纹；
 * 同 key 不同载荷是调用方 bug，必须 409，不能静默覆盖第一条事实。
 */
class EventRegistrationRequest extends Model
{
    protected $table = 'event_registration_requests';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'action',
        'request_key',
        'payload_hash',
        'target_type',
        'target_id',
        'result_type',
        'result_id',
        'result_public_no',
        'completed_at',
    ];

    protected $casts = [
        'actor_id'        => 'integer',
        'target_id'       => 'integer',
        'result_id'       => 'integer',
        'completed_at'    => UtcDateTime::class,
    ];
}
