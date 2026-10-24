<?php

namespace Webkul\Shop\Services\SkillPackage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

/**
 * 技能包自动封装器（供给侧闭环核心）。
 *
 * 解决开发者门户“上传原始动作/脚本/参数文件或普通压缩包后因缺少 manifest.json 而无法提审”的断路：
 *   1. 若上传文件已是含 manifest.json/yaml 的标准 .skill/ZIP 包，原样保留；
 *   2. 若开发者开启自动封装（auto_pack）或上传的是原始载体文件（.bvh/.npz/.csv/.py/.sh/.json/.onnx 等）
 *      或不含 manifest.json 的普通 .zip，自动按版本元数据合成合法的 `zhaohuan.skill/v2` 清单、
 *      安装说明 README.md 与载荷文件，产出标准 `.skill` 归档包；
 *   3. 支持一键生成标准示例 `.skill` 包供开发者下载参考或快速联调。
 */
class SkillPackageBuilder
{
    /** 可自动封装为标准 .skill 包的原始文件扩展名。 */
    public const RAW_PACKABLE_EXT = [
        'bvh', 'npz', 'csv', 'fbx', 'npy',
        'py', 'sh', 'bash', 'js',
        'json', 'yaml', 'yml', 'onnx', 'urdf', 'xml', 'txt', 'md',
        'zip',
    ];

    public const SUPPORTED_KINDS = [
        SkillManifest::KIND_MOTION,
        SkillManifest::KIND_APP,
        SkillManifest::KIND_COMPOSITE,
    ];

    public const SUPPORTED_RISK_LEVELS = ['low', 'medium', 'high'];

    public const SUPPORTED_MOTION_FORMATS = ['bvh', 'npz', 'csv', 'fbx', 'npy'];

