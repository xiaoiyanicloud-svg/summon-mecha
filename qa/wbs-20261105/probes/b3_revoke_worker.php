<?php

/** B3 并发撤销探针：尝试撤销同一目标发布。 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\SkillCompile\SkillTargetReleaseService;

$id = (int) ($argv[1] ?? 0);
$worker = (string) ($argv[2] ?? 'w');

if ($id <= 0) {
    fwrite(STDERR, "release id required\n");
    exit(2);
}

if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "refuse: not isolated test db\n");
    exit(3);
}

try {
    $release = $app->make(SkillTargetReleaseService::class)->revoke($id, 999001, 'concurrency probe '.$worker);
    echo json_encode(['worker' => $worker, 'ok' => true, 'status' => $release->review_status]).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['worker' => $worker, 'ok' => false, 'error' => $exception->getMessage()]).PHP_EOL;
}
