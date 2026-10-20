<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * G3-10 · 奖项主体。
 *
 * `award_key` 在赛项内唯一（champion / runner_up ...）。公开状态只认
 * 当前版本仍为 published 且引用当前终审成绩版本。
 */
class CompetitionAward extends Model
{
    protected $table = 'competition_awards';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'edition_id',
        'track_id',
        'award_key',
        'name',
        'sort_order',
        'status',
        'current_version_id',
        'row_version',
    ];

    protected $casts = [
        'edition_id'         => 'integer',
        'track_id'           => 'integer',
        'sort_order'         => 'integer',
        'current_version_id' => 'integer',
        'row_version'        => 'integer',
    ];

    public function track()
    {
        return $this->belongsTo(CompetitionTrack::class, 'track_id');
    }

    public function versions()
    {
        return $this->hasMany(CompetitionAwardVersion::class, 'award_id');
    }

    public function currentVersion()
    {
        return $this->belongsTo(CompetitionAwardVersion::class, 'current_version_id');
    }
}
