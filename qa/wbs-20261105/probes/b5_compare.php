<?php

/**
 * B5 构建确定性探针 —— 比对两个独立进程的产物指纹，并清理探针数据。
 *
 * 用法: php b5_compare.php <a.json> <b.json>
 *
 * 判定（全部必须成立）：
 *   1. 两个进程的 build_key 相同
 *   2. 两个进程的产物 sha256 相同、字节数相同
 *   3. 产物 ZIP 的条目名/大小/mtime 序列完全一致
 *   4. 两个进程的 skill_version_id / 存储根 / 临时目录确实不同（否则"一致"没有意义）
 *
 * 安全：断言库名是 summon_mecha_step10_test 才允许读写与清理。
 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$dbName = DB::connection()->getDatabaseName();
if ($dbName !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "REFUSE_DB={$dbName}\n");
    exit(3);
}

$aPath = $argv[1] ?? '';
$bPath = $argv[2] ?? '';

if ($aPath === '' || $bPath === '') {
    fwrite(STDERR, "usage: b5_compare.php <a.json> <b.json>\n");
    exit(2);
}

$a = json_decode((string) file_get_contents($aPath), true);
$b = json_decode((string) file_get_contents($bPath), true);

if (! is_array($a) || ! is_array($b)) {
    fwrite(STDERR, "invalid json input\n");
    exit(2);
}

$checks = [
    'same_build_key'          => $a['build_key'] === $b['build_key'] && $a['build_key'] !== null,
    'same_package_sha256'     => $a['package_sha256'] === $b['package_sha256'] && $a['package_sha256'] !== null,
    'same_artifact_size'      => $a['artifact_size'] === $b['artifact_size'],
    'same_entry_sequence'     => $a['entries'] === $b['entries'],
    'same_profile_digest'     => $a['target_profile_digest'] === $b['target_profile_digest'],
    'different_version_id'    => $a['skill_version_id'] !== $b['skill_version_id'],
    'different_storage_root'  => $a['storage_root'] !== $b['storage_root'],
    'different_tmp_dir'       => $a['tmp_dir'] !== $b['tmp_dir'],
    'both_succeeded'          => $a['status'] === 'success' && $b['status'] === 'success',
];

$allPass = ! in_array(false, $checks, true);

// ---- 清理探针数据（只动 summon_mecha_step10_test）----
$cleanup = [];

foreach ([$a, $b] as $row) {
    $versionId = (int) ($row['skill_version_id'] ?? 0);
    $productId = (int) ($row['product_id'] ?? 0);
    $buildKey = (string) ($row['build_key'] ?? '');

    if ($versionId <= 0) {
        continue;
    }

    $cleanup[] = ['table' => 'skill_target_release_events', 'deleted' => DB::table('skill_target_release_events')->where('skill_version_id', $versionId)->delete()];
    $cleanup[] = ['table' => 'skill_target_releases', 'deleted' => DB::table('skill_target_releases')->where('skill_version_id', $versionId)->delete()];

    foreach (['skill_build_runs', 'skill_builds', 'skill_build_commands'] as $table) {
        if (Schema::hasTable($table)) {
            $cleanup[] = ['table' => $table, 'deleted' => DB::table($table)->where('skill_version_id', $versionId)->delete()];
        }
    }

    $cleanup[] = ['table' => 'skill_assets', 'deleted' => DB::table('skill_assets')->where('skill_version_id', $versionId)->delete()];
    $cleanup[] = ['table' => 'skill_versions', 'deleted' => DB::table('skill_versions')->where('id', $versionId)->delete()];

    if ($productId > 0) {
        $cleanup[] = ['table' => 'products', 'deleted' => DB::table('products')->where('id', $productId)->delete()];
    }

    if ($buildKey !== '' && Schema::hasTable('skill_build_sequences')) {
        $cleanup[] = ['table' => 'skill_build_sequences', 'deleted' => DB::table('skill_build_sequences')->where('build_key', $buildKey)->delete()];
    }
}

echo json_encode([
    'verdict' => $allPass ? 'IDENTICAL' : 'DIVERGED',
    'checks'  => $checks,
    'a'       => array_intersect_key($a, array_flip(['worker', 'skill_version_id', 'storage_root', 'tmp_dir', 'build_key', 'package_sha256', 'artifact_size'])),
    'b'       => array_intersect_key($b, array_flip(['worker', 'skill_version_id', 'storage_root', 'tmp_dir', 'build_key', 'package_sha256', 'artifact_size'])),
    'entries_count' => count($a['entries'] ?? []),
    'entries' => $a['entries'] ?? [],
    'cleanup' => $cleanup,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;

exit($allPass ? 0 : 1);
