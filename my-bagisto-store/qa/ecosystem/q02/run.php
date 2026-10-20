<?php

/**
 * Q-02 · 并发 / 隔离演练总控。
 *
 *   DB_DATABASE=summon_mecha_step10_test APP_ENV=testing \
 *     php qa/ecosystem/q02/run.php [--json=D:/main/docs/ecosystem/q02-result.json]
 *
 * 覆盖：
 *   1. 专用库硬校验：root/public 库被 bootstrap 拒绝（真实子进程验证）；
 *   2. 容量 1、两个真实 PHP 进程同时提交：只有一个 confirmed；
 *   3. 两进程行锁等待：waiter 真实阻塞 >= 1s；
 *   4. REPEATABLE READ：普通 SELECT 保持旧快照，FOR UPDATE 读取当前提交。
 *
 * 所有夹具在结束时清理；失败会保留 JSON 与退出码 1，便于定位。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$jsonPath = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--json=')) {
        $jsonPath = substr($argument, 7);
    }
}

$here = __DIR__;
$database = (string) config('database.connections.mysql.database');
$result = [
    'q'            => 'Q-02',
    'generated_at' => gmdate('c'),
    'database'     => $database,
    'app_env'      => app()->environment(),
    'checks'       => [],
    'ok'           => false,
];

$check = static function (string $name, bool $passed, array $detail = []) use (&$result): void {
    $result['checks'][] = ['name' => $name, 'passed' => $passed] + $detail;
};

$fixture = null;
$barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-q02-barrier-'.bin2hex(random_bytes(4));
$lockFlag = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-q02-lock-'.bin2hex(random_bytes(4));

try {
    // ── 1. 专用库硬校验 ────────────────────────────────────────────────
    $guarded = qa_spawn($here.'/guard_probe.php', [], [
        'APP_ENV'     => 'local',
        'DB_DATABASE' => 'root',
    ]);
    $check('guard_rejects_root', $guarded['exit'] === 2, [
        'exit'   => $guarded['exit'],
        'stderr' => trim($guarded['stderr']),
    ]);

    $allowed = qa_spawn($here.'/guard_probe.php');
    $allowedJson = qa_last_json($allowed['stdout']);
    $check('guard_accepts_test_db', $allowed['exit'] === 0 && ($allowedJson['ok'] ?? false) === true, [
        'exit'     => $allowed['exit'],
        'database' => $allowedJson['database'] ?? null,
    ]);

    // ── 2. 容量 1 两进程竞争 ──────────────────────────────────────────
    $prepare = qa_spawn($here.'/prepare.php', ['1', 'auto']);
    $fixture = qa_last_json($prepare['stdout']);

    if ($fixture === null || ($fixture['event_id'] ?? 0) <= 0) {
        throw new RuntimeException('fixture prepare failed: '.$prepare['stderr'].$prepare['stdout']);
    }

    @unlink($barrier);

    // 必须用非阻塞 proc_open 同时启动两个子进程，再写屏障文件；
    // 用 qa_spawn 顺序执行会让第一个进程永远等不到屏障。
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procs = [];
    $pipes = [];

    foreach ([0, 1] as $index) {
        $procs[$index] = proc_open([
            PHP_BINARY,
            $here.'/capacity_worker.php',
            (string) $fixture['event_id'],
            (string) $fixture['customer_ids'][$index],
            'q02-'.$fixture['marker'].'-'.$index,
            $barrier,
        ], $descriptors, $pipes[$index], null, null);
    }

    usleep(300000);
    file_put_contents($barrier, 'go');

    $workers = [];

    foreach ($procs as $index => $process) {
        if (! is_resource($process)) {
            $workers[$index] = ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];

            continue;
        }

        $stdout = stream_get_contents($pipes[$index][1]);
        $stderr = stream_get_contents($pipes[$index][2]);
        fclose($pipes[$index][1]);
        fclose($pipes[$index][2]);
        $workers[$index] = ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    $parsed = array_map(fn ($worker) => qa_last_json($worker['stdout']), $workers);
    $successes = array_values(array_filter($parsed, fn ($item) => is_array($item) && ($item['ok'] ?? false)));
    $failures = array_values(array_filter($parsed, fn ($item) => is_array($item) && ! ($item['ok'] ?? false)));
    $fullRejections = array_values(array_filter($failures, fn ($item) => ($item['error_code'] ?? '') === 'REGISTRATION_FULL'));

    $confirmed = (int) DB::table('event_registration_attempts')
        ->where('event_id', $fixture['event_id'])
        ->where('status', 'confirmed')
        ->count();
    $registrations = (int) DB::table('event_registrations')->where('event_id', $fixture['event_id'])->count();
    $scope = DB::table('event_registration_scopes')->where('id', $fixture['scope_id'])->first();

    $check('capacity_race_one_confirmed', count($successes) === 1 && $confirmed === 1 && $registrations === 1, [
        'successes'      => count($successes),
        'failures'       => count($failures),
        'confirmed_rows' => $confirmed,
        'registrations'  => $registrations,
        'confirmed_count'=> (int) ($scope->confirmed_count ?? -1),
        'workers'        => $parsed,
    ]);
    $check('capacity_race_loser_rejected', count($fullRejections) === 1, [
        'rejections' => $fullRejections,
    ]);

    // ── 3. 两进程行锁等待 ────────────────────────────────────────────
    @unlink($lockFlag);

    $holder = proc_open([
        PHP_BINARY,
        $here.'/lock_probe.php',
        'holder',
        (string) $fixture['event_id'],
        (string) $fixture['scope_id'],
        $lockFlag,
        '3',
    ], $descriptors, $holderPipes, null, null);

    $holderStart = microtime(true);

    while (! file_exists($lockFlag) && microtime(true) - $holderStart < 10) {
        usleep(10000);
    }

    $waiter = qa_spawn($here.'/lock_probe.php', [
        'waiter',
        (string) $fixture['event_id'],
        (string) $fixture['scope_id'],
        $lockFlag,
    ]);

    $holderOut = is_resource($holder) ? stream_get_contents($holderPipes[1]) : '';
    $holderErr = is_resource($holder) ? stream_get_contents($holderPipes[2]) : '';
    if (is_resource($holder)) {
        fclose($holderPipes[1]);
        fclose($holderPipes[2]);
        proc_close($holder);
    }

    $holderJson = qa_last_json((string) $holderOut);
    $waiterJson = qa_last_json($waiter['stdout']);

    $check('real_lock_wait', ($waiterJson['blocked'] ?? false) === true && ($waiterJson['waited_ms'] ?? 0) >= 1000, [
        'holder' => $holderJson,
        'waiter' => $waiterJson,
        'holder_stderr' => trim((string) $holderErr),
    ]);

    // ── 4. REPEATABLE READ 快照 / 当前读 ─────────────────────────────
    $rr = qa_spawn($here.'/rr_snapshot.php', [(string) $fixture['scope_id']]);
    $rrJson = qa_last_json($rr['stdout']);

    $check('repeatable_read_snapshot_vs_current', ($rrJson['ok'] ?? false) === true, [
        'exit'   => $rr['exit'],
        'detail' => $rrJson,
        'stderr' => trim($rr['stderr']),
    ]);
} catch (Throwable $exception) {
    $check('harness_exception', false, [
        'class'   => $exception::class,
        'message' => $exception->getMessage(),
    ]);
} finally {
    if (is_array($fixture) && ($fixture['event_id'] ?? 0) > 0) {
        $cleanup = qa_spawn($here.'/cleanup.php', [
            (string) $fixture['event_id'],
            (string) ($fixture['marker'] ?? ''),
        ]);
        $cleanupJson = qa_last_json($cleanup['stdout']);
        $check('fixture_cleanup', ($cleanupJson['deleted'] ?? false) === true, [
            'exit'   => $cleanup['exit'],
            'detail' => $cleanupJson,
            'stderr' => trim($cleanup['stderr']),
        ]);
    }

    @unlink($barrier);
    @unlink($lockFlag);
}

$result['ok'] = $result['checks'] !== []
    && count(array_filter($result['checks'], fn ($item) => $item['passed'] === true)) === count($result['checks']);

if ($jsonPath !== null) {
    $directory = dirname($jsonPath);

    if (! is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

qa_json_pretty($result);
exit($result['ok'] ? 0 : 1);
