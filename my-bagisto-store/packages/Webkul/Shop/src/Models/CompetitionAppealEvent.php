<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * G3-09 · 申诉时间线（只追加）。
 *
 * 提交、受理、调查、裁决、撤回都写一条；不更新、不删除，
 * 保证任何结论都能回查到谁在什么时候基于哪个版本做了什么。
 */
class CompetitionAppealEvent extends Model
{
    protected $table = 'competition_appeal_events';

    public const TYPE_SUBMITTED = 'submitted';

    public const TYPE_ACCEPTED = 'accepted';

    public const TYPE_INVESTIGATING = 'investigating';

    public const TYPE_DECISION = 'decision';

    public const TYPE_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'appeal_id',
        'event_type',
        'actor_type',
        'actor_id',
        'reason',
        'payload',
    ];

    protected $casts = [
        'appeal_id' => 'integer',
        'actor_id'  => 'integer',
        'payload'   => 'array',
    ];

    public function appeal()
    {
        return $this->belongsTo(CompetitionAppeal::class, 'appeal_id');
    }
}
