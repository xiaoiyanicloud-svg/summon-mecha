<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * G3-09 · 申诉主体。
 *
 * 绑定“当次公布的成绩版本”；版本被修订后不得悄悄改绑。
 * 裁决字段与事件时间线分开保存，时间线只追加，不覆盖。
 */
class CompetitionAppeal extends Model
{
    protected $table = 'competition_appeals';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_DECIDED = 'decided';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const TERMINAL = [self::STATUS_DECIDED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    protected $fillable = [
        'appeal_no',
        'edition_id',
        'track_id',
        'entry_id',
        'result_id',
        'result_version_id',
        'submitted_by_customer_id',
        'status',
        'reason',
        'rule_version_id',
        'rules_fingerprint',
        'window_opens_at',
        'deadline_at',
        'submitted_at',
        'decided_at',
        'decided_by_admin_id',
        'decision_result',
        'decision_reason',
        'decision_result_version_id',
        'row_version',
    ];

    protected $casts = [
        'edition_id'                  => 'integer',
        'track_id'                    => 'integer',
        'entry_id'                    => 'integer',
        'result_id'                   => 'integer',
        'result_version_id'           => 'integer',
        'submitted_by_customer_id'    => 'integer',
        'rule_version_id'             => 'integer',
        'window_opens_at'             => UtcDateTime::class,
        'deadline_at'                 => UtcDateTime::class,
        'submitted_at'                => UtcDateTime::class,
        'decided_at'                  => UtcDateTime::class,
        'decided_by_admin_id'         => 'integer',
        'decision_result_version_id'  => 'integer',
        'row_version'                 => 'integer',
    ];

    public function track()
    {
        return $this->belongsTo(CompetitionTrack::class, 'track_id');
    }

    public function entry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_id');
    }

    public function result()
    {
        return $this->belongsTo(CompetitionResult::class, 'result_id');
    }

    public function events()
    {
        return $this->hasMany(CompetitionAppealEvent::class, 'appeal_id');
    }

    public function attachments()
    {
        return $this->hasMany(CompetitionAppealAttachment::class, 'appeal_id');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL, true);
    }
}
