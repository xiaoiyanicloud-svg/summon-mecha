<?php

/**
 * Q-02 · 真实两进程行锁探针。
 *
 *   php lock_probe.php holder <event_id> <scope_id> <flag> [hold_seconds=3]
 *   php lock_probe.php waiter <event_id> <scope_id> <flag>
 *
 * holder 在事务内 `SELECT ... FOR UPDATE` 锁住 event + scope，写 flag 后睡 N 秒；
 * waiter 看到 flag 后对同一行执行 `SELECT ... FOR UPDATE`，测量真实阻塞时间。
 *
 * 这不是同一进程里的“模拟锁”：两条连接来自两个独立 PHP 进程，
 * 等待时间由 InnoDB 给出。waiter 的 waited_ms 必须 >= 1000 才算通过。
 */

require __DIR__.'/../bootstrap.php';

$role = (string) ($argv[1] ?? '');
$eventId = (int) ($argv[2] ?? 0);
$scopeId = (int) ($argv[3] ?? 0);
$flag = (string) ($argv[4] ?? '');
$holdSeconds = (int) ($argv[5] ?? 3);

if (! in_array($role, ['holder', 'waiter'], true) || $eventId <= 0 || $scopeId <= 0 || $flag === '') {
    fwrite(STDERR, "usage: lock_probe.php holder|waiter <event_id> <scope_id> <flag> [hold_seconds]\n");
    exit(1);
}

$config = config('database.connections.mysql');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);

$pdo = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 15');

if ($role === 'holder') {
    $pdo->beginTransaction();
    $pdo->query("SELECT id FROM events WHERE id = {$eventId} FOR UPDATE")->fetchAll();
    $pdo->query("SELECT id FROM event_registration_scopes WHERE id = {$scopeId} FOR UPDATE")->fetchAll();
    file_put_contents($flag, (string) getmypid());
    sleep(max(1, $holdSeconds));
    $pdo->commit();

    qa_json([
        'role'  => 'holder',
        'pid'   => getmypid(),
        'event' => $eventId,
        'scope' => $scopeId,
        'held_seconds' => $holdSeconds,
    ]);
    exit(0);
}

$deadline = microtime(true) + 20;

while (! file_exists($flag)) {
    if (microtime(true) > $deadline) {
        qa_json(['role' => 'waiter', 'error' => 'flag_timeout', 'blocked' => false]);
        exit(1);
    }

    usleep(1000);
}

$started = microtime(true);
$pdo->beginTransaction();
$pdo->query("SELECT id FROM event_registration_scopes WHERE id = {$scopeId} FOR UPDATE")->fetchAll();
$elapsedMs = (int) round((microtime(true) - $started) * 1000);
$pdo->commit();

qa_json([
    'role'      => 'waiter',
    'pid'       => getmypid(),
    'scope'     => $scopeId,
    'waited_ms' => $elapsedMs,
    'blocked'   => $elapsedMs >= 1000,
]);
