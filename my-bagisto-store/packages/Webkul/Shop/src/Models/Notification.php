<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 通知中心(v3.4 步骤 4)。
 *
 * channel: inapp(站内信,本次实现) / subscribe_msg(微信订阅消息,待小程序配置) / sms
 */
class Notification extends Model
{
    protected $table = 'notification';

    public const CHANNEL_INAPP = 'inapp';

    public const CHANNEL_SUBSCRIBE = 'subscribe_msg';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'type',
        'title',
        'content',
        'payload',
        'channel',
        'read_at',
        'sent_at',
    ];

    protected $casts = [
        'recipient_id' => 'integer',
        'payload'      => 'array',
        'read_at'      => 'datetime',
        'sent_at'      => 'datetime',
    ];
}
