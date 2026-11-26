<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class DeployInboxEvent extends Model
{
    protected $table = 'deploy_inbox_event';

    protected $fillable = [
        'did', 'event_id', 'task_no', 'event_type', 'event_seq', 'stage',
        'payload_hash', 'payload', 'applied', 'result_status', 'late_report',
        'ack_id', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'event_seq' => 'integer',
        'applied' => 'boolean',
        'late_report' => 'boolean',
        'processed_at' => 'datetime',
    ];
}