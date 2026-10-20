<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class AiAnalyticsEvent extends Model
{
    protected $table = 'ai_analytics_events';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'customer_id',
        'device_hash',
        'event_name',
        'properties',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'properties'  => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];
}
