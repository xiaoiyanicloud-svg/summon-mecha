<?php

namespace Webkul\Shop\Services\SkillCompile;

use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillBuildRun;
use Webkul\Shop\Models\SkillVersion;

/**
 * 技能编译服务 —— 转换层编排 (Work Package C01 / C06)。
 * 协调构建器执行，并在 skill_build_runs 记录每次独立构建 Run 历史，杜绝覆写成功历史。
 */
class SkillBuildService
{
    /**
     * 强类型构建入口：先核对源资产、源摘要、目标 Profile 摘要，再进入 builder。
     * 任何前置不一致均返回 failed，不调用外部构建器。
     */
    public function compileWithCommand(BuildCommand $command, array $manifest, array $options = []): BuildResult
    {
        $version = SkillVersion::query()->whereKey($command->skillVersionId)->first();
        if (! $version) {
            return BuildResult::failed('BUILD_SOURCE_VERSION_NOT_FOUND', 'BUILD_SOURCE_VERSION_NOT_FOUND');
        }

        $source = SkillAsset::query()->whereKey($command->sourceAssetId)->first();
        if (! $source
            || (int) $source->skill_version_id !== $command->skillVersionId
            || ! in_array($source->asset_role, [SkillAsset::ROLE_SOURCE, SkillAsset::ROLE_LEGACY], true)) {
            return BuildResult::failed('BUILD_SOURCE_ASSET_MISMATCH', 'BUILD_SOURCE_ASSET_MISMATCH');
        }

        $expectedSourceDigest = 'sha256:' . strtolower((string) $source->file_hash);
        if (! hash_equals($expectedSourceDigest, strtolower($command->sourceDigest))) {
            return BuildResult::failed('BUILD_SOURCE_DIGEST_STALE', 'BUILD_SOURCE_DIGEST_STALE');
        }

        $profiles = app(\Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService::class);
        if (! in_array($command->targetModelCode, \Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService::GA_TARGET_MODELS, true)) {
            return BuildResult::failed('BUILD_TARGET_NOT_GA', 'BUILD_TARGET_NOT_GA');
        }
        if (! hash_equals($profiles->getDigest($command->targetModelCode), $command->targetProfileDigest)) {
            return BuildResult::failed('BUILD_PROFILE_DIGEST_STALE', 'BUILD_PROFILE_DIGEST_STALE');
        }

        $builder = $this->resolveBuilder((string) ($manifest['kind'] ?? ''), $manifest);
        if (! $builder) {
            return BuildResult::failed('BUILD_BUILDER_NOT_FOUND', 'BUILD_BUILDER_NOT_FOUND');
        }

        $result = $builder->build(
            $command->skillVersionId,
            $manifest,
            $command->targetModelCode,
            $options,
        );
        $this->persist($command->skillVersionId, $command->targetModelCode, $result);

        return $result;
    }
    /**
     * 为若干目标机型编译一个技能版本。
     *
     * `error_code` 是构建器给出的**机器码**（如 `BUILD_MOTION_FORMAT_UNSUPPORTED`），
     * 与 `error` 的人话消息分开返回：调用方要能按码分支，而不是去抠字符串。
     *
     * @param  array  $targetModelCodes  目标机型 model_code 列表
     * @return array<int, array{model: string, status: string, error: ?string, error_code: ?string}>
     */
    public function compile(int $skillVersionId, array $manifest, array $targetModelCodes, array $options = []): array
    {
        $kind = (string) ($manifest['kind'] ?? '');
        $builder = $this->resolveBuilder($kind, $manifest);

        if (! $builder) {
            return array_map(fn ($m) => [
                'model'      => $m,
                'status'     => 'failed',
                'error'      => "无匹配的构建器(kind={$kind})",
                'error_code' => 'BUILD_BUILDER_NOT_FOUND',
            ], $targetModelCodes);
        }

        $out = [];

        foreach ($targetModelCodes as $modelCode) {
            $result = $builder->build($skillVersionId, $manifest, $modelCode, $options);
            $this->persist($skillVersionId, $modelCode, $result);

            $out[] = [
                'model'      => $modelCode,
                'status'     => $result->status,
                'error'      => $result->error,
                'error_code' => $result->errorCode,
            ];
        }

        return $out;
    }

