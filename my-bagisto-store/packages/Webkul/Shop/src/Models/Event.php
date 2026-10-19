<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

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

        // G1-02 可信发布字段（ADR-0001 §4.2）。旧字段行为不变；
        // 新字段的并发写入口是 Services\Ecosystem\EventWriteService。
        'kind',
        'participation_mode',
        'publish_status',
        'disposition',
        'hosting_status',
        'timezone',
        'starts_at',
        'ends_at',
        'published_revision_id',
        'working_revision_id',
        'first_published_at',
        'published_at',
        'row_version',
    ];

    protected $casts = [
        // G1 时间字段以 UTC 存储（ADR G1-D04）。应用时区是 Asia/Shanghai，
        // 不能用默认 datetime cast 读取，否则会整体偏 8 小时。
        'starts_at'             => UtcDateTime::class,
        'ends_at'               => UtcDateTime::class,
        'first_published_at'    => UtcDateTime::class,
        'published_at'          => UtcDateTime::class,
        'published_revision_id' => 'integer',
        'working_revision_id'   => 'integer',
        'row_version'           => 'integer',
    ];

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class);
    }
}
