<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 工单消息 —— v3.4 步骤 7。
 */
class TicketMessage extends Model
{
    protected $table = 'ticket_message';

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
    ];

    protected $casts = [
        'ticket_id'   => 'integer',
        'actor_id'    => 'integer',
        'attachments' => 'array',
    ];
}
