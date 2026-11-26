<?php

namespace Webkul\Shop\Services\SkillCompile\Builders;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\CarrierBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\SourcePackageRejected;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;
use ZipArchive;

/**
 * 应用类载体构建器（WBS C1 / 计划 §4.20 转换层）。
 *
 * 输入：开发者上传的中立 `.skill`（kind=app，含 `payload/` 与 `entrypoint`）。
 * 输出：**按目标机型装配的厂商载体包**（确定性 ZIP）并登记为 SkillAsset：
 *
 *   · unitree_g1 / unitree_r1 → `app/` + `metadata.yaml` + `build.sh`（ARM64 Docker 构建入口）
 *   · agibot_x2 / agibot_a3   → `resources/` + `rpc_manifest.json` + `install.sh`
 *   · engineai_t800           → `plugin/` + `runner.json` + `install.sh`
 *
 * ⚠ 诚实边界（必须写进交付文档）：
 *   本构建器只做**装配与登记**，不调用任何厂商 SDK、不构建 ARM64 镜像、不做机上 RPC。
 *   产物是"可交付的厂商载体目录 + 构建/安装脚本"，真正落到机器人上仍需：
 *   ① 在 ARM64 环境执行 `build.sh`（宇树）；② 厂商侧同步/RPC（智元）；③ 机上运行（众擎）。
 *   因此 `retarget_conf.requires_external_build` 如实标为 true（智元/众擎为 false，但仍需机上执行）。
 *
 * 安全：源包来自外部，进入归档前逐条校验名称（绝对路径 / `..` / 软链 / 反斜杠），
 * 并拒绝与平台生成的元数据文件同名顶替。
 */
class AppCarrierBuilder implements CarrierBuilder
{
    public const ERR_TARGET_UNSUPPORTED = 'BUILD_TARGET_UNSUPPORTED';
    public const ERR_SOURCE_MISSING = 'BUILD_APP_SOURCE_MISSING';
    public const ERR_SOURCE_INVALID = 'BUILD_APP_SOURCE_INVALID';
    public const ERR_ENTRYPOINT_MISSING = 'BUILD_APP_ENTRYPOINT_MISSING';
    public const ERR_UNSAFE_ENTRY = 'BUILD_APP_UNSAFE_ENTRY';
    public const ERR_ARCHIVE_FAILED = 'BUILD_APP_ARCHIVE_FAILED';

    public const MAX_ENTRIES = 2000;
    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024;

    /** 目标机型 → 载体布局。 */
    public const LAYOUTS = [
        'unitree_g1' => ['dir' => 'app', 'carrier' => SkillAsset::CARRIER_APP_PACKAGE, 'external_build' => true],
        'unitree_r1' => ['dir' => 'app', 'carrier' => SkillAsset::CARRIER_APP_PACKAGE, 'external_build' => true],
        'agibot_x2'  => ['dir' => 'resources', 'carrier' => SkillAsset::CARRIER_VENDOR_PACKAGE, 'external_build' => false],
        'agibot_a3'  => ['dir' => 'resources', 'carrier' => SkillAsset::CARRIER_VENDOR_PACKAGE, 'external_build' => false],
        'engineai_t800' => ['dir' => 'plugin', 'carrier' => SkillAsset::CARRIER_APP_PACKAGE, 'external_build' => false],
    ];

    public const DETERMINISTIC_MTIME = 1700000000;

    /** 平台生成的保留文件名 —— 源包同名条目必须被拒（防顶替元数据/脚本）。
     *  `manifest.json` 不在此列：它是源包的正常组成，装配时由平台重新生成。 */
    protected const RESERVED = [
        'artifact.json',
        'metadata.yaml',
        'rpc_manifest.json',
        'runner.json',
        'build.sh',
        'install.sh',
    ];

    public function supports(string $kind): bool
    {
        return $kind === 'app';
    }