    /**
     * 登记型构建的强类型入口（WBS B1.2）。
     *
     * 与 `compile()` 的区别不是"多一层壳"，而是把两类**入口校验**挪进了唯一入口：
     *   ① 目标机型闭集（GA 白名单）—— 不允许拿任意字符串去登记；
     *   ② manifest 摘要与命令一致 —— 防止"校验 A、编译 B"。
     *
     * 结果结构、持久化与 `compile()` 完全一致，调用方无需分叉。
     *
     * @return array<int, array{model:string,status:string,error:?string,error_code:?string}>
     */
    public function registerWithCommand(RegistrationCommand $command, array $manifest, array $options = []): array
    {
        $out = [];
        $targets = [];

        foreach ($command->targetModelCodes as $modelCode) {
            if (! in_array($modelCode, \Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService::GA_TARGET_MODELS, true)) {
                $out[] = [
                    'model'      => $modelCode,
                    'status'     => 'failed',
                    'error'      => '目标机型不在 GA 白名单内（登记型构建也只接受 GA 机型）',
                    'error_code' => 'BUILD_TARGET_NOT_GA',
                ];
                continue;
            }
            $targets[] = $modelCode;
        }

        if ($targets === []) {
            return $out;
        }

        if (! hash_equals($command->manifestDigest, RegistrationCommand::digest($manifest))) {
            foreach ($targets as $modelCode) {
                $out[] = [
                    'model'      => $modelCode,
                    'status'     => 'failed',
                    'error'      => 'manifest 摘要与命令不一致',
                    'error_code' => 'BUILD_MANIFEST_DIGEST_MISMATCH',
                ];
            }

            return $out;
        }

        // 登记语义：以版本 id 为键，不强制该行先存在（D9 夹具与历史调用方依赖这一点）。
        // 版本存在性由提审/发布闸门把关，这里不新增一条会让老调用方 422 的前置。
        $builder = $this->resolveBuilder((string) ($manifest['kind'] ?? ''), $manifest);
        if (! $builder) {
            foreach ($targets as $modelCode) {
                $out[] = [
                    'model'      => $modelCode,
                    'status'     => 'failed',
                    'error'      => '无匹配的构建器(kind='.($manifest['kind'] ?? '').')',
                    'error_code' => 'BUILD_BUILDER_NOT_FOUND',
                ];
            }

            return $out;
        }

        foreach ($targets as $modelCode) {
            $result = $builder->build($command->skillVersionId, $manifest, $modelCode, $options);
            $this->persist($command->skillVersionId, $modelCode, $result);

            $out[] = [
                'model'      => $modelCode,
                'status'     => $result->status,
                'error'      => $result->error,
                'error_code' => $result->errorCode,
            ];
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
            // B2：序号只能由 RunNumberAllocator 在事务内分配，禁止 max()+1 无锁。
            app(RunNumberAllocator::class)->createRun($buildKey, [
                'skill_version_id'      => $skillVersionId,
                'target_model_code'     => $modelCode,
                'source_digest'         => $result->retargetConf['source_digest'] ?? null,
                'target_profile_digest' => $result->retargetConf['target_profile_digest'] ?? null,
                'status'                => $result->status,
                'carrier_type'          => $result->carrierType,
                'artifact_asset_id'     => $result->artifactAssetId,
                'build_log'             => $result->log,
                'error'                 => $result->error,
                // B5.5：每次 run 记录工具链指纹（含"无独立镜像 → NOT_RUN"的明示）
                'toolchain_json'        => ToolchainFingerprint::current(),
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
