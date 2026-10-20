<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 赛务场次（G3-06）。时间以 UTC 存储；发布后公开读取只返回已发布场次。
 */
class CompetitionMatch extends Model
{
    protected $table = 'competition_matches';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'edition_id',
        'track_id',
        'stage_id',
        'match_key',
        'round_no',
        'starts_at',
        'ends_at',
        'venue',
        'home_entry_id',
        'away_entry_id',
        'status',
        'published_at',
        'row_version',
    ];

    protected $casts = [
        'edition_id'    => 'integer',
        'track_id'      => 'integer',
        'stage_id'      => 'integer',
        'round_no'      => 'integer',
        'starts_at'     => UtcDateTime::class,
        'ends_at'       => UtcDateTime::class,
        'home_entry_id' => 'integer',
        'away_entry_id' => 'integer',
        'published_at'  => UtcDateTime::class,
        'row_version'   => 'integer',
    ];

    public function stage()
    {
        return $this->belongsTo(CompetitionStage::class, 'stage_id');
    }

    public function homeEntry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'home_entry_id');
    }

    public function awayEntry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'away_entry_id');
    }
}
