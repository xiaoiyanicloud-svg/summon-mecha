<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 赛事报名(EventRegistration)模型 —— 闭环的「转化」一环:前台报名落库,后台可查可跟进。
 */
class EventRegistration extends Model
{
    protected $table = 'event_registrations';

    protected $fillable = [
        'event_id',
        'customer_id',
        'name',
        'phone',
        'city',
        'note',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
