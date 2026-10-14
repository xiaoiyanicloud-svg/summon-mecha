<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能编译记录 —— 中立定义 → 各机型载体(v3.4 步骤 3 新增,对应计划 §4.20 转换层)。
 *
 * 一个技能版本 → N 条 build(每目标机型一条)。
 * artifact_asset_id 指向编译产物(skill_assets.id)。
 */
class SkillBuild extends Model
{
    protected $table = 'skill_build';

    protected $fillable = [
        'skill_version_id',
        'target_model_code',
        'target_agent_type',
        'status',
        'carrier_type',
        'artifact_asset_id',
        'retarget_conf',
        'build_log',
        'error',
        'built_at',
    ];

    protected $casts = [
        'skill_version_id'  => 'integer',
        'artifact_asset_id' => 'integer',
        'retarget_conf'     => 'array',
        'built_at'          => 'datetime',
    ];
}
