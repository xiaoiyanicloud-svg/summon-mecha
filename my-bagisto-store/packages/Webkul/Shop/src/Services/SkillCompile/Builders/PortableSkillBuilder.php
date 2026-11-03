<?php

namespace Webkul\Shop\Services\SkillCompile\Builders;

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\CarrierBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\CapabilityResolver;
use Webkul\Shop\Services\SkillCompile\Portable\PortableSourceValidator;
use Webkul\Shop\Services\SkillCompile\Portable\SemanticLowerer;
use Webkul\Shop\Services\SkillCompile\Portable\SourceParser;
use Webkul\Shop\Services\SkillCompile\Portable\TargetAppBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

/**
 * 标准技能可移植编译器（第二阶段核心） —— 将 BehaviorTree XML4 标准源编译为多机型交付成品。
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

    public function build(int $skillVersionId, array $manifest, string $targetModelCode): BuildResult
    {
        $runtime = $manifest['runtime'] ?? [];
        $sourceFormat = $runtime['source_format'] ?? null;

        // 若非标准 btcpp/xml4 格式，返回不受支持（交由其他构建器或报告）
        if ($sourceFormat !== 'btcpp/xml4') {
            return BuildResult::failed("PortableSkillBuilder 仅支持 runtime.source_format='btcpp/xml4'，当前为: " . ($sourceFormat ?: 'null'));
        }

        // 1. 清单校验
        $manifestErrors = $this->validator->validateManifest($manifest);
        if (! empty($manifestErrors)) {
            return BuildResult::failed("标准清单校验失败: " . implode('; ', $manifestErrors));
        }

        // 2. 获取源 XML 内容
        $xmlContent = $this->resolveSourceXml($skillVersionId, $manifest);
        if ($xmlContent === null) {
            return BuildResult::failed("未找到入口行为树 XML 文件: " . ($runtime['source_entrypoint'] ?? ''));
        }

        // 3. XML 语法与结构校验
        $xmlErrors = $this->validator->validateXml($xmlContent);
        if (! empty($xmlErrors)) {
            return BuildResult::failed("行为树 XML 校验失败: " . implode('; ', $xmlErrors));
        }

        // 4. 解析为 IR
        try {
            $ir = $this->parser->parse($xmlContent);
        } catch (\Exception $e) {
            return BuildResult::failed("IR 解析异常: " . $e->getMessage());
        }

        // 5. 目标机型能力仲裁
        $resolution = $this->resolver->resolve($ir, $targetModelCode);
        if (! $resolution['compatible']) {
            return BuildResult::failed("机型 {$targetModelCode} 能力不匹配: " . implode('; ', $resolution['reasons']));
        }

        // 6. 计算唯一构建身份 build_key
        $buildIdentity = [
            'skill_version_id'      => $skillVersionId,
            'source_digest'         => $ir['ir_digest'],
            'target_model_code'     => $targetModelCode,
            'target_profile_digest' => $resolution['target_profile_digest'],
            'compiler'              => 'zhaohuan.portable-compiler/v1',
        ];
        $canonicalJson = app(SigningService::class)->jcsCanonicalize($buildIdentity);
        $buildKey = 'bk_' . hash('sha256', $canonicalJson);

        // 7. 语义降阶与动作转码
        try {
            $lowered = $this->lowerer->lower($ir, $targetModelCode);
        } catch (\Exception $e) {
            return BuildResult::failed("语义降阶失败: " . $e->getMessage());
        }

        // 8. 目标成品打包落盘并登记资产
        try {
            $artifactAsset = $this->appBuilder->package(
                skillVersionId: $skillVersionId,
                sourceManifest: $manifest,
                lowered: $lowered,
                buildKey: $buildKey
            );
        } catch (\Exception $e) {
            return BuildResult::failed("目标成品打包失败: " . $e->getMessage());
        }

        $retargetConf = [
            'build_key'             => $buildKey,
            'source_format'         => 'btcpp/xml4',
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
     * 读取源 XML 内容（优先从已上传资产解压包或示例目录读取）。
     */
    protected function resolveSourceXml(int $skillVersionId, array $manifest): ?string
    {
        $entrypoint = $manifest['runtime']['source_entrypoint'] ?? 'behavior/main.xml';

        // 1. 尝试从 skill_assets 中已有的源包查找
        $sourceAsset = SkillAsset::where('skill_version_id', $skillVersionId)
            ->where('carrier_type', 'file')
            ->orderBy('id')
            ->first();

        if ($sourceAsset && $sourceAsset->file_path) {
            $disk = Storage::disk(SkillAsset::disk());
            if ($disk->exists($sourceAsset->file_path)) {
                $fullPath = $disk->path($sourceAsset->file_path);
                if (file_exists($fullPath) && class_exists(\ZipArchive::class)) {
                    $zip = new \ZipArchive();
                    if ($zip->open($fullPath) === true) {
                        $content = $zip->getFromName($entrypoint);
                        $zip->close();
                        if ($content !== false && $content !== '') {
                            return $content;
                        }
                    }
                }
            }
        }

        // 2. 尝试从 docs/portable-skill-spec/examples 示例中读取
        $specExample = base_path('../docs/portable-skill-spec/examples/greeting/' . $entrypoint);
        if (file_exists($specExample)) {
            return file_get_contents($specExample);
        }

        // 3. 相对路径兜底
        $relPath = base_path($entrypoint);
        if (file_exists($relPath)) {
            return file_get_contents($relPath);
        }

        return null;
    }
}