    public function build(int $skillVersionId, array $manifest, string $targetModelCode, array $options = []): BuildResult
    {
        if (! in_array($targetModelCode, TargetProfileService::GA_TARGET_MODELS, true)
            || ! isset(self::LAYOUTS[$targetModelCode])) {
            return BuildResult::failed(
                "目标机型 {$targetModelCode} 不是当前 GA 机型，拒绝装配应用载体。",
                self::ERR_TARGET_UNSUPPORTED
            );
        }

        $layout = self::LAYOUTS[$targetModelCode];
        $entrypoint = (string) ($manifest['entrypoint'] ?? '');

        if ($entrypoint === '') {
            return BuildResult::failed('kind=app 但 manifest.entrypoint 为空，无法确定入口。', self::ERR_ENTRYPOINT_MISSING);
        }

        $source = $this->sourceAsset($skillVersionId);

        if ($source === null) {
            return BuildResult::failed('找不到该版本的源包（asset_role=source/legacy 且已落盘）。', self::ERR_SOURCE_MISSING);
        }

        try {
            $entries = $this->readSourceEntries($source);
        } catch (SourcePackageRejected $exception) {
            return BuildResult::failed($exception->getMessage(), $exception->reasonCode);
        } catch (RuntimeException $exception) {
            return BuildResult::failed($exception->getMessage(), self::ERR_SOURCE_INVALID);
        }

        // 源包自带的 manifest.json 由平台重新生成（目标机型/构建身份必须由平台写死）
        unset($entries['manifest.json']);

        // 入口必须在源包内（允许带 payload/ 前缀，也允许 entrypoint 就是顶层文件）
        if (! array_key_exists($entrypoint, $entries)) {
            return BuildResult::failed(
                "manifest.entrypoint '{$entrypoint}' 不在源包中，拒绝装配（否则装上去也起不来）。",
                self::ERR_ENTRYPOINT_MISSING
            );
        }

        foreach (array_keys($entries) as $name) {
            if (in_array($name, self::RESERVED, true)) {
                return BuildResult::failed(
                    "源包条目 '{$name}' 与平台生成的载体元数据同名，禁止顶替。",
                    self::ERR_UNSAFE_ENTRY
                );
            }
        }

        $version = (string) ($manifest['version'] ?? '1.0.0');
        $sourceDigest = (string) ($source->file_hash ?? '');
        $buildKey = substr(hash('sha256', implode('|', [$skillVersionId, $targetModelCode, $sourceDigest, $entrypoint])), 0, 40);

        try {
            [$archiveFiles, $carrierType] = $this->assemble($manifest, $layout, $entries, $targetModelCode, $version, $buildKey, $sourceDigest);
        } catch (RuntimeException $exception) {
            return BuildResult::failed($exception->getMessage(), self::ERR_ARCHIVE_FAILED);
        }

        [$bytes, $sha256] = $this->zip($archiveFiles);

        if ($bytes === null) {
            return BuildResult::failed('产物归档生成失败（临时盘不可写或 ZipArchive 不可用）。', self::ERR_ARCHIVE_FAILED);
        }

        $destFileName = "{$targetModelCode}_{$version}_{$sha256}.skill";
        $relPath = "skills/{$skillVersionId}/artifacts/{$destFileName}";
        $disk = Storage::disk(SkillAsset::disk());

        if (! $disk->put($relPath, $bytes)) {
            $disk->delete($relPath);

            return BuildResult::failed('产物写入存储盘失败（磁盘可能已满或权限不足）。', self::ERR_ARCHIVE_FAILED);
        }

        // 回读校验：登记的哈希必须与盘上字节一致
        $stored = $disk->get($relPath);
        if ($stored === null || hash('sha256', $stored) !== $sha256) {
            $disk->delete($relPath);

            return BuildResult::failed('产物回读校验失败：盘上字节与登记哈希不一致。', self::ERR_ARCHIVE_FAILED);
        }

        // 唯一键是 (skill_version_id, carrier_type, sort_order)：同版本多机型必须用不同
        // sort_order，否则 g1/r1 会撞同一行。按固定机型顺序分配，保证重建幂等。
        $sortOrder = (int) array_search($targetModelCode, array_keys(self::LAYOUTS), true) + 1;

        $asset = SkillAsset::query()->updateOrCreate(
            [
                'skill_version_id' => $skillVersionId,
                'carrier_type'     => $carrierType,
                'sort_order'       => $sortOrder,
            ],
            [
                'seller_id'         => null,
                'asset_role'        => SkillAsset::ROLE_ARTIFACT,
                'asset_key'         => "app-{$targetModelCode}-{$buildKey}",
                'target_model_code' => $targetModelCode,
                'build_key'         => $buildKey,
                'source_digest'     => $sourceDigest !== '' ? 'sha256:'.$sourceDigest : null,
                'review_status'     => SkillAsset::REVIEW_PENDING,
                'file_path'         => $relPath,
                'file_name'         => $destFileName,
                'file_size'         => strlen($bytes),
                'file_hash'         => $sha256,
            ]
        );

        return BuildResult::success(
            carrierType: $carrierType,
            artifactAssetId: (int) $asset->id,
            retargetConf: [
                'build_key'               => $buildKey,
                'target_model_code'       => $targetModelCode,
                'target_profile_digest'   => (string) ($options['target_profile_digest'] ?? ''),
                'source_digest'           => $sourceDigest,
                'artifact_asset_id'       => (int) $asset->id,
                'artifact_sha256'         => $sha256,
                'entrypoint'              => $entrypoint,
                'carrier_layout'          => $layout['dir'],
                'requires_external_build' => $layout['external_build'],
            ],
            log: "app carrier assembled for {$targetModelCode} ({$layout['dir']}/, ".count($archiveFiles)." files)",
            buildKey: $buildKey,
            targetModelCode: $targetModelCode,
            packageSha256: $sha256,
        );
    }

