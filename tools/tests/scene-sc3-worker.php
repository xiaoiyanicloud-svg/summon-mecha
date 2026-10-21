<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\ScenePrincipal;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

$work = json_decode((string) getenv('SCENE_SC3_WORK'), true, 16, JSON_THROW_ON_ERROR);
$barrier = $work['barrier'];
$label = $work['label'];
$wait = function () use ($barrier) {
    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('SC3_BARRIER_TIMEOUT');
        }
        usleep(10000);
    }
};
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
if (! empty($work['timeout'])) {
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
}
$started = microtime(true);
$attempts = 0;
try {
    if (! empty($work['stale_snapshot'])) {
        DB::beginTransaction();
        DB::table('scene_reservations')->count();
    }
    if ($work['operation'] !== 'deadlock') {
        file_put_contents($barrier.'.ready-'.$label, (string) $connectionId);
        $wait();
    }
    if (! empty($work['delay_ms'])) {
        usleep((int) $work['delay_ms'] * 1000);
    }
    $result = match ($work['operation']) {
        'hold' => app(Webkul\Shop\Services\Scenes\SceneReservationService::class)->hold($work['quote_id'], ['resource_ids' => $work['resource_ids']], $work['key'], 2, 1),
        'convert' => app(Webkul\Shop\Services\Scenes\SceneOrderConversionService::class)->convert(new ScenePrincipal($work['customer_id'], null), $work['quote_id'], $work['payload'], $work['key'], 1),
        'deadlock' => app(SceneWorkflow::class)->command('sc3:'.$label, 'scene.sc3.deadlock', $work['key'], [], function () use ($work, $label, $barrier, $wait, &$attempts) {
            $attempts++;
            SceneWorkflow::requireRow('scene_resources', $work['resource_ids'][0]);
            DB::table('sc3_concurrency_probe')->insert(['label' => $label]);
            file_put_contents($barrier.'.ready-'.$label, 'locked');
            $wait();
            SceneWorkflow::requireRow('scene_resources', $work['resource_ids'][1]);

            return ['id' => 1, 'state' => 'committed'];
        }),
    };
    if (! empty($work['stale_snapshot'])) {
        DB::commit();
    }
    $output = ['ok' => true, 'result' => $result];
} catch (Throwable $e) {
    DB::rollBack(0);
    $output = ['ok' => false, 'code' => $e instanceof Webkul\Shop\Services\Scenes\SceneWorkflowException ? $e->errorCode : ($e instanceof Illuminate\Database\QueryException ? 'DATABASE_CONCURRENCY_ERROR' : get_class($e)),
        'sqlstate' => $e instanceof Illuminate\Database\QueryException ? ($e->errorInfo[1] ?? null) : null];
}
$output += ['connection_id' => $connectionId, 'isolation' => 'REPEATABLE READ', 'elapsed_ms' => (int) ((microtime(true) - $started) * 1000), 'transaction_level' => DB::transactionLevel(), 'attempts' => $attempts];
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
