<?php

/**
 * Q-02 · MySQL REPEATABLE READ 快照 vs 当前读探针。
 *
 *   php rr_snapshot.php <scope_id>
 *
 * 两条**独立连接**：
 *   A 开启 REPEATABLE READ 事务，先做普通 SELECT 建立快照；
 *   B 提交 UPDATE（关闭报名 + 容量归零 + 计数变化）；
 *   A 再普通 SELECT → 必须仍是旧快照；
 *   A 再 `SELECT ... FOR UPDATE` → 必须看到 B 的最新提交。
 *
 * 这证明报名服务“取得锁后重新读取当前状态”不是靠请求开始时的快照放行；
 * 若把代码里的 FOR UPDATE 改成普通 SELECT，本探针会直接失败。
 */

require __DIR__.'/../bootstrap.php';

$scopeId = (int) ($argv[1] ?? 0);

if ($scopeId <= 0) {
    fwrite(STDERR, "usage: rr_snapshot.php <scope_id>\n");
    exit(1);
}

$config = config('database.connections.mysql');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

$pdoA = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), $options);
$pdoB = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), $options);

$isolation = null;

foreach (['transaction_isolation', 'tx_isolation'] as $variable) {
    try {
        $isolation = (string) $pdoA->query("SELECT @@{$variable}")->fetchColumn();

        break;
    } catch (Throwable) {
        // MySQL 5.7 / 8.0 变量名不同，继续尝试。
    }
}

$select = "SELECT capacity, confirmed_count, registration_closes_at, status FROM event_registration_scopes WHERE id = {$scopeId}";

$pdoA->beginTransaction();
$snapshotBefore = $pdoA->query($select)->fetch(PDO::FETCH_ASSOC);

if ($snapshotBefore === false) {
    $pdoA->rollBack();
    qa_json(['ok' => false, 'error' => 'scope_not_found', 'scope_id' => $scopeId]);
    exit(1);
}

$pdoB->beginTransaction();
$update = $pdoB->prepare(
    'UPDATE event_registration_scopes
     SET capacity = 0,
         confirmed_count = confirmed_count + 7,
         registration_closes_at = :closed_at,
         status = :status
     WHERE id = :id'
);
$update->execute([
    'closed_at' => '2000-01-01 00:00:00',
    'status'    => 'closed',
    'id'        => $scopeId,
]);
$pdoB->commit();

$snapshotAfter = $pdoA->query($select)->fetch(PDO::FETCH_ASSOC);
$currentRead = $pdoA->query($select.' FOR UPDATE')->fetch(PDO::FETCH_ASSOC);

$pdoA->rollBack();

// 恢复夹具原值（runner 随后会整体清理，这里保证可重复执行）。
$restore = $pdoB->prepare(
    'UPDATE event_registration_scopes
     SET capacity = :capacity,
         confirmed_count = :confirmed_count,
         registration_closes_at = :closed_at,
         status = :status
     WHERE id = :id'
);
$restore->execute([
    'capacity'        => $snapshotBefore['capacity'],
    'confirmed_count' => $snapshotBefore['confirmed_count'],
    'closed_at'       => $snapshotBefore['registration_closes_at'],
    'status'          => $snapshotBefore['status'],
    'id'              => $scopeId,
]);

$result = [
    'isolation'        => $isolation,
    'snapshot_before'  => $snapshotBefore,
    'snapshot_after'   => $snapshotAfter,
    'current_read'     => $currentRead,
    'snapshot_held'    => (int) $snapshotAfter['capacity'] === (int) $snapshotBefore['capacity']
        && $snapshotAfter['registration_closes_at'] === $snapshotBefore['registration_closes_at'],
    'current_read_fresh' => (int) $currentRead['capacity'] === 0
        && (int) $currentRead['confirmed_count'] === (int) $snapshotBefore['confirmed_count'] + 7
        && (string) $currentRead['registration_closes_at'] === '2000-01-01 00:00:00',
];

$result['ok'] = $result['snapshot_held'] && $result['current_read_fresh'];

qa_json($result);
exit($result['ok'] ? 0 : 1);
