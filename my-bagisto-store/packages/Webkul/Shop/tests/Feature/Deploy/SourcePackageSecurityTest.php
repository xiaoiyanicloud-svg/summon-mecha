<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Builders\PortableSkillBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\SourcePackageReader;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;

/**
 * B4 —— 源包安全：ZIP Slip / 软链 / 压缩炸弹 / 保留名冒用 / 体积预算 / 磁盘满。
 *
 * 全部走**真实 ZIP + 真实编译路径**：不 mock ZipArchive，也不直接调用私有方法 ——
 * 只有真实构造恶意包并让 `PortableSkillBuilder::build()` 跑一遍，
 * 才证明"恶意源包进不来"，而不是"某个 helper 返回了 false"。
 */

function b4Manifest(): array
{
    return [
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'composite',
        'name'              => ['zh_CN' => 'B4 源包安全用例'],
        'version'           => '1.0.0',
        'description'       => 'B4 源包安全',
        'pricing_mode'      => 'buyout',
        'delivery_channels' => ['agent', 'manual'],
        'permissions'       => ['motion_control', 'audio_play'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'     => 'medium',
            'max_duration_s' => 20,
            'estop_required' => true,
        ],
        'runtime'           => [
            'source_format'       => 'btcpp/xml4',
            'capability_contract' => 'portable-actions/v1',
            'source_entrypoint'   => 'behavior/main.xml',
        ],
    ];
}

function b4Xml(): string
{
    return <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="B4">
  <BehaviorTree ID="B4">
    <Sequence name="seq">
      <Action ID="SM_GestureWave" name="wave" hand="right" cycles="2" timeout_ms="8000"/>
      <Action ID="SM_AudioPlay" name="audio" asset="assets/welcome.wav" wait="true" timeout_ms="4000"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;
}

/**
 * 造一个真实的源包 ZIP。
 *
 * @param  array<string, string>  $extraEntries  条目名 => 内容
 * @param  array<int, string>  $symlinks  需要标记为 Unix 符号链接的条目名
 */
function b4Zip(array $extraEntries = [], array $symlinks = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'b4_src_').'.zip';

    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('behavior/main.xml', b4Xml());
    $zip->addFromString('assets/welcome.wav', 'RIFF44WAVEfmt 16000data-b4-audio');

    foreach ($extraEntries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    foreach ($symlinks as $name) {
        // S_IFLNK(0120000) | 0777 —— 与真实 symlink 条目一致的文件类型位
        $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, (0120777 << 16));
    }

    $zip->close();

    return $path;
}

function b4Version(): SkillVersion
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'b4-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_DRAFT,
        'license_mode' => 'per_device',
    ]);
}

function b4RegisterSource(SkillVersion $version, string $zipPath, string $fileName = 'b4.skill'): SkillAsset
{
    Storage::fake(SkillAsset::disk());

    $disk = Storage::disk(SkillAsset::disk());
    $relPath = "skills/{$version->id}/source/{$fileName}";
    $disk->put($relPath, fopen($zipPath, 'r'));

    return SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => 'file',
        'asset_role'       => SkillAsset::ROLE_SOURCE,
        'asset_key'        => 'src_'.substr(hash('sha256', $zipPath.$version->id), 0, 16),
        'file_path'        => $relPath,
        'file_name'        => $fileName,
        'file_size'        => filesize($zipPath),
        'file_hash'        => hash_file('sha256', $zipPath),
        'entrypoint'       => 'behavior/main.xml',
        'sort_order'       => 0,
    ]);
}

/**
 * 用一个源包跑一遍真实编译，返回 BuildResult。
 */
function b4Build(SkillVersion $version, string $zipPath) : \Webkul\Shop\Services\SkillCompile\BuildResult
{
    b4RegisterSource($version, $zipPath);

    return app(PortableSkillBuilder::class)->build($version->id, b4Manifest(), 'unitree_g1');
}

// ==================== ① 合法源包必须仍然能编译（回归） ====================

it('B4-01 合法源包照常编译成功，产物资产登记且盘上存在', function () {
    $version = b4Version();
    $result = b4Build($version, b4Zip());

    expect($result->status)->toBe('success')
        ->and($result->artifactAssetId)->not->toBeNull();

    $artifact = SkillAsset::find($result->artifactAssetId);

    expect($artifact)->not->toBeNull()
        ->and($artifact->asset_role)->toBe(SkillAsset::ROLE_ARTIFACT)
        ->and(Storage::disk(SkillAsset::disk())->exists($artifact->file_path))->toBeTrue();
});

// ==================== ② ZIP Slip / 路径穿越 ====================

it('B4-02 源包含 `../` 穿越条目 → 拒绝，码 SOURCE_PACKAGE_UNSAFE_ENTRY', function () {
    $result = b4Build(b4Version(), b4Zip(['../evil.txt' => 'pwned']));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_UNSAFE_ENTRY)
        ->and($result->error)->toContain('../evil.txt');
});

it('B4-03 源包含绝对路径条目 → 拒绝', function () {
    $result = b4Build(b4Version(), b4Zip(['/etc/passwd' => 'x']));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_UNSAFE_ENTRY);
});

it('B4-04 源包含反斜杠条目 → 拒绝（Windows 路径语义穿越）', function () {
    $result = b4Build(b4Version(), b4Zip(['a\\b.txt' => 'x']));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_UNSAFE_ENTRY);
});

