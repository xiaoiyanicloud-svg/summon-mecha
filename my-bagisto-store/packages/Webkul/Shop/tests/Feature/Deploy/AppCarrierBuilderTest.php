<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Builders\AppCarrierBuilder;

/**
 * C1 · 应用类载体装配（宇树 Docker / 智元资源包 / 众擎 Runner）。
 *
 * 诚实边界：本构建器只做**装配与登记**，不调用厂商 SDK、不构建 ARM64 镜像。
 * 用例断言的是"产物结构与安全拒绝"，不是"能上真机"。
 */

function c1Zip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'c1src').'.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();
    $bytes = file_get_contents($path);
    @unlink($path);

    return (string) $bytes;
}

function c1Version(int $productId = 920): SkillVersion
{
    return SkillVersion::create([
        'product_id'        => $productId,
        'seller_id'         => null,
        'version'           => '1.0.'.random_int(100, 999),
        'channel'           => SkillVersion::CHANNEL_STABLE,
        'delivery_channels' => ['agent', 'manual'],
        'risk_level'        => 'low',
        'status'            => SkillVersion::STATUS_DRAFT,
        'license_mode'      => SkillVersion::LICENSE_PER_DEVICE,
        'pricing_mode'      => 'buyout',
    ]);
}

function c1SourceAsset(SkillVersion $version, string $bytes): SkillAsset
{
    Storage::disk(SkillAsset::disk())->put('c1/source.skill', $bytes);

    return SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => null,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'asset_role'       => SkillAsset::ROLE_SOURCE,
        'asset_key'        => 'c1-source',
        'file_path'        => 'c1/source.skill',
        'file_name'        => 'source.skill',
        'file_size'        => strlen($bytes),
        'file_hash'        => hash('sha256', $bytes),
        'sort_order'       => 1,
    ]);
}

function c1Manifest(array $overrides = []): array
{
    return array_merge([
        'kind'       => 'app',
        'version'    => '1.0.0',
        'name'       => ['zh_CN' => '迎宾应用'],
        'entrypoint' => 'payload/main.py',
    ], $overrides);
}

function c1ArtifactEntries(SkillAsset $asset): array
{
    $bytes = Storage::disk(SkillAsset::disk())->get($asset->file_path);
    $tmp = tempnam(sys_get_temp_dir(), 'c1art').'.zip';
    file_put_contents($tmp, $bytes);

    $zip = new ZipArchive();
    $zip->open($tmp);

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($tmp);

    sort($names);

    return $names;
}

beforeEach(function () {
    Storage::fake(SkillAsset::disk());
});

it('C1-A-01 五个 GA 机型都能装配出对应布局，且登记为可选交付资产', function (string $model, string $dir, string $carrier) {
    $version = c1Version();
    c1SourceAsset($version, c1Zip([
        'manifest.json'   => json_encode(c1Manifest()),
        'payload/main.py' => "print('hello')\n",
        'payload/assets/a.txt' => 'asset',
    ]));

    $result = (new AppCarrierBuilder())->build($version->id, c1Manifest(), $model);

    expect($result->status)->toBe('success', $result->error ?? '')
        ->and($result->carrierType)->toBe($carrier)
        ->and($result->artifactAssetId)->toBeInt()
        ->and($result->packageSha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and($result->retargetConf['carrier_layout'])->toBe($dir);

    $asset = SkillAsset::findOrFail($result->artifactAssetId);
    expect($asset->fileExists())->toBeTrue()
        ->and($asset->target_model_code)->toBe($model)
        ->and($asset->carrier_type)->toBe($carrier)
        ->and($asset->review_status)->toBe(SkillAsset::REVIEW_PENDING);

    $entries = c1ArtifactEntries($asset);
    expect($entries)->toContain("{$dir}/main.py", "{$dir}/assets/a.txt", 'manifest.json', 'artifact.json');

    $expected = match ($model) {
        'unitree_g1', 'unitree_r1' => ['metadata.yaml', 'build.sh'],
        'agibot_x2', 'agibot_a3' => ['rpc_manifest.json', 'install.sh'],
        default => ['runner.json', 'install.sh'],
    };
    foreach ($expected as $file) {
        expect($entries)->toContain($file);
    }
})->with([
    ['unitree_g1', 'app', SkillAsset::CARRIER_APP_PACKAGE],
    ['unitree_r1', 'app', SkillAsset::CARRIER_APP_PACKAGE],
    ['agibot_x2', 'resources', SkillAsset::CARRIER_VENDOR_PACKAGE],
    ['agibot_a3', 'resources', SkillAsset::CARRIER_VENDOR_PACKAGE],
    ['engineai_t800', 'plugin', SkillAsset::CARRIER_APP_PACKAGE],
]);

it('C1-A-02 源包含路径穿越 → 安全拒绝，不产出资产', function () {
    $version = c1Version(921);
    c1SourceAsset($version, c1Zip([
        'manifest.json'    => json_encode(c1Manifest()),
        'payload/main.py'  => "print('ok')\n",
        '../evil.py'       => "print('evil')\n",
    ]));

    $result = (new AppCarrierBuilder())->build($version->id, c1Manifest(), 'unitree_g1');

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(AppCarrierBuilder::ERR_UNSAFE_ENTRY)
        ->and($result->artifactAssetId)->toBeNull();
});

it('C1-A-03 入口不在源包内 → 拒绝（否则装上也起不来）', function () {
    $version = c1Version(922);
    c1SourceAsset($version, c1Zip([
        'manifest.json'   => json_encode(c1Manifest()),
        'payload/other.py' => "print('other')\n",
    ]));

    $result = (new AppCarrierBuilder())->build($version->id, c1Manifest(), 'unitree_g1');

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(AppCarrierBuilder::ERR_ENTRYPOINT_MISSING);
});

it('C1-A-04 源包试图顶替平台生成的脚本 → 拒绝', function () {
    $version = c1Version(923);
    c1SourceAsset($version, c1Zip([
        'manifest.json'   => json_encode(c1Manifest()),
        'payload/main.py' => "print('ok')\n",
        'install.sh'      => "rm -rf /\n",
    ]));

    $result = (new AppCarrierBuilder())->build($version->id, c1Manifest(), 'agibot_x2');

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(AppCarrierBuilder::ERR_UNSAFE_ENTRY);
});

it('C1-A-05 非 GA 机型 → 拒绝', function () {
    $version = c1Version(924);

    $result = (new AppCarrierBuilder())->build($version->id, c1Manifest(), 'unitree_h1');

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(AppCarrierBuilder::ERR_TARGET_UNSUPPORTED);
});

it('C1-A-06 同输入两次构建 → 产物哈希一致（确定性归档）', function () {
    $version = c1Version(925);
    c1SourceAsset($version, c1Zip([
        'manifest.json'   => json_encode(c1Manifest()),
        'payload/main.py' => "print('det')\n",
    ]));

    $first = (new AppCarrierBuilder())->build($version->id, c1Manifest(), 'engineai_t800');
    $second = (new AppCarrierBuilder())->build($version->id, c1Manifest(), 'engineai_t800');

    expect($first->status)->toBe('success')
        ->and($second->status)->toBe('success')
        ->and($first->packageSha256)->toBe($second->packageSha256);
});
