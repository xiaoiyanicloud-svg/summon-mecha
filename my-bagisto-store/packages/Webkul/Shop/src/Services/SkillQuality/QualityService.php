<?php

namespace Webkul\Shop\Services\SkillQuality;

use Webkul\Shop\Models\SkillQuality;

/**
 * 质量分级服务 —— v3.4 步骤 6(计划 §4.21)。
 *
 * ⚠ 只对**已过审**的技能分级(不合格技能在 AuditService 被拒,不进本表)。
 *   质量分来自客观数据:仿真跑分 / 安装成功率 / 用户评分 / 卸载率。
 */
class QualityService
{
    /**
     * 依据指标计算等级。
     *
     * ★★★ featured : 仿真高分 + 评分≥4.5 + 成功率≥95%
     * ★★  good     : 评分≥4.0 + 成功率≥90%
     * ★   qualified: 过审即可
     */
    public function grade(
        float $simScore = 0,
        float $ratingAvg = 0,
        float $successRate = 100,
        float $uninstallRate = 0,
    ): string {
        if ($simScore >= 85 && $ratingAvg >= 4.5 && $successRate >= 95) {
            return SkillQuality::GRADE_FEATURED;
        }

        if ($ratingAvg >= 4.0 && $successRate >= 90) {
            return SkillQuality::GRADE_GOOD;
        }

        return SkillQuality::GRADE_QUALIFIED;
    }

    /**
     * 写入/更新一个技能版本的质量记录,返回等级。
     */
    public function record(
        int $skillVersionId,
        float $simScore = 0,
        float $ratingAvg = 0,
        float $successRate = 100,
        float $uninstallRate = 0,
    ): string {
        $grade = $this->grade($simScore, $ratingAvg, $successRate, $uninstallRate);

        SkillQuality::updateOrCreate(
            ['skill_version_id' => $skillVersionId],
            [
                'sim_score'            => $simScore,
                'rating_avg'           => $ratingAvg,
                'install_success_rate' => $successRate,
                'uninstall_rate'       => $uninstallRate,
                'grade'                => $grade,
                'graded_at'            => now(),
            ]
        );

        return $grade;
    }

    /**
     * 降级复审判据:成功率骤降 / 差评多 → 需要复审(联动熔断)。
     */
    public function needsReview(int $skillVersionId): bool
    {
        $q = SkillQuality::where('skill_version_id', $skillVersionId)->first();

        if (! $q) {
            return false;
        }

        return $q->install_success_rate < 80 || $q->rating_avg < 3.0;
    }
}
