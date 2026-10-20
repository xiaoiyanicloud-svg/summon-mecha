<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 赛事届次（G3-02）。
 *
 * 一个 edition 绑定一个 `events` 活动壳：活动负责公开内容/发布/报名，
 * edition 负责赛务届次身份。`event_id` 唯一，防止同一活动被挂到两届。
 */
class CompetitionEdition extends Model
{
    protected $table = 'competition_editions';

    protected $fillable = [
        'series_id',
        'event_id',
        'edition_no',
        'name',
        'starts_at',
        'ends_at',
        'timezone',
        'status',
        'row_version',
    ];

    protected $casts = [
        'series_id'   => 'integer',
        'event_id'    => 'integer',
        'edition_no'  => 'integer',
        'starts_at'   => UtcDateTime::class,
        'ends_at'     => UtcDateTime::class,
        'row_version' => 'integer',
    ];

    public function series()
    {
        return $this->belongsTo(CompetitionSeries::class, 'series_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function tracks()
    {
        return $this->hasMany(CompetitionTrack::class, 'edition_id');
    }
}