    /**
     * 判断上传的文件是否已是含 manifest 清单的 ZIP/.skill 归档。
     */
    public function hasValidArchiveManifest(string $filePath): bool
    {
        if (! is_file($filePath) || filesize($filePath) < 4) {
            return false;
        }

        // 检查 ZIP 魔数 PK\x03\x04
        $fh = @fopen($filePath, 'rb');
        if (! $fh) {
            return false;
        }
        $magic = fread($fh, 4);
        fclose($fh);

        if ($magic !== "PK\x03\x04") {
            return false;
        }

        $zip = new \ZipArchive;
        if ($zip->open($filePath) !== true) {
            return false;
        }

        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && in_array(basename($name), PackageInspector::MANIFEST_FILES, true)) {
                $found = true;
                break;
            }
        }

        $zip->close();

        return $found;
    }

    /**
     * 判断是否需要对上传文件执行自动 .skill 封装。
     */
    public function shouldAutoPack(UploadedFile $file, bool $explicitAutoPack = false): bool
    {
        $realPath = $file->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            return false;
        }

        // 已经是包含 manifest 的合法 .skill / .zip 包 → 无需二次封装
        if ($this->hasValidArchiveManifest($realPath)) {
            return false;
        }

        if ($explicitAutoPack) {
            return true;
        }

        $ext = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        // 扩展名为 .skill 且未显式要求 auto_pack 时不篡改原字节（保留测试中伪造坏 .skill 包的断言语义）
        if ($ext === 'skill') {
            return false;
        }

        return in_array($ext, self::RAW_PACKABLE_EXT, true);
    }

    /**
     * 将开发者上传的原始文件（或无 manifest 的普通 zip）封装为符合 zhaohuan.skill/v2 规范的 .skill 包。
     *
     * @param  array<string, mixed>  $options
     */
    public function buildFromUploadedFile(SkillVersion $version, UploadedFile $rawFile, array $options = []): UploadedFile
    {
        $origName = basename($rawFile->getClientOriginalName());
        $safeOrigName = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', $origName) ?: 'payload.bin';
        $ext = strtolower((string) pathinfo($safeOrigName, PATHINFO_EXTENSION));
        $baseStem = pathinfo($safeOrigName, PATHINFO_FILENAME) ?: 'skill-package';

        $kind = $this->resolveKind($version, $ext, $options);
        $entrypoint = $this->resolveEntrypoint($kind, $safeOrigName, $ext, $options);

        $manifest = $this->buildManifestArray($version, $kind, $entrypoint, $ext, $options);

        $tmpZipPath = tempnam(sys_get_temp_dir(), 'zhskill_').'.skill';
        $zip = new \ZipArchive;
        if ($zip->open($tmpZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建临时 .skill 归档文件。');
        }

        $zip->addFromString(
            'manifest.json',
            (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $readme = trim((string) ($options['install_guide'] ?? $version->install_guide ?? ''));
        if ($readme === '') {
            $skillName = $manifest['name']['zh_CN'] ?? '机器人技能';
            $readme = "# {$skillName} (v{$manifest['version']})\n\n"
                ."- 类型：`{$kind}`\n"
                ."- 入口：`{$entrypoint}`\n"
                ."- 交付通道：`".implode(', ', $manifest['delivery_channels'])."`\n";
        }
        $zip->addFromString('README.md', $readme);

        // 若原文件本身是一个普通 ZIP（只是缺 manifest.json），将其内部文件合并进 .skill
        $rawRealPath = $rawFile->getRealPath();
        $copiedFromZip = false;
        if ($ext === 'zip' && $rawRealPath && is_file($rawRealPath)) {
            $srcZip = new \ZipArchive;
            if ($srcZip->open($rawRealPath) === true) {
                for ($i = 0; $i < $srcZip->numFiles; $i++) {
                    $entryName = $srcZip->getNameIndex($i);
                    if ($entryName === false || str_ends_with($entryName, '/')) {
                        continue;
                    }
                    if (in_array(basename($entryName), ['manifest.json', 'manifest.yaml', 'manifest.yml', 'README.md'], true)) {
                        continue;
                    }
                    $entryBytes = $srcZip->getFromIndex($i);
                    if ($entryBytes !== false) {
                        $zip->addFromString($entryName, $entryBytes);
                        $copiedFromZip = true;
                    }
                }
                $srcZip->close();
            }
        }

        if (! $copiedFromZip && $rawRealPath && is_file($rawRealPath)) {
            $zip->addFile($rawRealPath, $entrypoint);
        }

        $zip->close();

        $outName = str_ends_with(strtolower($baseStem), '.skill') ? $baseStem : "{$baseStem}.skill";

        return new UploadedFile($tmpZipPath, $outName, 'application/zip', null, true);
    }

    /**
     * 生成标准示例 .skill 包（供开发者下载模板或一键生成演示交付物）。
     *
     * @param  array<string, mixed>  $options
     */
    public function buildSamplePackage(SkillVersion $version, array $options = []): UploadedFile
    {
        $kind = (string) ($options['kind'] ?? ($version->manifest['kind'] ?? SkillManifest::KIND_MOTION));
        if (! in_array($kind, self::SUPPORTED_KINDS, true)) {
            $kind = SkillManifest::KIND_MOTION;
        }

        if ($kind === SkillManifest::KIND_APP) {
            $entrypoint = 'payload/main.py';
            $ext = 'py';
            $payloadContent = "#!/usr/bin/env python3\n"
                ."# -*- coding: utf-8 -*-\n"
                ."\"\"\"召唤机甲标准应用类技能入口示例\"\"\"\n"
                ."import json\nimport sys\n\n"
                ."def main():\n"
                ."    print(json.dumps({\"ok\": True, \"skill_version\": \"{$version->version}\", \"status\": \"ready\"}))\n\n"
                ."if __name__ == \"__main__\":\n"
                ."    main()\n";
        } else {
            $entrypoint = 'payload/motion_clip.bvh';
            $ext = 'bvh';
            $payloadContent = "HIERARCHY\nROOT Hips\n{\n  OFFSET 0.00 0.00 0.00\n  CHANNELS 6 Xposition Yposition Zposition Zrotation Xrotation Yrotation\n  End Site\n  {\n    OFFSET 0.00 10.00 0.00\n  }\n}\nMOTION\nFrames: 2\nFrame Time: 0.033333\n0.00 0.00 0.00 0.00 0.00 0.00\n0.00 0.10 0.00 1.50 0.00 0.00\n";
        }

        $manifest = $this->buildManifestArray($version, $kind, $entrypoint, $ext, $options);

        $tmpZipPath = tempnam(sys_get_temp_dir(), 'zhsample_').'.skill';
        $zip = new \ZipArchive;
        if ($zip->open($tmpZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建示例 .skill 归档文件。');
        }

        $zip->addFromString(
            'manifest.json',
            (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $skillName = $manifest['name']['zh_CN'] ?? '示例技能';
        $readme = "# {$skillName} (v{$manifest['version']})\n\n"
            ."本包由开发者门户自动生成，符合 `zhaohuan.skill/v2` 标准规范。\n\n"
            ."## 目录结构\n"
            ."- `manifest.json`：技能标准清单\n"
            ."- `{$entrypoint}`：技能载荷文件\n"
            ."- `README.md`：安装与运行说明\n";

        $zip->addFromString('README.md', $readme);
        $zip->addFromString($entrypoint, $payloadContent);
        $zip->close();

        $fileName = "skill-{$version->product_id}-v{$version->version}.skill";

        return new UploadedFile($tmpZipPath, $fileName, 'application/zip', null, true);
    }

    /**
     * 组装符合 ManifestValidator 校验的 zhaohuan.skill/v2 数组。
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildManifestArray(
        SkillVersion $version,
        string $kind,
        string $entrypoint,
        string $ext,
        array $options = []
    ): array {
        $existingManifest = is_array($version->manifest) ? $version->manifest : [];

        $skillName = trim((string) ($options['name'] ?? ($existingManifest['name']['zh_CN'] ?? '')));
        if ($skillName === '') {
            $skillName = (string) (DB::table('product_flat')
                ->where('product_id', $version->product_id)
                ->whereNotNull('name')
                ->value('name') ?: "机器人技能 #{$version->product_id}");
        }

        $deliveryChannels = $options['delivery_channels']
            ?? $version->delivery_channels
            ?? ($existingManifest['delivery_channels'] ?? [SkillAsset::DELIVERY_AGENT, SkillAsset::DELIVERY_MANUAL]);

        if (is_string($deliveryChannels)) {
            $deliveryChannels = array_values(array_filter(array_map('trim', explode(',', $deliveryChannels))));
        }
        if (! is_array($deliveryChannels) || $deliveryChannels === []) {
            $deliveryChannels = [SkillAsset::DELIVERY_AGENT, SkillAsset::DELIVERY_MANUAL];
        }

        $riskLevel = (string) ($options['risk_level']
            ?? $version->risk_level
            ?? ($existingManifest['safety']['risk_level'] ?? 'low'));
        if (! in_array($riskLevel, self::SUPPORTED_RISK_LEVELS, true)) {
            $riskLevel = 'low';
        }

        $targetModels = $options['target_models'] ?? null;
        if (is_string($targetModels)) {
            $targetModels = array_values(array_filter(array_map('trim', explode(',', $targetModels))));
        }

        $compatibility = is_array($version->compatibility) ? $version->compatibility : ($existingManifest['compatibility'] ?? []);
        if (is_array($targetModels) && $targetModels !== []) {
            $compatibility['models'] = array_map(fn (string $code) => $this->normalizeCompatibilityEntry($code), $targetModels);
        } elseif (empty($compatibility['models']) || ! is_array($compatibility['models'])) {
            $compatibility['models'] = [
                $this->normalizeCompatibilityEntry('unitree_g1'),
                $this->normalizeCompatibilityEntry('agibot_a2'),
                $this->normalizeCompatibilityEntry('engineai_pm01'),
            ];
        } else {
            $compatibility['models'] = array_map(
                fn ($entry) => is_string($entry) ? $this->normalizeCompatibilityEntry($entry) : $this->normalizeCompatibilityArray((array) $entry),
                $compatibility['models']
            );
        }

        $permissions = $options['permissions'] ?? $version->permissions ?? ($existingManifest['permissions'] ?? ['motion_control']);
        if (is_string($permissions)) {
            $permissions = array_values(array_filter(array_map('trim', explode(',', $permissions))));
        }
        if (! is_array($permissions)) {
            $permissions = ['motion_control'];
        }
        $permissions = array_values(array_intersect($permissions, SkillManifest::PERMISSION_WHITELIST));
        if ($permissions === []) {
            $permissions = ['motion_control'];
        }

        $resourceType = (string) ($options['resource_type'] ?? ($existingManifest['resource_type'] ?? 'skill'));
        if (! in_array($resourceType, SkillManifest::RESOURCE_TYPES, true)) {
            $resourceType = 'skill';
        }

        $nameEn = (string) ($options['name_en'] ?? ($existingManifest['name']['en'] ?? $existingManifest['name']['en_US'] ?? $skillName));

        $manifest = [
            'schema'            => SkillManifest::SCHEMA,
            'kind'              => $kind,
            'name'              => [
                'zh_CN' => $skillName,
                'en'    => $nameEn,
                'en_US' => $nameEn,
            ],
            'version'           => preg_match('/^\d+\.\d+\.\d+/', (string) $version->version) ? $version->version : '1.0.0',
            'description'       => (string) ($options['description'] ?? $version->changelog ?? ($existingManifest['description'] ?? "{$skillName} 技能交付包")),
            'delivery_channels' => array_values(array_unique($deliveryChannels)),
            'resource_type'     => $resourceType,
            'pricing_mode'      => (string) ($options['pricing_mode'] ?? ($existingManifest['pricing_mode'] ?? 'buyout')),
            'entrypoint'        => $entrypoint,
            'safety'            => [
                'risk_level'         => $riskLevel,
                'requires_open_area' => (bool) ($options['requires_open_area'] ?? ($existingManifest['safety']['requires_open_area'] ?? true)),
                'estop_required'     => (bool) ($options['estop_required'] ?? ($existingManifest['safety']['estop_required'] ?? true)),
                'max_duration_s'     => (int) ($options['max_duration_s'] ?? ($existingManifest['safety']['max_duration_s'] ?? 60)),
            ],
            'compatibility'     => $compatibility,
            'permissions'       => $permissions,
        ];

        if ($kind === SkillManifest::KIND_APP) {
            $manifest['runtime'] = [
                'entrypoint' => $entrypoint,
            ];
        }

        if ($kind === SkillManifest::KIND_MOTION) {
            $motionFormat = in_array($ext, self::SUPPORTED_MOTION_FORMATS, true)
                ? $ext
                : (string) ($options['motion_format'] ?? ($existingManifest['motion']['format'] ?? 'bvh'));
            if (! in_array($motionFormat, self::SUPPORTED_MOTION_FORMATS, true)) {
                $motionFormat = 'bvh';
            }

            $manifest['motion'] = [
                'format' => $motionFormat,
                'fps'    => max(1, (int) ($options['motion_fps'] ?? ($existingManifest['motion']['fps'] ?? 30))),
                'file'   => $entrypoint,
            ];
        }

        return $manifest;
    }

    /**
     * 将 model_code（如 unitree_g1）规范化为符合 ManifestValidator::validateCompatibility 的 {vendor, model, model_code}。
     *
     * @return array{vendor: string, model: string, model_code: string}
     */
    protected function normalizeCompatibilityEntry(string $code): array
    {
        $code = trim($code) ?: 'unitree_g1';
        if (str_contains($code, '_')) {
            [$vendor, $model] = explode('_', $code, 2);
            $vendor = trim($vendor) ?: 'generic';
            $model = trim($model) ?: $code;
        } else {
            $vendor = 'generic';
            $model = $code;
        }

        return [
            'vendor'     => $vendor,
            'model'      => $model,
            'model_code' => $code,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function normalizeCompatibilityArray(array $entry): array
    {
        $vendor = isset($entry['vendor']) && is_string($entry['vendor']) ? trim($entry['vendor']) : '';
        $model = isset($entry['model']) && is_string($entry['model']) ? trim($entry['model']) : '';
        $code = isset($entry['model_code']) && is_string($entry['model_code']) ? trim($entry['model_code']) : '';

        if ($vendor !== '' && $model !== '') {
            if ($code === '') {
                $entry['model_code'] = "{$vendor}_{$model}";
            }

            return $entry;
        }

        return array_merge($entry, $this->normalizeCompatibilityEntry($code !== '' ? $code : ($model !== '' ? $model : 'unitree_g1')));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function resolveKind(SkillVersion $version, string $ext, array $options): string
    {
        $explicit = (string) ($options['kind'] ?? ($version->manifest['kind'] ?? ''));
        if (in_array($explicit, self::SUPPORTED_KINDS, true)) {
            return $explicit;
        }

        $carrier = (string) ($options['carrier_type'] ?? '');
        if ($carrier === SkillAsset::CARRIER_APP_PACKAGE || in_array($ext, ['py', 'sh', 'bash', 'js'], true)) {
            return SkillManifest::KIND_APP;
        }

        if ($carrier === SkillAsset::CARRIER_MOTION_DATA || in_array($ext, ['bvh', 'npz', 'csv', 'fbx', 'npy'], true)) {
            return SkillManifest::KIND_MOTION;
        }

        if (in_array($ext, ['json', 'yaml', 'yml', 'onnx', 'urdf'], true)) {
            return SkillManifest::KIND_COMPOSITE;
        }

        return SkillManifest::KIND_MOTION;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function resolveEntrypoint(string $kind, string $safeOrigName, string $ext, array $options): string
    {
        $explicit = trim((string) ($options['entrypoint'] ?? ''), '/\\');
        if ($explicit !== '') {
            return str_replace('\\', '/', $explicit);
        }

        if ($ext === 'zip') {
            return $kind === SkillManifest::KIND_APP ? 'main.py' : 'payload/motion.bvh';
        }

        return 'payload/'.$safeOrigName;
    }
}
