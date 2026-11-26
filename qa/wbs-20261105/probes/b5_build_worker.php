<?php

/**
 * B5 构建确定性探针 —— 单进程执行一次真实编译，打印构建身份与产物指纹。
 *
 * 用法: php b5_build_worker.php <workerId> <storageRoot> <tmpDir>
 *
 * 与另一个进程的**差异**刻意设成最大：
 *   · 不同的私有存储根目录
 *   · 不同的临时目录（影响 tempnam 落点）
 *   · 不同的 skill_version_id / product / 资产行
 * 相同的只有：中立源 XML 字节、素材字节、清单、目标机型。
 *
 * 只要两边 build_key 与产物 sha256 一致，就证明产物与环境无关。
 *
 * 安全：启动后断言连接库名必须是 summon_mecha_step10_test，否则拒绝执行。
 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$workerId = (string) ($argv[1] ?? 'w0');
$storageRoot = (string) ($argv[2] ?? '');
$tmpDir = (string) ($argv[3] ?? '');

if ($storageRoot === '' || $tmpDir === '') {
    fwrite(STDERR, "usage: b5_build_worker.php <workerId> <storageRoot> <tmpDir>\n");
    exit(2);
}

if (! is_dir($tmpDir) && ! @mkdir($tmpDir, 0777, true)) {
    fwrite(STDERR, "cannot create tmp dir\n");
    exit(2);
}

// 让 tempnam()/sys_get_temp_dir() 落到本进程专属目录。
putenv('TMP='.$tmpDir);
putenv('TEMP='.$tmpDir);
$_ENV['TMP'] = $tmpDir;
$_ENV['TEMP'] = $tmpDir;

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Builders\PortableSkillBuilder;

$dbName = DB::connection()->getDatabaseName();
if ($dbName !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "REFUSE_DB={$dbName}; expected summon_mecha_step10_test\n");
    exit(3);
}

config(['filesystems.disks.private.root' => $storageRoot]);
Storage::forgetDisk('private');

$xml = <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="B5PR">
  <BehaviorTree ID="B5PR">
    <Sequence name="seq">
      <Action ID="SM_GestureWave" name="wave" hand="left" cycles="2" timeout_ms="8000"/>
      <Action ID="SM_AudioPlay" name="audio" asset="assets/welcome.wav" wait="true" timeout_ms="4000"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;

$manifest = [
    'schema'            => 'zhaohuan.skill/v2',
    'kind'              => 'composite',
    'name'              => ['zh_CN' => 'B5 跨进程确定性'],
    'version'           => '1.0.0',
    'description'       => 'B5 cross-process determinism',
    'pricing_mode'      => 'buyout',
    'delivery_channels' => ['agent', 'manual'],
    'permissions'       => ['motion_control', 'audio_play'],
    'resource_type'     => 'skill',
    'safety'            => ['risk_level' => 'medium', 'max_duration_s' => 20, 'estop_required' => true],
    'runtime'           => [
        'source_format'       => 'btcpp/xml4',
        'capability_contract' => 'portable-actions/v1',
        'source_entrypoint'   => 'behavior/main.xml',
    ],
];

try {
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'b5-probe-'.$workerId.'-'.substr(bin2hex(random_bytes(4)), 0, 8),
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

    // 源包字节必须**完全一致**：同样的条目、同样的顺序、同样的内容。
    $zipPath = $tmpDir.DIRECTORY_SEPARATOR.'source_'.$workerId.'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('behavior/main.xml', $xml);
    $zip->addFromString('assets/welcome.wav', 'RIFF44WAVEfmt 16000data-b5-probe-audio');
    $zip->close();

    $disk = Storage::disk(SkillAsset::disk());
    $relPath = "skills/{$version->id}/source/b5-probe.skill";
    $disk->put($relPath, fopen($zipPath, 'r'));

    $sourceAsset = SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => 'file',
        'asset_role'       => SkillAsset::ROLE_SOURCE,
        'asset_key'        => 'src_'.substr(hash_file('sha256', $zipPath), 0, 16),
        'file_path'        => $relPath,
        'file_name'        => 'b5-probe.skill',
        'file_size'        => filesize($zipPath),
        'file_hash'        => hash_file('sha256', $zipPath),
        'entrypoint'       => 'behavior/main.xml',
        'sort_order'       => 0,
    ]);

    $result = app(PortableSkillBuilder::class)->build($version->id, $manifest, 'unitree_g1');

    $artifact = $result->artifactAssetId ? SkillAsset::find($result->artifactAssetId) : null;

    $entries = [];
    if ($artifact) {
        $z = new ZipArchive();
        $full = $disk->path($artifact->file_path);
        if ($z->open($full) === true) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $stat = $z->statIndex($i);
                $entries[] = ['name' => $stat['name'], 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime']];
            }
            $z->close();
        }
    }

    echo json_encode([
        'worker'             => $workerId,
        'php_version'        => PHP_VERSION,
        'tmp_dir'            => $tmpDir,
        'storage_root'       => $storageRoot,
        'skill_version_id'   => (int) $version->id,
        'product_id'         => (int) $productId,
        'source_asset_id'    => (int) $sourceAsset->id,
        'source_file_sha256' => hash_file('sha256', $zipPath),
        'status'             => $result->status,
        'error_code'         => $result->errorCode,
        'build_key'          => $result->buildKey,
        'artifact_asset_id'  => $result->artifactAssetId,
        'package_sha256'     => $result->packageSha256,
        'artifact_size'      => $artifact?->file_size,
        'target_profile_digest' => $result->targetProfileDigest,
        'entries'            => $entries,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'PROBE_ERROR='.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
