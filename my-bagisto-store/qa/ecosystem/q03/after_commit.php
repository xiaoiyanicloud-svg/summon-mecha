<?php

/**
 * Q-03 · afterCommit / rollback 可见性探针（真实第二连接观察）。
 *
 * 事务内写 outbox：
 *   · 未提交时，另一条 MySQL 连接看不到；
 *   · 提交后看得到；
 *   · 回滚后不留下任何事实。
 *
 * 探针行使用独立 dedupe 前缀并在 finally 中删除，不会被 worker 消费。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\OutboxWriter;

$prefix = 'qa-q03-tx-'.bin2hex(random_bytes(6));
$config = config('database.connections.mysql');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);

$observer = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$count = static fn (): int => (int) $observer
    ->query("SELECT COUNT(*) FROM ecosystem_outbox WHERE dedupe_key LIKE '".$prefix."%'")
    ->fetchColumn();

$writer = app(OutboxWriter::class);
$result = ['prefix' => $prefix, 'ok' => false];

try {
    DB::beginTransaction();
    $writer->write('registration', 900001, 'registration.submitted', [
        'event_id' => 1, 'registration_id' => 900001, 'attempt_id' => 900001,
        'status' => 'submitted', 'attempt_row_version' => 0,
    ], $prefix.'-commit');
    $result['before_commit'] = $count();
    DB::commit();
    $result['after_commit'] = $count();

    DB::beginTransaction();
    $writer->write('registration', 900002, 'registration.submitted', [
        'event_id' => 1, 'registration_id' => 900002, 'attempt_id' => 900002,
        'status' => 'submitted', 'attempt_row_version' => 0,
    ], $prefix.'-rollback');
    $result['visible_during_tx'] = $count();
    DB::rollBack();
    $result['after_rollback'] = $count();

    $result['ok'] = $result['before_commit'] === 0
        && $result['after_commit'] === 1
        && $result['visible_during_tx'] === 1
        && $result['after_rollback'] === 1;
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    $result['error'] = $exception::class.': '.$exception->getMessage();
} finally {
    DB::table('ecosystem_outbox')->where('dedupe_key', 'like', $prefix.'%')->delete();
}

qa_json($result);
exit($result['ok'] ? 0 : 1);
