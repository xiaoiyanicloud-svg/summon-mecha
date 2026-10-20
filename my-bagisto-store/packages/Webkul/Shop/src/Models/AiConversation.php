<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiConversation extends Model
{
    use SoftDeletes;

    protected $table = 'ai_conversations';

    protected $fillable = [
        'customer_id',
        'client_id',
        'title',
        'messages',
        'client_updated_at',
    ];

    protected $casts = [
        'customer_id'       => 'integer',
        'messages'          => 'array',
        'client_updated_at' => 'integer',
        'deleted_at'        => 'datetime',
    ];
}
