<?php

/** B1 claim 探针：尝试领取指定命令，打印是否成功。 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\SkillCompile\BuildCommandService;

$id = (int) ($argv[1] ?? 0);
$worker = (string) ($argv[2] ?? 'w');

if ($id <= 0) {
    fwrite(STDERR, "command id required\n");
    exit(2);
}

if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "refuse: not isolated test db\n");
    exit(3);
}

$service = $app->make(BuildCommandService::class);

echo json_encode(['worker' => $worker, 'claimed' => $service->claim($id, $worker)]).PHP_EOL;
