<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 活动变更事实（G2-08）。
 *
 * 改期/取消/调额/关闭/恢复都留版本。需要确认的变更必须显式配置
 * ack_deadline / decline_releases_seat / no_response_action；
 * 政策缺失时不得发布，不用代码默认值替业务补空白。
 */
class EventChange extends Model
{
    protected $table = 'event_changes';

    public const TYPE_RESCHEDULE = 'reschedule';

    public const TYPE_CANCEL = 'cancel';

    public const TYPE_CAPACITY = 'capacity';

    public const TYPE_CLOSE = 'close';

    public const TYPE_REOPEN = 'reopen';

    protected $fillable = [
        'event_id',
        'change_version',
        'type',
        'summary',
        'details',
        'requires_ack',
        'ack_deadline',
        'decline_releases_seat',
        'no_response_action',
        'status',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'event_id'             => 'integer',
        'change_version'       => 'integer',
        'details'              => 'array',
        'requires_ack'         => 'boolean',
        'ack_deadline'         => UtcDateTime::class,
        'decline_releases_seat'=> 'boolean',
        'created_by'           => 'integer',
        'published_at'         => UtcDateTime::class,
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function acknowledgments()
    {
        return $this->hasMany(EventChangeAcknowledgment::class, 'change_id');
    }
}
