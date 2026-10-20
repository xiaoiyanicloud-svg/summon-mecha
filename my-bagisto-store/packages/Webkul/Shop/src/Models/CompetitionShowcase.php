<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * G3-11 · 作品展示。
 *
 * 授权绑定具体提交版本与内容指纹；展示发布不触发上架、license 或真机下发。
 */
class CompetitionShowcase extends Model
{
    protected $table = 'competition_showcases';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const TYPE_AWARD = 'award';

    public const TYPE_RECAP = 'recap';

    protected $fillable = [
        'edition_id',
        'track_id',
        'entry_id',
        'submission_version_id',
        'award_id',
        'showcase_type',
        'title',
        'summary',
        'status',
        'consent_at',
        'consent_by_customer_id',
        'consent_fingerprint',
        'consent_scope',
        'reviewed_by_admin_id',
        'reviewed_at',
        'published_by_admin_id',
        'published_at',
        'rejected_reason',
        'skill_version_id',
        'developer_handoff_at',
        'handoff_result',
        'row_version',
    ];

    protected $casts = [
        'edition_id'             => 'integer',
        'track_id'               => 'integer',
        'entry_id'               => 'integer',
        'submission_version_id'  => 'integer',
        'award_id'               => 'integer',
        'consent_at'             => UtcDateTime::class,
        'consent_by_customer_id' => 'integer',
        'consent_scope'          => 'array',
        'reviewed_by_admin_id'   => 'integer',
        'reviewed_at'            => UtcDateTime::class,
        'published_by_admin_id'  => 'integer',
        'published_at'           => UtcDateTime::class,
        'skill_version_id'       => 'integer',
        'developer_handoff_at'   => UtcDateTime::class,
        'handoff_result'         => 'array',
        'row_version'            => 'integer',
    ];

    public function track()
    {
        return $this->belongsTo(CompetitionTrack::class, 'track_id');
    }

    public function entry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_id');
    }

    public function submissionVersion()
    {
        return $this->belongsTo(CompetitionSubmissionVersion::class, 'submission_version_id');
    }

    public function award()
    {
        return $this->belongsTo(CompetitionAward::class, 'award_id');
    }
}
