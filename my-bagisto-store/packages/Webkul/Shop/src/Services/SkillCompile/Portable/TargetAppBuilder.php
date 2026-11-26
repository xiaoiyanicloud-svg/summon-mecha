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
        // ⚠ 源素材来自**外部不可信的源包**：合并前必须确认它没有顶替平台生成的文件。
        //   历史缺陷：源包里放一个 artifact.json 就能覆盖产物元数据（build_key / source_digest），
        //   放一个 action.py 就能替换生成的执行脚本 —— 产物 bytes 看着合法，内容已被改写。
        //   名称合法性（穿越/绝对路径/软链）在 SourcePackageReader 已挡；这里挡的是**同名顶替**。
        foreach ($sourceAssets as $assetRelPath => $assetBytes) {
            if (array_key_exists($assetRelPath, $archiveFiles)) {
                throw new SourcePackageRejected(
                    SourcePackageReader::CODE_RESERVED_ENTRY,
                    "源素材 '{$assetRelPath}' 与平台生成的产物文件同名，禁止顶替（产物元数据/执行脚本只能由编译器生成）。"
                );
            }

            $archiveFiles[$assetRelPath] = $assetBytes;
        }

        ksort($archiveFiles);

        // 4. 构建临时确定性 ZIP
        $tmpZip = tempnam(sys_get_temp_dir(), 'skill_pkg_');
        if ($tmpZip === false) {
            throw new \RuntimeException('无法在临时目录创建技能归档文件（临时盘不可写或已满）。');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);

            throw new \RuntimeException("无法创建临时技能归档文件: {$tmpZip}");
        }

        // B5 确定性：条目 mtime 必须能被固定。`ZipArchive::setMtimeName()` 是 PHP 8.3 起才有的；
        // 在更老的运行时上它会**静默退化**成"当前时间"，产物哈希每次都不一样 —— 那不叫构建确定性，
        // 那叫每次都是新产物。故缺方法时直接 fail-closed，而不是悄悄产出不可复现的包。
        if (! method_exists($zip, 'setMtimeName')) {
            $zip->close();
            @unlink($tmpZip);

            throw new \RuntimeException(
                'BUILD_DETERMINISM_UNSUPPORTED: 当前 PHP 运行时缺少 ZipArchive::setMtimeName()（需 PHP >= 8.3），'
                .'无法生成可复现的产物包，拒绝产出。实际版本: '.PHP_VERSION
            );
        }

        foreach ($archiveFiles as $entryName => $content) {
            if ($zip->addFromString($entryName, $content) === false) {
                $zip->close();
                @unlink($tmpZip);

                throw new \RuntimeException("写入产物条目失败: {$entryName}");
            }

            if (! $zip->setMtimeName($entryName, self::DETERMINISTIC_MTIME)) {
                $zip->close();
                @unlink($tmpZip);

                throw new \RuntimeException("无法固定产物条目时间戳（确定性归档前提）: {$entryName}");
            }
        }

        if (! $zip->close()) {
            @unlink($tmpZip);

            throw new \RuntimeException('产物归档收尾失败（磁盘可能已满）。');
        }

        // 5. 计算不可变哈希与大小
        clearstatcache(true, $tmpZip);
        $sha256 = hash_file('sha256', $tmpZip);
        $size = filesize($tmpZip);

        if ($sha256 === false || $size === false || $size <= 0) {
            @unlink($tmpZip);

            throw new \RuntimeException('产物归档落盘不完整（无法计算哈希或大小），拒绝登记资产。');
        }

        // 6. 落盘到私有存储盘，并**回读到盘上校验**。
        //    B4「磁盘满」：`put()` 在磁盘写满/中途失败时可能落到"返回 false"或"只写了一部分"，
        //    两种都必须 fail-closed —— 否则会登记一条 file_hash 是 A、盘上实际是残缺 B 的资产，
        //    交付时才发现买家拿到的是坏包。
        $destFileName = "{$modelCode}_{$version}_{$sha256}.skill";
        $relPath = "skills/{$skillVersionId}/artifacts/{$destFileName}";
        $disk = Storage::disk($diskName);

        $stream = fopen($tmpZip, 'r');
        $written = $disk->put($relPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($written === false) {
            $disk->delete($relPath);
            @unlink($tmpZip);

            throw new \RuntimeException('ARTIFACT_WRITE_FAILED: 产物写入存储盘失败（磁盘可能已满或权限不足），拒绝登记资产。');
        }

        $verify = $this->verifyStoredArtifact($disk, $relPath, $sha256, $size);
        if (! $verify['ok']) {
            $disk->delete($relPath);
            @unlink($tmpZip);

            throw new \RuntimeException('ARTIFACT_WRITE_VERIFY_FAILED: '.$verify['message'].'（拒绝登记资产）');
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

    /**
     * 回读盘上产物，确认"登进去的哈希"与"盘上躺着的字节"是同一份（B4 磁盘满/写截断防线）。
     *
     * 本地盘优先走 `hash_file()`（快且不占内存）；远端盘退回流式哈希。
     * 任何异常都算校验失败 —— 宁可拒绝登记，也不要登记一份没人验过的资产。
     *
     * @return array{ok: bool, message: string}
     */
    protected function verifyStoredArtifact($disk, string $relPath, string $expectedSha256, int $expectedSize): array
    {
        try {
            if (! $disk->exists($relPath)) {
                return ['ok' => false, 'message' => "产物未落到盘上: {$relPath}"];
            }

            $actualSize = $disk->size($relPath);
            if ((int) $actualSize !== $expectedSize) {
                return ['ok' => false, 'message' => "盘上产物大小 {$actualSize} 与预期 {$expectedSize} 不一致"];
            }

            $actualHash = $this->hashStoredFile($disk, $relPath);
            if ($actualHash === null || ! hash_equals($expectedSha256, $actualHash)) {
                return ['ok' => false, 'message' => '盘上产物哈希与生成时哈希不一致（写入被截断或被改写）'];
            }

            return ['ok' => true, 'message' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '回读产物失败: '.$e->getMessage()];
        }
    }

    /**
     * 对存储盘上的文件计算 sha256（本地盘用 path，远端盘用流）。
     */
    protected function hashStoredFile($disk, string $relPath): ?string
    {
        try {
            if (method_exists($disk, 'path')) {
                $localPath = $disk->path($relPath);
                if (is_string($localPath) && is_file($localPath)) {
                    $hash = hash_file('sha256', $localPath);

                    return $hash === false ? null : $hash;
                }
            }
        } catch (\Throwable) {
            // 非本地盘：`path()` 会抛异常，走下面的流式哈希。
        }

        $stream = $disk->readStream($relPath);
        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }
}
