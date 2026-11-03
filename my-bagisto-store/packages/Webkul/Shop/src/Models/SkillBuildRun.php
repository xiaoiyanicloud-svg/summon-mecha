<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 技能构建执行记录 —— 保留每次独立构建 Run 历史（不可变日志追溯）。
 */
class SkillBuildRun extends Model
{
    protected $table = 'skill_build_runs';

    protected $fillable = [
        'skill_version_id',
        'target_model_code',
        'build_key',
        'run_number',
        'source_digest',
        'target_profile_digest',
        'status',
        'carrier_type',
        'artifact_asset_id',
        'build_log',
        'error',
    ];

    protected $casts = [
        'skill_version_id'  => 'integer',
        'run_number'        => 'integer',
        'artifact_asset_id' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'skill_version_id');
    }

    public function artifactAsset(): BelongsTo
    {
        return $this->belongsTo(SkillAsset::class, 'artifact_asset_id');
    }
}
