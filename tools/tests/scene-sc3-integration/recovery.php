<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$dir = dirname(storage_path());
$fixtures = json_decode(file_get_contents($dir.'/fixtures.json'), true, 32, JSON_THROW_ON_ERROR);
$receipt = json_decode(file_get_contents($dir.'/receipt.json'), true, 32, JSON_THROW_ON_ERROR);
$checks = [];
$assert = function (string $name, bool $ok) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    }$checks[] = ['name' => $name, 'pass' => true];
    echo 'PASS '.$name.PHP_EOL;
};
$target = DB::table('business_outbox')->where('event_type', 'scene.order.converted')->where('aggregate_id', $receipt['project_id'])->first();
$assert('HTTP 转换留下待处理真实 outbox', $target !== null && $target->processed_at === null);
$table = (new Webkul\Shop\Models\Notification)->getTable();
$failure = 'sc3_qa_notification_failure';
$blocking = 'sc3_qa_notification_block';
$lock = 'sc3-qa-'.substr(getenv('SCENE_SC3_INTEGRATION_RUN'), 0, 15);
$env = [];
foreach (['APP_ENV', 'APP_KEY', 'APP_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE', 'SESSION_DRIVER', 'MAIL_MAILER', 'QUEUE_CONNECTION', 'SCENE_SC3_INTEGRATION_RUN', 'SCENE_SC3_INTEGRATION_DIR', 'SCENE_SC3_PHP_ARGS', 'PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC'] as $name) {
    $env[$name] = (string) getenv($name);
}
$worker = function (int $limit = 1000) use ($env) {
    $args = json_decode(getenv('SCENE_SC3_PHP_ARGS'), true, 32, JSON_THROW_ON_ERROR);
    $p = new Process([PHP_BINARY, ...$args, __DIR__.'/worker.php', (string) $limit], dirname(__DIR__, 3), $env);
    $p->setTimeout(180);

    return $p;
};
$reports = [];
$process = null;
try {
    DB::unprepared("CREATE TRIGGER $failure BEFORE INSERT ON `$table` FOR EACH ROW BEGIN IF NEW.type='scene.order.converted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated notification failure'; END IF; END");
    $p = $worker();
    $p->run();
    $reports[] = ['phase' => 'notification_failure', 'exit' => $p->getExitCode(), 'output' => $p->getOutput()];
    $after = DB::table('business_outbox')->find($target->id);
    $assert('实际命令在投影失败时返回非零', $p->getExitCode() === 1);
    $assert('通知失败保留业务订单且无消费回执', $after->processed_at === null && $after->last_error_code === 'OUTBOX_PROJECTION_FAILED' && DB::table('business_event_receipts')->where('event_id', $target->event_id)->count() === 0 && DB::table($table)->where('type', 'scene.order.converted')->count() === 0 && DB::table('orders')->where('id', $receipt['order_id'])->exists());
    DB::unprepared("DROP TRIGGER $failure");
    app(Webkul\Shop\Services\Scenes\SceneOperationsService::class)->replay((int) $target->id, (int) $fixtures['admins']['reviewer']['id']);
    DB::selectOne('SELECT GET_LOCK(?, 1) AS acquired', [$lock]);
    DB::unprepared("CREATE TRIGGER $blocking BEFORE INSERT ON `$table` FOR EACH ROW BEGIN IF NEW.type='scene.order.converted' THEN SET @sc3_qa_gate=GET_LOCK('$lock', 30); END IF; END");
    $process = $worker(1);
    $process->start();
    $deadline = microtime(true) + 90;
    $connection = null;
    do {
        if (preg_match('/SC3_WORKER_CONNECTION:(\d+)/', $process->getOutput(), $m)) {
            $connection = (int) $m[1];
            $row = DB::selectOne('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID=?', [$connection]);
            if ($row && str_contains(strtolower((string) $row->STATE), 'user lock')) {
                break;
            }
        }
        if (! $process->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('Worker 未达到真实通知投影屏障：'.$process->getOutput().$process->getErrorOutput());
        }
        usleep(50000);
    } while (true);
    $process->stop(0);
    $reports[] = ['phase' => 'killed_after_committed_lease', 'connection_id' => $connection, 'exit' => $process->getExitCode()];
    DB::selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
    DB::unprepared("DROP TRIGGER $blocking");
    $leased = DB::table('business_outbox')->find($target->id);
    $assert('真实 worker 中断后仅保留已提交租约，通知及 receipt 回滚', $leased->processed_at === null && $leased->lease_token !== null && DB::table('business_event_receipts')->where('event_id', $target->event_id)->count() === 0 && DB::table($table)->where('type', 'scene.order.converted')->count() === 0);
    $p = $worker(1);
    $p->run();
    $reports[] = ['phase' => 'before_lease_expiry', 'exit' => $p->getExitCode(), 'output' => $p->getOutput()];
    $assert('租约到期前其他进程不提前消费', str_contains($p->getOutput(), '"idle"') && DB::table('business_outbox')->find($target->id)->processed_at === null);
    echo 'WAIT_REAL_LEASE_EXPIRY '.$leased->lease_until.PHP_EOL;
    while ($leased->lease_until > now('UTC')->format('Y-m-d H:i:s')) {
        usleep(200000);
    }
    $p = $worker(1);
    $p->run();
    $reports[] = ['phase' => 'recovered_after_real_expiry', 'exit' => $p->getExitCode(), 'output' => $p->getOutput()];
    $assert('真实租约到期后新进程恢复且仅一份通知及回执', $p->getExitCode() === 0 && DB::table('business_outbox')->find($target->id)->processed_at !== null && DB::table('business_event_receipts')->where('event_id', $target->event_id)->count() === 1 && DB::table($table)->where('type', 'scene.order.converted')->count() === 1);
    $p = $worker(1);
    $p->run();
    $reports[] = ['phase' => 'repeated_consumer', 'exit' => $p->getExitCode(), 'output' => $p->getOutput()];
    $assert('再次启动消费者不重复通知', DB::table($table)->where('type', 'scene.order.converted')->count() === 1 && DB::table('business_event_receipts')->where('event_id', $target->event_id)->count() === 1);
    $assert('项目订单资源保持完整且事务层级归零', DB::transactionLevel() === 0 && DB::table('scene_reservations')->where('quote_id', $fixtures['quote_id'])->where('state', 'confirmed')->count() === 1 && DB::table('scene_order_links')->where('quote_id', $fixtures['quote_id'])->count() === 1);
    file_put_contents($dir.'/worker-checks.json', json_encode(['checks' => $checks, 'reports' => $reports, 'event_id' => $target->event_id, 'project_id' => $receipt['project_id']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
} finally {
    if ($process?->isRunning()) {
        $process->stop(0);
    }
    DB::unprepared("DROP TRIGGER IF EXISTS $failure");
    DB::unprepared("DROP TRIGGER IF EXISTS $blocking");
    DB::selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
}
