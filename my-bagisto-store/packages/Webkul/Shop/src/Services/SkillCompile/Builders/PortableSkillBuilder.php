<?php

namespace Webkul\Shop\Services\SkillCompile\Builders;

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuildRun;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Models\SkillTargetReleaseEvent;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\CarrierBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\CapabilityResolver;
use Webkul\Shop\Services\SkillCompile\Portable\MotionTranscoder;
use Webkul\Shop\Services\SkillCompile\Portable\PortableSourceValidator;
use Webkul\Shop\Services\SkillCompile\Portable\SemanticLowerer;
use Webkul\Shop\Services\SkillCompile\Portable\SourceParser;
use Webkul\Shop\Services\SkillCompile\Portable\SourcePackageReader;
use Webkul\Shop\Services\SkillCompile\Portable\SourcePackageRejected;
use Webkul\Shop\Services\SkillCompile\Portable\TargetAppBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

/**
 * 标准技能可移植编译器（第二阶段核心） (Work Packages C01 ~ C06)。
 * 将 BehaviorTree XML4 标准源编译为多机型交付成品，杜绝路径回退与假转码。
 */
class PortableSkillBuilder implements CarrierBuilder
{
    public function __construct(
        protected PortableSourceValidator $validator,
        protected SourceParser $parser,
        protected CapabilityResolver $resolver,
        protected SemanticLowerer $lowerer,
        protected TargetAppBuilder $appBuilder,
        protected TargetProfileService $profileService,
        protected SourcePackageReader $sourceReader,
    ) {}

    public function supports(string $kind): bool
    {
        return in_array($kind, ['composite', 'motion'], true);
    }

