<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 赛事(Event)模型 —— 支撑首页生态中心「赛事活动」tab 的平台级闭环。
 *
 * 后台(Admin「赛事管理」)维护赛事 → 前台(Shop API)展示 → 用户报名落 event_registrations。
 */
class Event extends Model
{
    protected $table = 'events';

    protected $fillable = [
        'name',
        'type',
        'status',
        'date',
        'city',
        'location',
        'organizer',
        'hot',
        'teams',
        'prize',
        'schedule',
        'history',
        'detail',
        'icon',
        'bg_color',
        'sort',
    ];

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class);
    }
}
