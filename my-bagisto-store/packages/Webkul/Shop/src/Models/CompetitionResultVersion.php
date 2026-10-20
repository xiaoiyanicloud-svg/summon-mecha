<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * G3-08 · 成绩版本（不可变）。
 *
 * draft 阶段可修改；提交复核后正文/分数/排名不可改。修订必须新建版本，
 * 旧版本标 superseded，历史保留。
 */
class CompetitionResultVersion extends Model
{
    protected $table = 'competition_result_versions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PROVISIONAL = 'provisional';

    public const STATUS_FINAL = 'final';

    public const STATUS_SUPERSEDED = 'superseded';

    public const IMMUTABLE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_PROVISIONAL,
        self::STATUS_FINAL,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'result_id',
        'track_id',
        'entry_id',
        'match_id',
        'version_no',
        'status',
        'superseded_status',
        'supersedes_version_id',
        'score_values',
        'total_scaled',
        'rank_no',
        'tie_group',
        'forfeit_status',
        'excluded',
        'scoring_policy',
        'scoring_snapshot',
        'scoring_fingerprint',
        'rules_version_id',
        'rules_fingerprint',
        'reason',
        'created_by_admin_id',
        'submitted_at',
        'reviewed_by_admin_id',
        'reviewed_at',
        'published_at',
        'finalized_by_admin_id',
        'finalized_at',
        'revision_reason',
        'row_version',
    ];

    protected $casts = [
        'result_id'             => 'integer',
        'track_id'              => 'integer',
        'entry_id'              => 'integer',
        'match_id'              => 'integer',
        'version_no'            => 'integer',
        'supersedes_version_id' => 'integer',
        'score_values'          => 'array',
        'scoring_snapshot'      => 'array',
        'rank_no'               => 'integer',
        'excluded'              => 'boolean',
        'rules_version_id'      => 'integer',
        'created_by_admin_id'   => 'integer',
        'reviewed_by_admin_id'  => 'integer',
        'finalized_by_admin_id' => 'integer',
        'submitted_at'          => UtcDateTime::class,
        'reviewed_at'           => UtcDateTime::class,
        'published_at'          => UtcDateTime::class,
        'finalized_at'          => UtcDateTime::class,
        'row_version'           => 'integer',
    ];

    public function result()
    {
        return $this->belongsTo(CompetitionResult::class, 'result_id');
    }

    public function isDraft(): bool
    {
        return (string) $this->status === self::STATUS_DRAFT;
    }

    public function isImmutable(): bool
    {
        return in_array((string) $this->status, self::IMMUTABLE_STATUSES, true);
    }
}
