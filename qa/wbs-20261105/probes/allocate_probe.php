<?php

/**
 * B2 并发探针：每个进程独立 bootstrap Laravel，真实提交事务。
 * 用法: php allocate_probe.php <build_key> <iterations> <worker_id>
 *
 * 安全：启动后先断言连接库名必须是 summon_mecha_step10_test，否则拒绝执行。
 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\SkillCompile\RunNumberAllocator;

$buildKey = $argv[1] ?? '';
$iterations = max(1, (int) ($argv[2] ?? 1));
$workerId = $argv[3] ?? 'w0';

if ($buildKey === '') {
    fwrite(STDERR, "build_key required\n");
    exit(2);
}

$dbName = DB::connection()->getDatabaseName();
if ($dbName !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "REFUSE_DB={$dbName}; expected summon_mecha_step10_test\n");
    exit(3);
}

$allocator = $app->make(RunNumberAllocator::class);
$numbers = [];

try {
    for ($i = 0; $i < $iterations; $i++) {
        $run = $allocator->createRun($buildKey, [
            'skill_version_id'      => 920001,
            'target_model_code'     => 'unitree_g1',
            'source_digest'         => 'sha256:'.str_repeat('a', 64),
            'target_profile_digest' => 'sha256:'.str_repeat('b', 64),
            'status'                => 'success',
            'carrier_type'          => 'file',
            'artifact_asset_id'     => null,
            'build_log'             => 'concurrency probe '.$workerId,
        ]);
        $numbers[] = (int) $run->run_number;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "PROBE_ERROR=".get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}

echo json_encode(['worker' => $workerId, 'numbers' => $numbers]).PHP_EOL;
