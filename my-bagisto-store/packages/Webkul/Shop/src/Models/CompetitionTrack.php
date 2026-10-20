<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 赛项（G3-02）。一个赛项绑定：
 *   · 一个届次（edition_id）；
 *   · 一个报名 scope（registration_scope_id，必须属于同届活动，服务层校验）；
 *   · 一个已批准的规则版本（rules_version_id）。
 *
 * `rules_profile` 是 G3-01 的机器可读规则契约；`rules_fingerprint` 是对规范化
 * 契约 + 规则版本指纹的 sha256。**模型不提供任何默认策略**。
 */
class CompetitionTrack extends Model
{
    protected $table = 'competition_tracks';

    protected $fillable = [
        'edition_id',
        'track_key',
        'name',
        'discipline',
        'summary',
        'registration_scope_id',
        'rules_version_id',
        'status',
        'rules_profile',
        'rules_fingerprint',
        'rules_confirmed_at',
        'rules_confirmed_by',
        'min_team_size',
        'max_team_size',
        'allow_multi_team',
        'submission_deadline_at',
        'allow_correction',
        'row_version',
    ];

    protected $casts = [
        'edition_id'             => 'integer',
        'registration_scope_id'  => 'integer',
        'rules_version_id'       => 'integer',
        'rules_profile'          => 'array',
        'rules_confirmed_at'     => UtcDateTime::class,
        'rules_confirmed_by'     => 'integer',
        'min_team_size'          => 'integer',
        'max_team_size'          => 'integer',
        'allow_multi_team'       => 'boolean',
        'submission_deadline_at' => UtcDateTime::class,
        'allow_correction'       => 'boolean',
        'row_version'            => 'integer',
    ];

    public function edition()
    {
        return $this->belongsTo(CompetitionEdition::class, 'edition_id');
    }

    public function scope()
    {
        return $this->belongsTo(EventRegistrationScope::class, 'registration_scope_id');
    }

    public function ruleVersion()
    {
        return $this->belongsTo(EventRuleVersion::class, 'rules_version_id');
    }

    public function teams()
    {
        return $this->hasMany(CompetitionTeam::class, 'track_id');
    }

    public function stages()
    {
        return $this->hasMany(CompetitionStage::class, 'track_id');
    }

    /**
     * 规则是否已完整确认。只读已落库事实，不在读取时补默认值。
     */
    public function rulesConfirmed(): bool
    {
        return $this->rules_confirmed_at !== null
            && $this->rules_fingerprint !== null
            && is_array($this->rules_profile)
            && $this->rules_version_id !== null;
    }
}
