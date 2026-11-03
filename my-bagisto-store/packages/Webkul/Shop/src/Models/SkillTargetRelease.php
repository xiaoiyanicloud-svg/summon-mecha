<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 目标成品发布快照与独立审核记录（Work Package C01 / C06 / C07）。
 * 解耦中立源审核与各目标机型交付成品的独立准入。
 */
class SkillTargetRelease extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVOKED = 'revoked';

    protected $table = 'skill_target_releases';

    protected $fillable = [
        'skill_version_id',
        'target_model_code',
        'artifact_asset_id',
        'build_key',
        'review_status',
        'reviewed_at',
        'reviewer_id',
        'rejection_reason',
    ];

    protected $casts = [
        'skill_version_id'  => 'integer',
        'artifact_asset_id' => 'integer',
        'reviewer_id'       => 'integer',
        'reviewed_at'       => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'skill_version_id');
    }

    public function artifactAsset(): BelongsTo
    {
        return $this->belongsTo(SkillAsset::class, 'artifact_asset_id');
    }

    public function isApproved(): bool
    {
        return $this->review_status === self::STATUS_APPROVED;
    }
}
