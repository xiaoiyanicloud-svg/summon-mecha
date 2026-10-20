<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 赛务阶段（G3-06）。阶段先 draft，运营显式发布后才可能公开。
 */
class CompetitionStage extends Model
{
    protected $table = 'competition_stages';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'edition_id',
        'track_id',
        'stage_key',
        'name',
        'sort_order',
        'status',
        'published_at',
        'row_version',
    ];

    protected $casts = [
        'edition_id'   => 'integer',
        'track_id'     => 'integer',
        'sort_order'   => 'integer',
        'published_at' => UtcDateTime::class,
        'row_version'  => 'integer',
    ];

    public function track()
    {
        return $this->belongsTo(CompetitionTrack::class, 'track_id');
    }

    public function matches()
    {
        return $this->hasMany(CompetitionMatch::class, 'stage_id');
    }
}
