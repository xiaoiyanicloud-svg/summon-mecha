<?php

/** 清理 B2 并发探针专用 build_key 的写入（只删该 key，绝不动其他数据）。 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$buildKey = $argv[1] ?? '';

if ($buildKey === '' || ! str_starts_with($buildKey, 'bk_b2_probe_')) {
    fwrite(STDERR, "refuse: build_key must start with bk_b2_probe_\n");
    exit(2);
}

if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "refuse: not isolated test db\n");
    exit(3);
}

$runQuery = fn () => DB::table('skill_build_runs')->where('build_key', $buildKey);

$evidence = [
    'build_key'      => $buildKey,
    'run_rows'       => $runQuery()->count(),
    'distinct_runs'  => $runQuery()->distinct()->count('run_number'),
    'min_run'        => $runQuery()->min('run_number'),
    'max_run'        => $runQuery()->max('run_number'),
];

$deletedRuns = $runQuery()->delete();
$deletedSeq = DB::table('skill_build_sequences')->where('build_key', $buildKey)->delete();

echo json_encode($evidence + ['deleted_runs' => $deletedRuns, 'deleted_sequences' => $deletedSeq]).PHP_EOL;
