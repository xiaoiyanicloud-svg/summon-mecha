<?php

namespace Webkul\Shop\Services\SkillCompile;

use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillBuildRun;

/**
 * 技能编译服务 —— 转换层编排 (Work Package C01 / C06)。
 * 协调构建器执行，并在 skill_build_runs 记录每次独立构建 Run 历史，杜绝覆写成功历史。
 */
class SkillBuildService
{
    /**
     * 为若干目标机型编译一个技能版本。
     *
     * @param  array  $targetModelCodes  目标机型 model_code 列表
     * @return array<int, array{model: string, status: string, error: ?string}>
     */
    public function compile(int $skillVersionId, array $manifest, array $targetModelCodes, array $options = []): array
    {
        $kind = (string) ($manifest['kind'] ?? '');
        $builder = $this->resolveBuilder($kind, $manifest);

        if (! $builder) {
            return array_map(fn ($m) => ['model' => $m, 'status' => 'failed', 'error' => "无匹配的构建器(kind={$kind})"], $targetModelCodes);
        }

        $out = [];

        foreach ($targetModelCodes as $modelCode) {
            $result = $builder->build($skillVersionId, $manifest, $modelCode, $options);
            $this->persist($skillVersionId, $modelCode, $result);

            $out[] = ['model' => $modelCode, 'status' => $result->status, 'error' => $result->error];
        }

        return $out;
    }

    /**
     * 按 kind 与 manifest 解析构建器（根据 source_format 优先路由，严格保护旧 manual/legacy 构建器 C-T19）。
     */
    protected function resolveBuilder(string $kind, array $manifest = []): ?CarrierBuilder
    {
        $sourceFormat = $manifest['runtime']['source_format'] ?? null;
        if ($sourceFormat === 'btcpp/xml4') {
            return app(\Webkul\Shop\Services\SkillCompile\Builders\PortableSkillBuilder::class);
        }

        foreach ((array) config('skill_builders.builders', []) as $entry) {
            $class = $entry['class'] ?? null;
            if (! $class || ! class_exists($class)) {
                continue;
            }
            if ($class === \Webkul\Shop\Services\SkillCompile\Builders\PortableSkillBuilder::class) {
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
     * 落库编译记录并持久化 Run 尝试历史 (C01: 不按 version+model 抹除历史日志，失败不覆写成功)。
     */
    protected function persist(int $skillVersionId, string $modelCode, BuildResult $result): SkillBuild
    {
        $buildKey = $result->retargetConf['build_key'] ?? ('bk_' . hash('sha256', "{$skillVersionId}_{$modelCode}"));

        // 1. 记录不可变构建 Run 历史（若构建器内部未记录则补充登记）
        $runExists = false;
        if ($result->artifactAssetId) {
            $runExists = SkillBuildRun::where('build_key', $buildKey)
                ->where('artifact_asset_id', $result->artifactAssetId)
                ->exists();
        }

        if (! $runExists) {
            $runNumber = (int) (SkillBuildRun::where('build_key', $buildKey)->max('run_number') ?? 0) + 1;
            SkillBuildRun::create([
                'skill_version_id'      => $skillVersionId,
                'target_model_code'     => $modelCode,
                'build_key'             => $buildKey,
                'run_number'            => $runNumber,
                'source_digest'         => $result->retargetConf['source_digest'] ?? null,
                'target_profile_digest' => $result->retargetConf['target_profile_digest'] ?? null,
                'status'                => $result->status,
                'carrier_type'          => $result->carrierType,
                'artifact_asset_id'     => $result->artifactAssetId,
                'build_log'             => $result->log,
                'error'                 => $result->error,
            ]);
        }

        // 2. 保护历史成功记录不被失败构建覆盖
        $existing = SkillBuild::where('skill_version_id', $skillVersionId)
            ->where('target_model_code', $modelCode)
            ->first();

        if ($existing && $existing->status === 'success' && $result->status !== 'success') {
            return $existing;
        }

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
        return match (true) {
            str_starts_with($modelCode, 'unitree_') => 'unitree',
            str_starts_with($modelCode, 'agibot_')  => 'agibot',
            str_starts_with($modelCode, 'engineai_') => 'engineai',
            default                                 => null,
        };
    }
}
