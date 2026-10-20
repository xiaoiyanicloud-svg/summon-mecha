<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * G3-10 · 奖项版本（历史保留）。
 *
 * status: draft / published / pending_review / superseded / revoked。
 * 成绩修订后旧 published 版本转 pending_review；新版本发布后旧版本转 superseded。
 */
class CompetitionAwardVersion extends Model
{
    protected $table = 'competition_award_versions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'award_id',
        'version_no',
        'entry_id',
        'result_version_id',
        'rank_no',
        'status',
        'reason',
        'snapshot',
        'created_by_admin_id',
        'published_by_admin_id',
        'published_at',
        'revoked_by_admin_id',
        'revoked_at',
        'revocation_reason',
        'row_version',
    ];

    protected $casts = [
        'award_id'             => 'integer',
        'version_no'           => 'integer',
        'entry_id'             => 'integer',
        'result_version_id'    => 'integer',
        'rank_no'              => 'integer',
        'snapshot'             => 'array',
        'created_by_admin_id'  => 'integer',
        'published_by_admin_id'=> 'integer',
        'published_at'         => UtcDateTime::class,
        'revoked_by_admin_id'  => 'integer',
        'revoked_at'           => UtcDateTime::class,
        'row_version'          => 'integer',
    ];

    public function award()
    {
        return $this->belongsTo(CompetitionAward::class, 'award_id');
    }

    public function entry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_id');
    }

    public function resultVersion()
    {
        return $this->belongsTo(CompetitionResultVersion::class, 'result_version_id');
    }
}
