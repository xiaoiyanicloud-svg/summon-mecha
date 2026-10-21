<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Webkul\Shop\Services\Scenes\SceneReservationService;

function sc3Race(array $one, array $two, ?callable $locked = null): array
{
    $barrier = storage_path('sc3-barrier-'.bin2hex(random_bytes(6)));
    $processes = [];
    foreach (['a' => $one, 'b' => $two] as $label => $input) {
        $env = ['SCENE_SC3_WORK' => json_encode($input + ['barrier' => $barrier, 'label' => $label, 'key' => sc3Key()], JSON_THROW_ON_ERROR), 'SCENE_SC3_STORAGE' => storage_path()];
        foreach (['APP_ENV', 'SCENE_SC3_DATABASE', 'DB_DATABASE', 'SCENE_SC3_PORT', 'SCENE_SC3_RUN'] as $variable) {
            $env[$variable] = (string) getenv($variable);
        }
        $process = new Process([PHP_BINARY, '-d', 'extension=pdo_mysql', '-d', 'extension=pdo_sqlite', __DIR__.'/scene-sc3.php', '--mysql', '--worker'], null, $env);
        $process->setTimeout(25);
        $process->start();
        $processes[] = $process;
    }
    try {
        $deadline = microtime(true) + 20;
        while (! is_file($barrier.'.ready-a') || ! is_file($barrier.'.ready-b')) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('SC3_WORKER_EARLY_EXIT:'.$process->getErrorOutput().$process->getOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('SC3_PARENT_BARRIER_TIMEOUT');
            }
            usleep(10000);
        }
        file_put_contents($barrier.'.go', 'go');
        if ($locked) {
            $locked($processes);
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('SC3_WORKER_FAILED:'.$process->getErrorOutput().$process->getOutput());
            }
            $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        }
        same(true, $results[0]['connection_id'] !== $results[1]['connection_id']);
        foreach ($results as $result) {
            same(0, $result['transaction_level']);
        }
        $GLOBALS['sc3RaceEvidence'][] = $results;

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach (['.go', '.ready-a', '.ready-b'] as $suffix) {
            if (is_file($barrier.$suffix)) {
                unlink($barrier.$suffix);
            }
        }
    }
}

foreach ([false, true] as $stale) {
    test('SC3 MySQL 两客户真实进程抢同档'.($stale ? '，RR 旧快照且延迟获取父锁' : ''), function () use ($stale) {
        $a = sc3Quote(false, 1, true, false);
        $b = sc3Quote(false, 2, true, false);
        $base = ['operation' => 'hold', 'resource_ids' => [$GLOBALS['sc3Resource']], 'stale_snapshot' => $stale];
        $results = sc3Race($base + ['quote_id' => $a['id']], $base + ['quote_id' => $b['id'], 'delay_ms' => $stale ? 200 : 0]);
        same(1, count(array_filter($results, fn ($r) => $r['ok'])));
        same(1, count(array_filter($results, fn ($r) => ($r['code'] ?? null) === 'SCENE_RESOURCE_CONFLICT')));
        foreach ([$a, $b] as $f) {
            $row = DB::table('scene_reservations')->where('quote_id', $f['id'])->where('state', 'held')->first();
            if ($row) {
                app(SceneReservationService::class)->release($f['id'], $row->hold_group, sc3Key(), 1);
            }
        }
    });
}

test('SC3 MySQL 同一报价多进程不同提交标识只建一单一项目，丢响应可查询', function () {
    $f = sc3Quote();
    $base = ['operation' => 'convert', 'quote_id' => $f['id'], 'customer_id' => 1, 'payload' => $f['data']];
    $results = sc3Race($base, $base);
    same(true, $results[0]['ok']);
    same(true, $results[1]['ok']);
    same($results[0]['result'], $results[1]['result']);
    same(1, DB::table('scene_order_links')->where('quote_id', $f['id'])->count());
    same(1, DB::table('scene_projects')->where('quote_id', $f['id'])->count());
    // 下一演练使用其他时段；此处仅在专用 QA 库结束本次夹具的占用。
    DB::table('scene_projects')->where('quote_id', $f['id'])->update(['returned' => true]);
    DB::table('scene_reservations')->where('quote_id', $f['id'])->update(['state' => 'completed']);
});

test('SC3 MySQL 资源父行锁等待超时有界失败且无半预留', function () {
    $a = sc3Quote(false, 1, true, false);
    $b = sc3Quote(false, 2, true, false);
    DB::beginTransaction();
    Webkul\Shop\Services\Scenes\SceneWorkflow::requireRow('scene_resources', $GLOBALS['sc3Resource']);
    try {
        $base = ['operation' => 'hold', 'resource_ids' => [$GLOBALS['sc3Resource']], 'timeout' => true];
        $results = sc3Race($base + ['quote_id' => $a['id']], $base + ['quote_id' => $b['id']]);
        foreach ($results as $result) {
            same(false, $result['ok']);
            same('DATABASE_CONCURRENCY_ERROR', $result['code']);
            same(1205, (int) $result['sqlstate']);
            same(true, $result['elapsed_ms'] < 15000);
        }
    } finally {
        DB::rollBack();
    }
    same(0, DB::table('scene_reservations')->whereIn('quote_id', [$a['id'], $b['id']])->count());
});

test('SC3 MySQL 真死锁触发命令有界重试、重新读事实，失败尝试不残留', function () {
    DB::connection()->getSchemaBuilder()->create('sc3_concurrency_probe', fn ($t) => $t->string('label', 1)->primary());
    $one = ['operation' => 'deadlock', 'resource_ids' => [$GLOBALS['sc3Resource'], $GLOBALS['sc3Operator']]];
    $two = ['operation' => 'deadlock', 'resource_ids' => [$GLOBALS['sc3Operator'], $GLOBALS['sc3Resource']]];
    $results = sc3Race($one, $two);
    foreach ($results as $r) {
        same(true, $r['ok']);
        same(true, $r['attempts'] <= 3);
    }
    same(true, max(array_column($results, 'attempts')) > 1);
    same(2, DB::table('sc3_concurrency_probe')->count());
});