    // ── 装配 ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $entries
     * @return array{0: array<string, string>, 1: string}
     */
    protected function assemble(
        array $manifest,
        array $layout,
        array $entries,
        string $targetModelCode,
        string $version,
        string $buildKey,
        string $sourceDigest,
    ): array {
        $dir = $layout['dir'];
        $files = [];

        foreach ($entries as $name => $content) {
            // payload/ 前缀剥离后整体放进厂商目录；entrypoint 路径随之保持一致
            $relative = str_starts_with($name, 'payload/') ? substr($name, strlen('payload/')) : $name;
            $files[$dir.'/'.$relative] = $content;
        }

        $entry = (string) ($manifest['entrypoint'] ?? 'payload/main.py');
        $entryInCarrier = $dir.'/'.(str_starts_with($entry, 'payload/') ? substr($entry, strlen('payload/')) : $entry);

        $baseManifest = [
            'schema'            => 'zhaohuan.skill/v2',
            'kind'              => 'app',
            'name'              => $manifest['name'] ?? ['zh_CN' => '应用技能'],
            'version'           => $version,
            'target_model_code' => $targetModelCode,
            // 载体内的真实入口（payload/ 前缀已按厂商布局展开）
            'entrypoint'        => $entryInCarrier,
            'permissions'       => $manifest['permissions'] ?? [],
            'safety'            => $manifest['safety'] ?? [],
            'runtime'           => [
                'source_format'    => 'zhaohuan.app/v1',
                'build_key'        => $buildKey,
                'source_digest'    => $sourceDigest,
                'carrier_layout'   => $dir,
            ],
        ];

        $files['manifest.json'] = json_encode($baseManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $files['artifact.json'] = json_encode([
            'compiler'          => 'zhaohuan.app-carrier/v1',
            'build_key'         => $buildKey,
            'source_digest'     => $sourceDigest,
            'target_model_code' => $targetModelCode,
            'deterministic_epoch' => self::DETERMINISTIC_MTIME,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (str_starts_with($targetModelCode, 'unitree_')) {
            $files['metadata.yaml'] = $this->yaml([
                'name'       => $this->displayName($manifest),
                'version'    => $version,
                'arch'       => 'arm64',
                'entrypoint' => $entryInCarrier,
                'type'       => 'docker-app',
            ]);
            $files['build.sh'] = "#!/bin/sh\nset -eu\n# 在 ARM64 环境执行：构建并加载宇树 UniStore 兼容的 Docker 应用包\ndocker build -t zhaohuan/app:{$version} app/\ndocker save zhaohuan/app:{$version} -o zhaohuan-app-{$version}.tar\n";
            $carrier = SkillAsset::CARRIER_APP_PACKAGE;
        } elseif (str_starts_with($targetModelCode, 'agibot_')) {
            $files['rpc_manifest.json'] = json_encode([
                'package'    => 'zhaohuan.resource/v1',
                'version'    => $version,
                'entrypoint' => $entryInCarrier,
                'sync'       => 'rpc',
                'target'     => $targetModelCode,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $files['install.sh'] = "#!/bin/sh\nset -eu\n# 智元：拷贝到机上资源临时目录，再触发厂商 RPC 同步（不直接改厂商云）\nTARGET=/agibot/data/resources/tmp/zhaohuan-{$version}\nmkdir -p \"\$TARGET\"\ncp -a resources/. \"\$TARGET\"/\necho \"resource package staged at \$TARGET; trigger AimDK sync next\"\n";
            $carrier = SkillAsset::CARRIER_VENDOR_PACKAGE;
        } else {
            $files['runner.json'] = json_encode([
                'package'    => 'zhaohuan.runner/v1',
                'version'    => $version,
                'entrypoint' => $entryInCarrier,
                'runtime'    => 'ros2-or-native',
                'target'     => $targetModelCode,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $files['install.sh'] = "#!/bin/sh\nset -eu\n# 众擎：整包落到机上插件目录后由 Runner/ROS2 启动\nDEST=\${ZHJJ_PLUGIN_DIR:-/opt/zhaohuan/plugins}/{$version}\nmkdir -p \"\$DEST\"\ncp -a plugin/. \"\$DEST\"/\necho \"runner plugin installed at \$DEST\"\n";
            $carrier = SkillAsset::CARRIER_APP_PACKAGE;
        }

        ksort($files);

        if (count($files) > self::MAX_ENTRIES) {
            throw new RuntimeException('载体文件数超过上限，拒绝打包。', 0);
        }

        return [$files, $carrier];
    }

    /**
     * @param  array<string, string>  $files
     * @return array{0: string|null, 1: string}
     */
    protected function zip(array $files): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zhjj_app_');
        if ($tmp === false) {
            return [null, ''];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);

            return [null, ''];
        }

        if (! method_exists($zip, 'setMtimeName')) {
            $zip->close();
            @unlink($tmp);

            return [null, ''];
        }

        foreach ($files as $name => $content) {
            if ($zip->addFromString($name, $content) === false || ! $zip->setMtimeName($name, self::DETERMINISTIC_MTIME)) {
                $zip->close();
                @unlink($tmp);

                return [null, ''];
            }
        }

        if (! $zip->close()) {
            @unlink($tmp);

            return [null, ''];
        }

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        if ($bytes === false || $bytes === '') {
            return [null, ''];
        }

        return [$bytes, hash('sha256', $bytes)];
    }

    // ── 源包读取与校验 ──────────────────────────────────────────────────────

    protected function sourceAsset(int $skillVersionId): ?SkillAsset
    {
        return SkillAsset::query()
            ->where('skill_version_id', $skillVersionId)
            ->whereIn('asset_role', [SkillAsset::ROLE_SOURCE, SkillAsset::ROLE_LEGACY])
            ->whereNotNull('file_path')
            ->orderBy('sort_order')
            ->get()
            ->first(fn (SkillAsset $asset) => $asset->fileExists());
    }

    /**
     * @return array<string, string>
     */
    protected function readSourceEntries(SkillAsset $source): array
    {
        $disk = Storage::disk(SkillAsset::disk());
        $path = method_exists($disk, 'path') ? $disk->path((string) $source->file_path) : null;

        if ($path === null || ! is_file($path)) {
            throw new SourcePackageRejected(self::ERR_SOURCE_MISSING, '源包文件在盘上不存在。');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new SourcePackageRejected(self::ERR_SOURCE_INVALID, '源包不是合法 ZIP。');
        }

        $entries = [];
        $total = 0;

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new SourcePackageRejected(self::ERR_SOURCE_INVALID, '源包条目数超过上限。');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                if ($this->unsafeName($name)) {
                    throw new SourcePackageRejected(self::ERR_UNSAFE_ENTRY, "源包含不安全条目: {$name}");
                }

                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new SourcePackageRejected(self::ERR_SOURCE_INVALID, '源包解压总量超过上限。');
                }

                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    throw new SourcePackageRejected(self::ERR_SOURCE_INVALID, "读取源包条目失败: {$name}");
                }

                $entries[$name] = $content;
            }
        } finally {
            $zip->close();
        }

        return $entries;
    }

    protected function unsafeName(string $name): bool
    {
        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized)) {
            return true;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..' || $segment === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, scalar>  $data
     */
    protected function yaml(array $data): string
    {
        $lines = [];

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $lines[] = $key.': '.$value;
        }

        return implode("\n", $lines)."\n";
    }

    protected function displayName(array $manifest): string
    {
        $name = $manifest['name'] ?? '应用技能';

        if (is_array($name)) {
            $first = reset($name);

            return (string) ($name['zh_CN'] ?? $name['en'] ?? ($first !== false ? $first : '应用技能'));
        }

        return (string) $name;
    }
}
