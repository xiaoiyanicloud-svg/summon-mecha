<?php

namespace Webkul\Shop\Services\SkillCompile;

use Webkul\Shop\Models\SkillBuild;

/**
 * 技能编译服务 —— 转换层编排 —— v3.4 步骤 6(计划 §4.20)。
 *
 * 把一个"中立技能版本"编译成"若干目标机型的载体":
 *   中立定义 + 目标机型 → CarrierBuilder → skill_build 记录(+ 产物回填)
 *
 * 一个技能版本 → N 条 skill_build(每目标机型一条)。
 *
 * ⚠ 口径更正(2026-10-16 · D-9):`skill_build` 记的是**编排意图/登记**,不是已经产出的载体。
 *   真正把中立动作映射到各机型关节的产物(`artifact_asset_id` → skill_assets.id),
 *   需**外部重定向工具产出或人工回填** —— **平台侧当前没有生产者**。因此受支持的
 *   kind(motion/composite)经本服务构建后 status 恒为 `pending`,**不是**"编译完成"。
 *   "传一次、多机型可下"是**目标**,当前并未成立:本表也**不在交付链路上**
 *   (唯一读点是 internal.key 的回读接口 `SkillSupplyController::builds()`)。
 *
 * 构建器可插拔(见 config/skill_builders.php),本服务只做编排 + 落库。
 */
class SkillBuildService
{
    /**
     * 为若干目标机型编译一个技能版本。
     *
     * @param  array  $targetModelCodes  目标机型 model_code 列表
     * @return array<int, array{model: string, status: string, error: ?string}>
     */
    public function compile(int $skillVersionId, array $manifest, array $targetModelCodes): array
    {
        $kind = (string) ($manifest['kind'] ?? '');
        $builder = $this->resolveBuilder($kind);

        if (! $builder) {
            return array_map(fn ($m) => ['model' => $m, 'status' => 'failed', 'error' => "无匹配的构建器(kind={$kind})"], $targetModelCodes);
        }

        $out = [];

        foreach ($targetModelCodes as $modelCode) {
            $result = $builder->build($skillVersionId, $manifest, $modelCode);
            $this->persist($skillVersionId, $modelCode, $result);

            $out[] = ['model' => $modelCode, 'status' => $result->status, 'error' => $result->error];
        }

        return $out;
    }

    /**
     * 按 kind 解析构建器(配置注册表 + 容器)。
     */
    protected function resolveBuilder(string $kind): ?CarrierBuilder
    {
        foreach ((array) config('skill_builders.builders', []) as $entry) {
            $class = $entry['class'] ?? null;
            if (! $class || ! class_exists($class)) {
                continue;
            }
            $builder = app($class);
            if ($builder instanceof CarrierBuilder && $builder->supports($kind)) {
                return $builder;
            }
        }

        return null;
    }

    /**
     * 落库一条编译记录(按 version+model 幂等,updateOrCreate)。
     */
    protected function persist(int $skillVersionId, string $modelCode, BuildResult $result): SkillBuild
    {
        return SkillBuild::updateOrCreate(
            ['skill_version_id' => $skillVersionId, 'target_model_code' => $modelCode],
            [
                'target_agent_type' => $this->agentTypeFor($modelCode),
                'status'            => $result->status,
                'carrier_type'      => $result->carrierType,
                'artifact_asset_id' => $result->artifactAssetId,
                'retarget_conf'     => $result->retargetConf,
                'build_log'         => $result->log,
                'error'             => $result->error,
                'built_at'          => $result->status === 'success' ? now() : null,
            ]
        );
    }

    /**
     * 从 model_code 前缀推断厂商(unitree_/agibot_/engineai_)。
     */
    protected function agentTypeFor(string $modelCode): ?string
    {
        foreach (['unitree', 'agibot', 'engineai'] as $v) {
            if (str_starts_with($modelCode, $v)) {
                return $v;
            }
        }

        return null;
    }
}
