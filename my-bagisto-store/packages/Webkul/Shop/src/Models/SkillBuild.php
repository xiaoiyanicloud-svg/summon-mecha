<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能编译记录 —— 中立定义 → 各机型载体(v3.4 步骤 3 新增,对应计划 §4.20 转换层)。
 *
 * 一个技能版本 → N 条 build(每目标机型一条)。
 * `artifact_asset_id` 指向编译产物(skill_assets.id)。
 *
 * ⚠ 口径更正(2026-10-16 · D-9):本表记的是**编排意图/登记**,不是已经产出的载体。
 *   真正把中立动作映射到各机型关节的产物需**外部重定向工具产出或人工回填**
 *   (`artifact_asset_id`)——**平台侧当前没有生产者**,故受支持的 kind(motion/composite)
 *   经编译后 status 恒为 `pending`,`artifact_asset_id` 恒为 NULL。**别把本表读成
 *   "编译已完成"**。本表也**不在交付链路上**:交付/下发只认 `skill_assets`(见 D1/D2),
 *   唯一读点是 internal.key 的回读接口 `SkillSupplyController::builds()`。
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
