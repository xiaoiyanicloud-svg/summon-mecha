<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;

/**
 * 目标成品打包器 (Work Package C01 / C05 / C06)。
 * 将降阶逻辑、真实 BT Runner、能力 Broker、计划与素材规范化打包为不可变交付包 (.skill ZIP) 并登记资产。
 * 严格落实确定性归档（固定 mtime 与排序），杜绝随机时间戳破坏双环境重建可复现性 (C-T17)。
 */
class TargetAppBuilder
{
    public const DETERMINISTIC_MTIME = 1700000000;

    /**
     * 生成并落盘目标机型交付成品包，登记为 SkillAsset。
     *
     * @param int $skillVersionId
     * @param array $sourceManifest
     * @param array $lowered
     * @param string $buildKey
     * @param array<string, string> $sourceAssets 真实素材字典 [相对路径 => 字节流]
     * @param string|null $sourceDigest 中立源摘要
     * @return SkillAsset
     */
    public function package(
        int $skillVersionId,
        array $sourceManifest,
        array $lowered,
        string $buildKey,
        array $sourceAssets = [],
        ?string $sourceDigest = null
    ): SkillAsset {
        $modelCode = $lowered['meta']['target_model_code'] ?? 'generic';
        $version = $sourceManifest['version'] ?? '1.0.0';
        $diskName = SkillAsset::disk();

        // 1. 构造 target manifest.json
        $targetManifest = [
            'schema'            => 'zhaohuan.skill/v2',
            'kind'              => $sourceManifest['kind'] ?? 'composite',
            'name'              => $sourceManifest['name'] ?? ['zh_CN' => '编译动作技能'],
            'version'           => $version,
            'description'       => $sourceManifest['description'] ?? '',
            'target_model_code' => $modelCode,
            'entrypoint'        => $lowered['entrypoint'] ?? 'action.py',
            'permissions'       => $sourceManifest['permissions'] ?? ['motion_control'],
            'safety'            => $sourceManifest['safety'] ?? [
                'risk_level'     => 'medium',
                'max_duration_s' => 30,
                'estop_required' => true,
            ],
            'runtime'           => [
                'source_format'         => 'btcpp/xml4',
                'target_model'          => $modelCode,
                'target_profile_digest' => $lowered['meta']['target_profile_digest'] ?? '',
                'build_key'             => $buildKey,
                'source_digest'         => $sourceDigest ?? '',
            ],
        ];

        // 2. 构造 artifact.json (固定 epoch 杜绝当前时间漂移)
        $artifactMeta = [
            'compiler'              => 'zhaohuan.portable-compiler/v1',
            'build_key'             => $buildKey,
            'source_digest'         => $sourceDigest ?? '',
            'target_model_code'     => $modelCode,
            'target_profile_digest' => $lowered['meta']['target_profile_digest'] ?? '',
            'deterministic_epoch'   => self::DETERMINISTIC_MTIME,
        ];

        // 3. 收集所有打包文件并按键名字典序排序 (保证归档字节绝对确定性)
        $archiveFiles = [];
        $archiveFiles['manifest.json'] = json_encode($targetManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $archiveFiles['artifact.json'] = json_encode($artifactMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $archiveFiles[$lowered['entrypoint']] = $lowered['action_py'];

        foreach (($lowered['data_files'] ?? []) as $filePath => $content) {
            $archiveFiles[$filePath] = $content;
        }

        // 包含真实源素材 (C-T02 严禁自动生成占位静音 WAV 顶替)
        foreach ($sourceAssets as $assetRelPath => $assetBytes) {
            $archiveFiles[$assetRelPath] = $assetBytes;
        }

        ksort($archiveFiles);

        // 4. 构建临时确定性 ZIP
        $tmpZip = tempnam(sys_get_temp_dir(), 'skill_pkg_');
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("无法创建临时技能归档文件: {$tmpZip}");
        }

        foreach ($archiveFiles as $entryName => $content) {
            $zip->addFromString($entryName, $content);
            if (method_exists($zip, 'setMtimeName')) {
                $zip->setMtimeName($entryName, self::DETERMINISTIC_MTIME);
            }
        }

        $zip->close();

        // 5. 计算不可变哈希与大小
        $sha256 = hash_file('sha256', $tmpZip);
        $size = filesize($tmpZip);

        // 6. 落盘到私有存储盘
        $destFileName = "{$modelCode}_{$version}_{$sha256}.skill";
        $relPath = "skills/{$skillVersionId}/artifacts/{$destFileName}";

        $stream = fopen($tmpZip, 'r');
        Storage::disk($diskName)->put($relPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        @unlink($tmpZip);

        // 7. 登记为 SkillAsset
        $maxSort = (int) (SkillAsset::where('skill_version_id', $skillVersionId)
            ->where('carrier_type', 'file')
            ->max('sort_order') ?? 0);

        return SkillAsset::create([
            'skill_version_id'  => $skillVersionId,
            'carrier_type'      => 'file',
            'asset_role'        => SkillAsset::ROLE_ARTIFACT,
            'asset_key'         => 'art_' . substr($sha256, 0, 16),
            'target_model_code' => $modelCode,
            'build_key'         => $buildKey,
            'source_digest'     => $sourceDigest,
            'review_status'     => SkillAsset::REVIEW_PENDING,
            'file_path'         => $relPath,
            'file_name'         => $destFileName,
            'file_size'         => $size,
            'file_hash'         => $sha256,
            'entrypoint'        => 'action.py',
            'sort_order'        => $maxSort + 10,
        ]);
    }
}
