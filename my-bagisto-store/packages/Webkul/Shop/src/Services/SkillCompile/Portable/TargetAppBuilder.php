<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;

/**
 * 目标成品打包器 —— 将降阶逻辑、动作轨迹与运行清单打包为不可变交付包 (.skill ZIP) 并登记资产。
 */
class TargetAppBuilder
{
    /**
     * 生成并落盘目标机型交付成品包，登记为 SkillAsset。
     */
    public function package(
        int $skillVersionId,
        array $sourceManifest,
        array $lowered,
        string $buildKey,
        ?string $sourceAssetDir = null
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
                'risk_level'      => 'medium',
                'max_duration_s'  => 30,
                'estop_required'  => true,
            ],
            'runtime'           => [
                'target_model'          => $modelCode,
                'target_profile_digest' => $lowered['meta']['target_profile_digest'] ?? '',
                'build_key'             => $buildKey,
            ],
        ];

        // 2. 构造 artifact.json
        $artifactMeta = [
            'compiler'              => 'zhaohuan.portable-compiler/v1',
            'build_key'             => $buildKey,
            'target_model_code'     => $modelCode,
            'target_profile_digest' => $lowered['meta']['target_profile_digest'] ?? '',
            'built_at'              => now()->toIso8601String(),
        ];

        // 3. 构建临时 ZIP
        $tmpZip = tempnam(sys_get_temp_dir(), 'skill_pkg_');
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("无法创建临时技能归档文件: {$tmpZip}");
        }

        $zip->addFromString('manifest.json', json_encode($targetManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('artifact.json', json_encode($artifactMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString($lowered['entrypoint'], $lowered['action_py']);

        // 写入数据文件（如轨迹 JSON）
        foreach (($lowered['data_files'] ?? []) as $filePath => $content) {
            $zip->addFromString($filePath, $content);
        }

        // 检查并包含音频等素材
        if ($sourceAssetDir && is_dir($sourceAssetDir)) {
            $assetsDir = $sourceAssetDir . DIRECTORY_SEPARATOR . 'assets';
            if (is_dir($assetsDir)) {
                $files = glob($assetsDir . DIRECTORY_SEPARATOR . '*');
                foreach ($files as $af) {
                    if (is_file($af)) {
                        $zip->addFile($af, 'assets/' . basename($af));
                    }
                }
            }
        }

        // 若引用的音频不存在，生成标准 44-byte 极简静音 WAV 占位
        if (! empty($sourceManifest['runtime']['source_entrypoint'])) {
            $dummyWav = pack(
                'A4VA4A4VvvVVvvA4V',
                'RIFF', 36, 'WAVE', 'fmt ', 16, 1, 1, 16000, 32000, 2, 16, 'data', 0
            );
            $zip->addFromString('assets/welcome.wav', $dummyWav);
        }

        $zip->close();

        // 4. 计算哈希与大小
        $sha256 = hash_file('sha256', $tmpZip);
        $size = filesize($tmpZip);

        // 5. 落盘到私有存储盘
        $destFileName = "{$modelCode}_{$version}_{$sha256}.skill";
        $relPath = "skills/{$skillVersionId}/artifacts/{$destFileName}";

        $stream = fopen($tmpZip, 'r');
        Storage::disk($diskName)->put($relPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        @unlink($tmpZip);

        // 6. 登记为 SkillAsset (按已有最大 sort_order 递增，满足 uk_skill_assets_version_carrier)
        $maxSort = (int) (SkillAsset::where('skill_version_id', $skillVersionId)
            ->where('carrier_type', 'file')
            ->max('sort_order') ?? 0);

        return SkillAsset::create([
            'skill_version_id' => $skillVersionId,
            'carrier_type'     => 'file',
            'file_path'        => $relPath,
            'file_name'        => $destFileName,
            'file_size'        => $size,
            'file_hash'        => $sha256,
            'entrypoint'       => 'action.py',
            'sort_order'       => $maxSort + 10,
        ]);
    }
}
