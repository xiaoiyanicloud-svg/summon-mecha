<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * G3-08 · 成绩主体。
 *
 * `status` 描述主体当前阶段：draft/submitted/provisional/final/revising。
 * 公开读取永远以 `published_version_id` 为准；修订期间旧版本仍可回查，
 * 但会标记 under_review，绝不让新草稿冒充已公布结论。
 */
class CompetitionResult extends Model
{
    protected $table = 'competition_results';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PROVISIONAL = 'provisional';

    public const STATUS_FINAL = 'final';

    public const STATUS_REVISING = 'revising';

    public const PUBLIC_STATUSES = [self::STATUS_PROVISIONAL, self::STATUS_FINAL, self::STATUS_REVISING];

    protected $fillable = [
        'edition_id',
        'track_id',
        'entry_id',
        'match_id',
        'scope_key',
        'status',
        'current_version_id',
        'published_version_id',
        'row_version',
    ];

    protected $casts = [
        'edition_id'           => 'integer',
        'track_id'             => 'integer',
        'entry_id'             => 'integer',
        'match_id'             => 'integer',
        'current_version_id'   => 'integer',
        'published_version_id' => 'integer',
        'row_version'          => 'integer',
    ];

    public function track()
    {
        return $this->belongsTo(CompetitionTrack::class, 'track_id');
    }

    public function entry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_id');
    }

    public function match()
    {
        return $this->belongsTo(CompetitionMatch::class, 'match_id');
    }

    public function currentVersion()
    {
        return $this->belongsTo(CompetitionResultVersion::class, 'current_version_id');
    }

    public function publishedVersion()
    {
        return $this->belongsTo(CompetitionResultVersion::class, 'published_version_id');
    }

    public function versions()
    {
        return $this->hasMany(CompetitionResultVersion::class, 'result_id');
    }

    public static function scopeKeyFor(?int $matchId): string
    {
        return $matchId === null || $matchId === 0 ? 'track' : 'match:'.$matchId;
    }
}