    public function build(int $skillVersionId, array $manifest, string $targetModelCode, array $options = []): BuildResult
    {
        $runtime = $manifest['runtime'] ?? [];
        $sourceFormat = $runtime['source_format'] ?? null;

        // 若非标准 btcpp/xml4 格式，返回不受支持（交由其他构建器）
        if ($sourceFormat !== 'btcpp/xml4') {
            return BuildResult::failed("PortableSkillBuilder 仅支持 runtime.source_format='btcpp/xml4'，当前为: " . ($sourceFormat ?: 'null'));
        }

        // 1. 清单格式校验 (C02)
        $manifestErrors = $this->validator->validateManifest($manifest);
        if (! empty($manifestErrors)) {
            return BuildResult::failed("标准清单校验失败: " . implode('; ', $manifestErrors));
        }

        // 2. 严格从数据库源包资产解析源 XML 与素材 (C-T01: 严禁从 docs 示例或相对路径兜底)
        //    源包是外部不可信输入 —— 读取与安全预算统一在 SourcePackageReader 里，
        //    被拒时**把拒绝码如实带回**，不用一句"未找到源包"把原因吞掉。
        $sourceInfo = $this->resolveSourcePackage($skillVersionId, $manifest);
        if (isset($sourceInfo['failure'])) {
            return $sourceInfo['failure'];
        }
        if (! isset($sourceInfo['xml_content'])) {
            return BuildResult::failed(
                '未找到已登记的标准技能源包 (C-T01: 严禁从 docs 示例或服务器路径兜底)',
                'BUILD_SOURCE_ASSET_NOT_FOUND'
            );
        }

        $xmlContent = $sourceInfo['xml_content'];
        $sourceAssets = $sourceInfo['assets'];
        $sourceAsset = $sourceInfo['source_asset'];

        // 3. XML 语法、安全边界与受限控制流校验 (C02 / C-T03 / C-T04)
        $xmlErrors = $this->validator->validateXml($xmlContent);
        if (! empty($xmlErrors)) {
            return BuildResult::failed("行为树 XML 校验失败: " . implode('; ', $xmlErrors));
        }

        // 4. 解析为 IR (以 main_tree_to_execute 为准隔离孤立未引用树 C-T05)
        try {
            $ir = $this->parser->parse($xmlContent);
        } catch (\Exception $e) {
            return BuildResult::failed("IR 解析异常: " . $e->getMessage());
        }

        // 5. 清单与行为树语义交叉校验 (权限不足与时长预算 C-T08)
        $crossErrors = $this->validator->validateCrossSemantics($manifest, $ir);
        if (! empty($crossErrors)) {
            return BuildResult::failed("语义一致性校验失败: " . implode('; ', $crossErrors));
        }

        // 6. 验证源包引用的素材真实存在且非空 (C-T02 严禁静音/占位 wav 顶替)
        foreach ($ir['required_assets'] as $reqAsset) {
            if (! isset($sourceAssets[$reqAsset]) || strlen($sourceAssets[$reqAsset]) === 0) {
                return BuildResult::failed("源引用素材缺失或损坏 (C-T02): 缺少素材 '{$reqAsset}'，严禁生成静音/占位文件顶替");
            }
        }

        // 7. 目标机型能力仲裁 (C03 / C04 / C-T06 / C-T07)
        $resolution = $this->resolver->resolve($ir, $targetModelCode);
        if (! $resolution['compatible']) {
            return BuildResult::failed("机型 {$targetModelCode} 能力不匹配: " . implode('; ', $resolution['reasons']));
        }

        // 8. 计算唯一构建身份 build_key (规范化 JCS，仅依赖中立源摘要、版本号与目标 Profile，环境无关 C-T17)
        $buildIdentity = [
            'source_digest'         => $ir['ir_digest'],
            'version'               => $manifest['version'] ?? '1.0.0',
            'target_model_code'     => $targetModelCode,
            'target_profile_digest' => $resolution['target_profile_digest'],
            'compiler'              => 'zhaohuan.portable-compiler/v1',
        ];
        $canonicalJson = app(SigningService::class)->jcsCanonicalize($buildIdentity);
        $buildKey = 'bk_' . hash('sha256', $canonicalJson);

        // 9. 语义降阶与受限代码/计划生成 (C03 / C05)
        try {
            $lowered = $this->lowerer->lower($ir, $targetModelCode);
        } catch (\Exception $e) {
            return BuildResult::failed("语义降阶失败: " . $e->getMessage());
        }

        // 10. 目标成品规范化打包落盘并登记资产 (C01 / C06 / C-T17)
        try {
            $artifactAsset = $this->appBuilder->package(
                skillVersionId: $skillVersionId,
                sourceManifest: $manifest,
                lowered: $lowered,
                buildKey: $buildKey,
                sourceAssets: $sourceAssets,
                sourceDigest: $ir['ir_digest']
            );
        } catch (SourcePackageRejected $rejected) {
            return BuildResult::failed(
                "目标成品打包被拒 [{$rejected->reasonCode}]: {$rejected->getMessage()}",
                $rejected->reasonCode
            );
        } catch (\Exception $e) {
            return BuildResult::failed("目标成品打包失败: " . $e->getMessage());
        }

        // 11. 登记独立目标发布快照。只有构建身份变化才重新进入待审；
        // 同一 build_key 的重建不得把已审核状态静默重置。
        $release = SkillTargetRelease::firstOrNew([
            'skill_version_id' => $skillVersionId,
            'target_model_code' => $targetModelCode,
        ]);
        if (! $release->exists || $release->build_key !== $buildKey) {
            $release->review_status = SkillTargetRelease::STATUS_PENDING;
            $release->reviewed_at = null;
            $release->reviewer_id = null;
            $release->rejection_reason = null;
        }
        $release->artifact_asset_id = $artifactAsset->id;
        $release->build_key = $buildKey;
        $release->target_profile_digest = $resolution['target_profile_digest'];
        $release->source_digest = $ir['ir_digest'];
        $release->save();

        SkillTargetReleaseEvent::create([
            'skill_version_id' => $skillVersionId,
            'target_model_code' => $targetModelCode,
            'artifact_asset_id' => $artifactAsset->id,
            'build_key' => $buildKey,
            'target_profile_digest' => $resolution['target_profile_digest'],
            'event_type' => 'build_succeeded',
            'actor_id' => null,
            'reason' => null,
            'created_at' => now(),
        ]);

        // 12. 记录构建 Run 历史。唯一键裁决并发，不能用 max()+1 假幂等。
        $runNumber = $this->reserveBuildRun(
            skillVersionId: $skillVersionId,
            targetModelCode: $targetModelCode,
            buildKey: $buildKey,
            sourceDigest: $ir['ir_digest'],
            targetProfileDigest: $resolution['target_profile_digest'],
            artifactAssetId: $artifactAsset->id,
        );

        $retargetConf = [
            'build_key'             => $buildKey,
            'source_format'         => 'btcpp/xml4',
            'source_digest'         => $ir['ir_digest'],
            'target_model_code'     => $targetModelCode,
            'target_profile_digest' => $resolution['target_profile_digest'],
            'artifact_asset_id'     => $artifactAsset->id,
            'artifact_sha256'       => $artifactAsset->file_hash,
            'actions_count'         => count($ir['actions']),
        ];

        return BuildResult::success(
            carrierType: 'file',
            artifactAssetId: $artifactAsset->id,
            retargetConf: $retargetConf,
            log: "成功编译 {$targetModelCode} 目标成品，生成资产 ID {$artifactAsset->id} (SHA-256: {$artifactAsset->file_hash})",
            buildKey: $buildKey,
            runNumber: $runNumber,
            targetModelCode: $targetModelCode,
            packageSha256: $artifactAsset->file_hash,
            targetProfileDigest: $resolution['target_profile_digest'],
        );
    }

