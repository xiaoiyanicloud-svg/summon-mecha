<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 作品版本（G3-05）。
 *
 * `status=submitted` 后正文与附件不可再改；补正必须创建 `version_no + 1`。
 * `content_fingerprint` 钉住提交时的正文事实。
 */
class CompetitionSubmissionVersion extends Model
{
    protected $table = 'competition_submission_versions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'submission_id',
        'version_no',
        'title',
        'summary',
        'description',
        'repo_url',
        'video_url',
        'content_fingerprint',
        'status',
        'created_by_customer_id',
        'submitted_at',
        'row_version',
    ];

    protected $casts = [
        'submission_id'          => 'integer',
        'version_no'             => 'integer',
        'created_by_customer_id' => 'integer',
        'submitted_at'           => UtcDateTime::class,
        'row_version'            => 'integer',
    ];

    public function submission()
    {
        return $this->belongsTo(CompetitionSubmission::class, 'submission_id');
    }

    public function attachments()
    {
        return $this->hasMany(CompetitionSubmissionAttachment::class, 'version_id');
    }

    public function isDraft(): bool
    {
        return (string) $this->status === self::STATUS_DRAFT;
    }
}
