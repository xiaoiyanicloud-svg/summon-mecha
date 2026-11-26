<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceReconcileReport extends Model
{
    protected $table = 'device_reconcile_report';

    public $timestamps = false;

    protected $fillable = [
        'did', 'model_code', 'observed_generation', 'report_hash',
        'installed_skills', 'active_executions', 'outbox_cursor',
        'drift_count', 'reported_at', 'received_at',
    ];

    protected $casts = [
        'installed_skills' => 'array',
        'active_executions' => 'array',
        'observed_generation' => 'integer',
        'drift_count' => 'integer',
        'reported_at' => 'integer',
        'received_at' => 'datetime',
    ];
}