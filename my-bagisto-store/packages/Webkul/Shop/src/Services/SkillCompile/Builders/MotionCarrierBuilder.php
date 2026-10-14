<?php

namespace Webkul\Shop\Services\SkillCompile\Builders;

use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\CarrierBuilder;

/**
 * 动作类载体构建器 —— v3.4 步骤 6。
 *
 * 中立动作(kind=motion) → 各机型关节空间,靠**动作重定向**。
 *
 * ⚠ 诚实边界:真正的重定向算法(把 BVH/SMPL-X 映射到某机型关节)是**独立工具/服务**,
 *   本构建器负责的是**编排与记录**:登记"该技能需要为哪些机型重定向""重定向配置是什么"。
 *   实际产物(artifact)由重定向服务产出后回填 —— 故 status=pending 直到产物就绪。
 *   这不是偷懒,是不把算法硬塞进平台层(保持架构健康)。
 */
class MotionCarrierBuilder implements CarrierBuilder
{
    public function supports(string $kind): bool
    {
        return in_array($kind, ['motion', 'composite'], true);
    }

    public function build(int $skillVersionId, array $manifest, string $targetModelCode): BuildResult
    {
        $motion = $manifest['motion'] ?? [];

        if (empty($motion)) {
            return BuildResult::failed('kind=motion 但缺少 motion 段(format/fps),无法重定向。');
        }

        // 记录该机型需要的重定向配置(真正映射由重定向工具消费)
        $retargetConf = [
            'source_format'   => $motion['format'] ?? null,
            'fps'             => $motion['fps'] ?? null,
            'target_model'    => $targetModelCode,
            'retarget_engine' => 'external', // 重定向工具标识,产物回填
            'note'            => '待重定向工具产出各机型动作文件后回填 artifact。',
        ];

        return BuildResult::pending(
            carrierType: 'motion_data',
            retargetConf: $retargetConf,
            log: "已登记 {$targetModelCode} 的动作重定向任务。",
        );
    }
}
