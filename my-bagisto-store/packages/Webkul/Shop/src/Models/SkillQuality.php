<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能质量分 —— 过审后的好/一般分级(v3.4 步骤 3 新增,对应计划 §4.21)。
 *
 * ⚠ 不合格技能不在此表 —— 它已被审核拒绝(§4.8 三层准入)。
 *   grade: qualified(合格) / good(优质) / featured(精选)
 */
class SkillQuality extends Model
{
    protected $table = 'skill_quality';

    public const GRADE_QUALIFIED = 'qualified';

    public const GRADE_GOOD = 'good';

    public const GRADE_FEATURED = 'featured';

    protected $fillable = [
        'skill_version_id',
        'sim_score',
        'install_success_rate',
        'rating_avg',
        'uninstall_rate',
        'grade',
        'graded_at',
    ];

    protected $casts = [
        'skill_version_id'     => 'integer',
        'sim_score'            => 'float',
        'install_success_rate' => 'float',
        'rating_avg'           => 'float',
        'uninstall_rate'       => 'float',
        'graded_at'            => 'datetime',
    ];
}
