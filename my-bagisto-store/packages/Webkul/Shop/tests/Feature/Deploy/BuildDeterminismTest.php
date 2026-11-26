<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Builders\PortableSkillBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\TargetAppBuilder;

/**
 * B5 —— 构建确定性：同一份中立源 + 同一机型，换目录、换进程、换版本 id，
 * 必须得到**同一个 build_key** 与**同一份产物字节**（sha256 相同）。
 *
 * 这里的"确定性"不是"大概相同"：
 *   · build_key 由 JCS 规范化的构建身份算出（源摘要 / 版本号 / 机型 / Profile 摘要 / 编译器版本），
 *     其中**不含** skill_version_id、时间戳、路径、主机名；
 *   · 产物 ZIP 内部条目按名排序，条目 mtime 固定为 DETERMINISTIC_MTIME。
 * 跨进程的真实证据见 `qa/wbs-20261105/b5-determinism-evidence.json`（两个独立 PHP 进程，
 * 不同存储根目录 + 不同临时目录），本文件覆盖单进程内的确定性契约。
 */

function b5Manifest(): array
{
    return [
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'composite',
        'name'              => ['zh_CN' => 'B5 确定性用例'],
        'version'           => '1.0.0',
        'description'       => 'B5',
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

function b5Xml(): string
{
    return <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="B5">
  <BehaviorTree ID="B5">
    <Sequence name="seq">
      <Action ID="SM_GestureWave" name="wave" hand="left" cycles="2" timeout_ms="8000"/>
      <Action ID="SM_AudioPlay" name="audio" asset="assets/welcome.wav" wait="true" timeout_ms="4000"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;
}

/**
 * 造一个全新的版本 + 一份**字节完全相同**的源包。
 *
 * 每次调用都会 `Storage::fake()` —— 即换一个全新的存储根目录。
 */
function b5Fixture(string $audio = 'RIFF44WAVEfmt 16000data-b5-audio'): array
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'b5-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $version = SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_DRAFT,
        'license_mode' => 'per_device',
    ]);

    $tmpZip = tempnam(sys_get_temp_dir(), 'b5_src_').'.zip';
    $zip = new \ZipArchive();
    $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('behavior/main.xml', b5Xml());
    $zip->addFromString('assets/welcome.wav', $audio);
    $zip->close();

    Storage::fake(SkillAsset::disk());
    $disk = Storage::disk(SkillAsset::disk());
    $relPath = "skills/{$version->id}/source/b5.skill";
    $disk->put($relPath, fopen($tmpZip, 'r'));

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => 'file',
        'asset_role'       => SkillAsset::ROLE_SOURCE,
        'asset_key'        => 'src_'.substr(hash('sha256', $tmpZip), 0, 16),
        'file_path'        => $relPath,
        'file_name'        => 'b5.skill',
        'file_size'        => filesize($tmpZip),
        'file_hash'        => hash_file('sha256', $tmpZip),
        'entrypoint'       => 'behavior/main.xml',
        'sort_order'       => 0,
    ]);

    $sourceHash = hash_file('sha256', $tmpZip);

    return [
        'version'    => $version,
        'source_hash' => $sourceHash,
    ];
}

it('B5-01 同一份源 + 同一机型：两个不同版本 id、两个不同存储根 → build_key 与产物 sha256 完全一致', function () {
    $a = b5Fixture();
    $resA = app(PortableSkillBuilder::class)->build($a['version']->id, b5Manifest(), 'unitree_g1');

    $b = b5Fixture();
    $resB = app(PortableSkillBuilder::class)->build($b['version']->id, b5Manifest(), 'unitree_g1');

    expect($resA->status)->toBe('success')
        ->and($resB->status)->toBe('success')
        // 版本 id 不同、存储根不同，构建身份必须相同
        ->and($resA->buildKey)->toBe($resB->buildKey)
        // 产物字节必须逐字节相同
        ->and($resA->packageSha256)->toBe($resB->packageSha256)
        ->and($resA->buildKey)->toStartWith('bk_');

    // 落盘的两份产物哈希也必须相同（不是只在内存里相同）
    $assetA = SkillAsset::find($resA->artifactAssetId);
    $assetB = SkillAsset::find($resB->artifactAssetId);

    expect($assetA->file_hash)->toBe($assetB->file_hash)
        ->and($assetA->file_size)->toBe($assetB->file_size);
});

it('B5-02 产物 ZIP 每条目 mtime 固定为确定性时间戳，且条目按名排序', function () {
    $fixture = b5Fixture();
    $result = app(PortableSkillBuilder::class)->build($fixture['version']->id, b5Manifest(), 'unitree_g1');

    $asset = SkillAsset::find($result->artifactAssetId);
    $zip = new \ZipArchive();
    expect($zip->open(Storage::disk(SkillAsset::disk())->path($asset->file_path)))->toBeTrue();

    $names = [];
    $mtimes = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $names[] = $stat['name'];
        $mtimes[] = (int) $stat['mtime'];
    }

    $zip->close();

    expect(array_unique($mtimes))->toBe([TargetAppBuilder::DETERMINISTIC_MTIME]);

    $sorted = $names;
    sort($sorted, SORT_STRING);
    expect($names)->toBe($sorted);
});

it('B5-03 换目标机型 → build_key 与产物哈希都改变（确定性不等于"都一样"）', function () {
    $fixture = b5Fixture();

    // 第二个目标用 mock profile：本用例验的是"构建身份随目标变"，与 GA 无关
    // （GA 机型里目前只有 unitree_g1 有关节限值的厂商原始依据，其余会被物理限值闸门挡下）。
    $g1 = app(PortableSkillBuilder::class)->build($fixture['version']->id, b5Manifest(), 'unitree_g1');
    $mock = app(PortableSkillBuilder::class)->build($fixture['version']->id, b5Manifest(), 'mock');

    expect($g1->status)->toBe('success')
        ->and($mock->status)->toBe('success')
        ->and($g1->buildKey)->not->toBe($mock->buildKey)
        ->and($g1->packageSha256)->not->toBe($mock->packageSha256);
});

it('B5-04 源素材字节改变 → build_key 不变（身份只认 XML 的 IR 摘要）但产物哈希必须改变', function () {
    // 这条是"确定性"的边界说明：build_key 绑定的是中立 XML 的 IR 摘要，
    // 素材变化不改变构建身份，但产物字节必须变 —— 否则"换了音频却拿到同一份包"就是静默错包。
    $a = app(PortableSkillBuilder::class)->build(b5Fixture('AUDIO-ORIGINAL')['version']->id, b5Manifest(), 'unitree_g1');
    $b = app(PortableSkillBuilder::class)->build(b5Fixture('AUDIO-MODIFIED')['version']->id, b5Manifest(), 'unitree_g1');

    expect($a->status)->toBe('success')
        ->and($b->status)->toBe('success')
        ->and($a->buildKey)->toBe($b->buildKey)
        ->and($a->packageSha256)->not->toBe($b->packageSha256);
});