it('B4-05 源包含盘符条目 → 拒绝', function () {
    $result = b4Build(b4Version(), b4Zip(['C:/win.txt' => 'x']));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_UNSAFE_ENTRY);
});

it('B4-06 源包含 `a/./b` 与双斜杠条目 → 拒绝（非规范路径）', function () {
    $dot = b4Build(b4Version(), b4Zip(['a/./b.txt' => 'x']));
    $dbl = b4Build(b4Version(), b4Zip(['x//y.txt' => 'x']));

    expect($dot->errorCode)->toBe(SourcePackageReader::CODE_UNSAFE_ENTRY)
        ->and($dbl->errorCode)->toBe(SourcePackageReader::CODE_UNSAFE_ENTRY);
});

// ==================== ③ 软链接条目 ====================

it('B4-07 源包含 Unix 符号链接条目 → 拒绝，码 SOURCE_PACKAGE_SYMLINK_ENTRY', function () {
    $zip = b4Zip(['link.txt' => '/etc/passwd'], ['link.txt']);

    $result = b4Build(b4Version(), $zip);

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_SYMLINK_ENTRY);
});

// ==================== ④ 保留名冒用 ====================

it('B4-08 源包自带 artifact.json（想顶替产物元数据）→ 拒绝，码 SOURCE_PACKAGE_RESERVED_ENTRY', function () {
    $result = b4Build(b4Version(), b4Zip(['artifact.json' => '{"build_key":"bk_forged"}']));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_RESERVED_ENTRY);
});

it('B4-09 源素材与生成文件同名（action.py）→ 打包阶段拒绝，不登记任何产物资产', function () {
    $version = b4Version();
    $result = b4Build($version, b4Zip(['action.py' => 'import os; os.system("id")']));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_RESERVED_ENTRY)
        ->and($result->error)->toContain('禁止顶替')
        // 关键：没有产物登记 = 交付链路拿不到被顶替的包
        ->and(SkillAsset::where('skill_version_id', $version->id)
            ->where('asset_role', SkillAsset::ROLE_ARTIFACT)->count())->toBe(0);
});

// ==================== ⑤ 体积预算与压缩炸弹 ====================

it('B4-10 条目数超预算 → 拒绝', function () {
    config(['skill_build.source_package.max_entries' => 3]);

    $result = b4Build(b4Version(), b4Zip([
        'a1.txt' => '1', 'a2.txt' => '2', 'a3.txt' => '3', 'a4.txt' => '4',
    ]));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_TOO_MANY_ENTRIES);
});

it('B4-11 解压总大小超预算 → 拒绝', function () {
    config(['skill_build.source_package.max_total_uncompressed_bytes' => 200]);

    $result = b4Build(b4Version(), b4Zip(['assets/big.bin' => str_repeat('B', 4096)]));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_TOTAL_TOO_LARGE);
});

it('B4-12 高压缩比条目（压缩炸弹）→ 拒绝，码 SOURCE_PACKAGE_BOMB', function () {
    config([
        'skill_build.source_package.max_compression_ratio'       => 2,
        'skill_build.source_package.ratio_check_threshold_bytes' => 1024,
    ]);

    $result = b4Build(b4Version(), b4Zip(['assets/zeros.bin' => str_repeat('A', 200000)]));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_BOMB);
});

it('B4-13 压缩包本体超预算 → 打开之前就拒绝', function () {
    config(['skill_build.source_package.max_archive_bytes' => 64]);

    $result = b4Build(b4Version(), b4Zip());

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_ARCHIVE_TOO_LARGE);
});

// ==================== ⑥ 入口 ====================

it('B4-14 缺少入口 XML → 拒绝，码 SOURCE_PACKAGE_ENTRYPOINT_MISSING', function () {
    $path = tempnam(sys_get_temp_dir(), 'b4_noep_').'.zip';
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('other/main.xml', b4Xml());
    $zip->close();

    $result = b4Build(b4Version(), $path);

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_ENTRYPOINT_MISSING);
});

it('B4-15 清单声明非法入口路径（`../`）→ 拒绝，码 SOURCE_PACKAGE_ENTRYPOINT_INVALID', function () {
    $version = b4Version();
    b4RegisterSource($version, b4Zip());

    $manifest = b4Manifest();
    $manifest['runtime']['source_entrypoint'] = '../../etc/passwd';

    $result = app(PortableSkillBuilder::class)->build($version->id, $manifest, 'unitree_g1');

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(SourcePackageReader::CODE_ENTRYPOINT_INVALID);
});

// ==================== ⑦ 服务层：拒绝要落到 skill_build 且不产出资产 ====================

it('B4-16 SkillBuildService 把源包拒绝如实记为 failed，且不存在任何产物资产', function () {
    $version = b4Version();
    b4RegisterSource($version, b4Zip(['../evil.txt' => 'pwned']));

    $results = app(SkillBuildService::class)->compile($version->id, b4Manifest(), ['unitree_g1']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['status'])->toBe('failed')
        ->and($results[0]['error_code'])->toBe(SourcePackageReader::CODE_UNSAFE_ENTRY)
        ->and($results[0]['error'])->toContain(SourcePackageReader::CODE_UNSAFE_ENTRY);

    expect(SkillAsset::where('skill_version_id', $version->id)
        ->where('asset_role', SkillAsset::ROLE_ARTIFACT)->count())->toBe(0);
});
