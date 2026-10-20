<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 作品提交主体（G3-05）。一个 entry 一个 submission；
 * 版本记录在 `competition_submission_versions`，提交后不可变。
 */
class CompetitionSubmission extends Model
{
    protected $table = 'competition_submissions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CORRECTION_REQUIRED = 'correction_required';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'entry_id',
        'track_id',
        'status',
        'current_version_id',
        'deadline_at',
        'submitted_at',
        'row_version',
    ];

    protected $casts = [
        'entry_id'           => 'integer',
        'track_id'           => 'integer',
        'current_version_id' => 'integer',
        'deadline_at'        => UtcDateTime::class,
        'submitted_at'       => UtcDateTime::class,
        'row_version'        => 'integer',
    ];

    public function entry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_id');
    }

    public function versions()
    {
        return $this->hasMany(CompetitionSubmissionVersion::class, 'submission_id');
    }

    public function currentVersion()
    {
        return $this->belongsTo(CompetitionSubmissionVersion::class, 'current_version_id');
    }
}
