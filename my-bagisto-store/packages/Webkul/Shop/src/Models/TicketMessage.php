<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 工单消息 —— v3.4 步骤 7。
 */
class TicketMessage extends Model
{
    protected $table = 'ticket_message';

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_DEVELOPER = 'developer';

    public const ACTOR_SYSTEM = 'system';

    /**
     * 内部备注：客服之间协作用，**永不进入用户侧任何接口**。
     *
     * 用 actor_type 而不是单独建表，是因为备注天然属于同一条会话时间线；
     * 但所有用户侧查询都必须显式排除它（见 TicketController 的 note 过滤）。
     */
    public const ACTOR_NOTE = 'note';

    /**
     * 代表「平台方已回复」的 actor_type。
     *
     * `last_staff_message_at` 与首响统计只认这两类，备注不算回复。
     */
    public const STAFF_ACTOR_TYPES = [self::ACTOR_ADMIN, self::ACTOR_DEVELOPER];

    /**
     * 工单消息只记 created_at(表无 updated_at;消息不可改)。
     */
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'actor_type',
        'actor_id',
        'content',
        'attachments',
        'created_at',
        'read_at',
    ];

    protected $casts = [
        'ticket_id'   => 'integer',
        'actor_id'    => 'integer',
        'attachments' => 'array',
        'created_at'  => 'datetime',
        'read_at'     => 'datetime',
    ];
}
