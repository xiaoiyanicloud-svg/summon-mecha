<?php

namespace Webkul\Shop\Services\SkillCompile\Builders;

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuildRun;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\CarrierBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\CapabilityResolver;
use Webkul\Shop\Services\SkillCompile\Portable\MotionTranscoder;
use Webkul\Shop\Services\SkillCompile\Portable\PortableSourceValidator;
use Webkul\Shop\Services\SkillCompile\Portable\SemanticLowerer;
use Webkul\Shop\Services\SkillCompile\Portable\SourceParser;
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
        protected TargetProfileService $profileService
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
        $sourceInfo = $this->resolveSourcePackage($skillVersionId, $manifest);
        if ($sourceInfo === null) {
            return BuildResult::failed("未找到已登记的标准技能源包 (C-T01: 严禁从 docs 示例或服务器路径兜底)");
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
        } catch (\Exception $e) {
            return BuildResult::failed("目标成品打包失败: " . $e->getMessage());
        }

        // 11. 登记独立目标发布快照 (C01 / C06)
        SkillTargetRelease::updateOrCreate(
            ['skill_version_id' => $skillVersionId, 'target_model_code' => $targetModelCode],
            [
                'artifact_asset_id' => $artifactAsset->id,
                'build_key'         => $buildKey,
                'review_status'     => SkillTargetRelease::STATUS_PENDING,
            ]
        );

        // 12. 记录构建 Run 历史 (C01)
        $runNumber = (int) (SkillBuildRun::where('build_key', $buildKey)->max('run_number') ?? 0) + 1;
        SkillBuildRun::create([
            'skill_version_id'      => $skillVersionId,
            'target_model_code'     => $targetModelCode,
            'build_key'             => $buildKey,
            'run_number'            => $runNumber,
            'source_digest'         => $ir['ir_digest'],
            'target_profile_digest' => $resolution['target_profile_digest'],
            'status'                => 'success',
            'carrier_type'          => 'file',
            'artifact_asset_id'     => $artifactAsset->id,
            'build_log'             => "成功编译 {$targetModelCode} 目标成品",
        ]);

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
            log: "成功编译 {$targetModelCode} 目标成品，生成资产 ID {$artifactAsset->id} (SHA-256: {$artifactAsset->file_hash})"
        );
    }

    /**
     * 严格从已登记的 source 资产 ZIP 包内读取入口 XML 与素材 (C-T01: 杜绝外部/示例兜底)。
     *
     * @return array{source_asset: SkillAsset, xml_content: string, assets: array<string, string>}|null
     */
    protected function resolveSourcePackage(int $skillVersionId, array $manifest): ?array
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
            return null;
        }

        $disk = Storage::disk(SkillAsset::disk());
        if (! $disk->exists($sourceAsset->file_path)) {
            return null;
        }

        $fullPath = $disk->path($sourceAsset->file_path);
        if (! file_exists($fullPath) || ! class_exists(\ZipArchive::class)) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($fullPath) !== true) {
            return null;
        }

        $xmlContent = $zip->getFromName($entrypoint);
        if ($xmlContent === false || $xmlContent === '') {
            $zip->close();
            return null;
        }

        // 读取所有附加素材文件（过滤目录、入口 XML 及清单）
        $assets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            if (str_ends_with($name, '/') || $name === $entrypoint || $name === 'manifest.json') {
                continue;
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes !== false) {
                $assets[$name] = $bytes;
            }
        }

        $zip->close();

        return [
            'source_asset' => $sourceAsset,
            'xml_content'  => $xmlContent,
            'assets'       => $assets,
        ];
    }
}