    protected function reserveBuildRun(
        int $skillVersionId,
        string $targetModelCode,
        string $buildKey,
        string $sourceDigest,
        string $targetProfileDigest,
        int $artifactAssetId,
    ): int {
        // B2：序号只由 RunNumberAllocator 分配（行锁 + 有界重试），禁止 max()+1。
        $run = app(\Webkul\Shop\Services\SkillCompile\RunNumberAllocator::class)->createRun($buildKey, [
            'skill_version_id'      => $skillVersionId,
            'target_model_code'     => $targetModelCode,
            'source_digest'         => $sourceDigest,
            'target_profile_digest' => $targetProfileDigest,
            'status'                => 'success',
            'carrier_type'          => 'file',
            'artifact_asset_id'     => $artifactAssetId,
            'build_log'             => "成功编译 {$targetModelCode} 目标成品",
        ]);

        return (int) $run->run_number;
    }
    /**
     * 严格从已登记的 source 资产 ZIP 包内读取入口 XML 与素材 (C-T01: 杜绝外部/示例兜底)。
     *
     * 安全校验（ZIP Slip / 软链 / 炸弹 / 加密 / 保留名）全部委托 `SourcePackageReader`，
     * 本方法只负责"找到是哪个资产、盘上有没有这个文件"，以及把拒绝码包成 BuildResult。
     *
     * @return array{
     *     failure?: BuildResult,
     *     source_asset?: SkillAsset,
     *     xml_content?: string,
     *     assets?: array<string, string>
     * }
     */
    protected function resolveSourcePackage(int $skillVersionId, array $manifest): array
    {
        $entrypoint = $manifest['runtime']['source_entrypoint'] ?? 'behavior/main.xml';

        // 查找属于该版本的源包资产 (source 角色，或 legacy 首个文件包)
        $sourceAsset = SkillAsset::where('skill_version_id', $skillVersionId)
            ->where('carrier_type', 'file')
            ->where(function ($q) {
                $q->where('asset_role', SkillAsset::ROLE_SOURCE)
                  ->orWhere(function ($q2) {
                      $q2->where('asset_role', SkillAsset::ROLE_LEGACY)
                         ->orWhereNull('asset_role');
                  });
            })
            ->where('asset_role', '!=', SkillAsset::ROLE_ARTIFACT)
            ->orderBy('id')
            ->first();

        if (! $sourceAsset || ! $sourceAsset->file_path) {
            return [];
        }

        $disk = Storage::disk(SkillAsset::disk());
        if (! $disk->exists($sourceAsset->file_path)) {
            return [];
        }

        $fullPath = $disk->path($sourceAsset->file_path);
        if (! file_exists($fullPath)) {
            return [];
        }

        try {
            $package = $this->sourceReader->read($fullPath, (string) $entrypoint);
        } catch (SourcePackageRejected $rejected) {
            return [
                'failure' => BuildResult::failed(
                    "源包安全校验未通过 [{$rejected->reasonCode}]: {$rejected->getMessage()}",
                    $rejected->reasonCode
                ),
            ];
        }

        return [
            'source_asset' => $sourceAsset,
            'xml_content'  => $package['xml_content'],
            'assets'       => $package['assets'],
        ];
    }
}
