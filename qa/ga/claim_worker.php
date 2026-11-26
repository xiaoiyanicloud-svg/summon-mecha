<?php

use Illuminate\Contracts\Console\Kernel;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\DeployTaskService;

require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/my-bagisto-store/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$did = $argv[1] ?? '';
try {
    $task = app(DeployTaskService::class)->claimNextCommand($did, DeployTask::PROTOCOL_V2);
    echo json_encode([
        'pid' => getmypid(),
        'claimed' => $task !== null,
        'task_no' => $task?->task_no,
        'delivery_attempt' => $task?->delivery_attempt,
    ]).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}