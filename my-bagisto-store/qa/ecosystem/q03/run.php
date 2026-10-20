<?php

/**
 * Q-03 · Outbox / worker / 通知补偿演练总控。
 *
 *   DB_DATABASE=summon_mecha_step10_test APP_ENV=testing \
 *     php qa/ecosystem/q03/run.php [--json=D:/main/docs/ecosystem/q03-result.json]
 *
 * 覆盖：
 *   1. 专用库硬校验（root 库被拒绝）；
 *   2. afterCommit 可见性 / 回滚无残留（真实第二连接）；
 *   3. 真实 worker 进程消费报名事件并生成站内信；
 *   4. 重复投递：同一事实重放不重复发信；
 *   5. 崩溃/租约：过期租约被恢复，旧 worker 结算被拒绝；
 *   6. 积压：25 条 pending 跨两个批次清空；
 *   7. 补偿：failed 可人工重排后送达，dead 绝不被重排复活。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$jsonPath = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--json=')) {
        $jsonPath = substr($argument, 7);
    }
}

$result = [
    'q'            => 'Q-03',
    'generated_at' => gmdate('c'),
    'database'     => (string) config('database.connections.mysql.database'),
    'app_env'      => app()->environment(),
    'checks'       => [],
    'ok'           => false,
];

$check = static function (string $name, bool $passed, array $detail = []) use (&$result): void {
    $result['checks'][] = ['name' => $name, 'passed' => $passed] + $detail;
};

$notificationsFor = static fn (int $customerId): int => (int) DB::table('notification')
    ->where('recipient_type', 'customer')
    ->where('recipient_id', $customerId)
    ->count();

$outboxStatus = static fn (int $id): string => (string) DB::table('ecosystem_outbox')->where('id', $id)->value('status');

$fixture = null;

try {
    // ── 0. 专用库硬校验 ───────────────────────────────────────────────
    $guarded = qa_spawn(__DIR__.'/../q02/guard_probe.php', [], [
        'APP_ENV'     => 'local',
        'DB_DATABASE' => 'root',
    ]);
    $check('guard_rejects_root', $guarded['exit'] === 2, ['exit' => $guarded['exit']]);

    // ── 1. 夹具 + 真实报名（产生 submitted/confirmed outbox）───────────
    $prepared = qa_spawn(__DIR__.'/prepare.php');
    $fixture = qa_last_json($prepared['stdout']);

    if ($fixture === null || ($fixture['registration_id'] ?? 0) <= 0) {
        throw new RuntimeException('q03 prepare failed: '.$prepared['stderr'].$prepared['stdout']);
    }

    $customerId = (int) $fixture['customer_ids'][0];

    // ── 2. afterCommit / rollback ────────────────────────────────────
    $afterCommit = qa_spawn(__DIR__.'/after_commit.php');
    $afterCommitJson = qa_last_json($afterCommit['stdout']);
    $check('after_commit_visibility', ($afterCommitJson['ok'] ?? false) === true, [
        'detail' => $afterCommitJson,
        'stderr' => trim($afterCommit['stderr']),
    ]);

    // ── 3. 真实 worker：消费 submitted + confirmed ───────────────────
    $dryRun = qa_spawn(__DIR__.'/worker.php', ['20', '--dry-run']);
    $dryRunJson = qa_last_json($dryRun['stdout']);
    $check('worker_dry_run', ($dryRunJson['ok'] ?? false) === true
        && str_contains((string) ($dryRunJson['output'] ?? ''), 'DRY-RUN'), [
        'detail' => $dryRunJson,
    ]);

    $round1 = qa_spawn(__DIR__.'/worker.php', ['20']);
    $round1Json = qa_last_json($round1['stdout']);

    $seedIds = array_map('intval', (array) ($fixture['outbox_ids'] ?? []));
    $seedSent = $seedIds === [] ? 0 : (int) DB::table('ecosystem_outbox')
        ->whereIn('id', $seedIds)
        ->where('status', 'sent')
        ->count();
    $notificationCount = $notificationsFor($customerId);

    $check('real_worker_consumes_seed', ($round1Json['ok'] ?? false) === true
        && $seedSent === count($seedIds)
        && $seedIds !== [], [
        'detail'        => $round1Json,
        'seed_ids'      => $seedIds,
        'seed_sent'     => $seedSent,
        'notifications' => $notificationCount,
    ]);
    $check('seed_notifications_created', $notificationCount === count($seedIds), [
        'notifications' => $notificationCount,
        'expected'      => count($seedIds),
    ]);

    // ── 4. 重复投递不重复发信 ─────────────────────────────────────────
    $confirmedId = (int) DB::table('ecosystem_outbox')
        ->whereIn('id', $seedIds)
        ->where('event_type', 'registration.confirmed')
        ->value('id');

    if ($confirmedId <= 0) {
        throw new RuntimeException('confirmed outbox row missing in fixture');
    }

    $beforeDuplicate = $notificationsFor($customerId);
    $duplicate = qa_spawn(__DIR__.'/duplicate_probe.php', [(string) $confirmedId]);
    $duplicateJson = qa_last_json($duplicate['stdout']);
    $round2 = qa_spawn(__DIR__.'/worker.php', ['20']);
    $round2Json = qa_last_json($round2['stdout']);
    $afterDuplicate = $notificationsFor($customerId);

    $check('duplicate_delivery_no_duplicate_notification', ($duplicateJson['reset'] ?? false) === true
        && $outboxStatus($confirmedId) === 'sent'
        && $afterDuplicate === $beforeDuplicate, [
        'detail'         => $duplicateJson,
        'worker'         => $round2Json,
        'before'         => $beforeDuplicate,
        'after'          => $afterDuplicate,
        'row_status'     => $outboxStatus($confirmedId),
    ]);

    // ── 5. 崩溃 / 过期租约 / 旧 worker 拒绝 ───────────────────────────
    $beforeLease = $notificationsFor($customerId);
    $lease = qa_spawn(__DIR__.'/lease_probe.php', [
        (string) $fixture['attempt_id'],
        (string) $fixture['registration_id'],
        (string) $fixture['event_id'],
        (string) $fixture['attempt_status'],
        (string) $fixture['attempt_row_version'],
    ]);
    $leaseJson = qa_last_json($lease['stdout']);

    $round3 = qa_spawn(__DIR__.'/worker.php', ['20']);
    $round3Json = qa_last_json($round3['stdout']);
    $afterLease = $notificationsFor($customerId);
    $leaseId = (int) ($leaseJson['outbox_id'] ?? 0);

    $check('lease_recovery_and_stale_rejection', ($leaseJson['ok'] ?? false) === true
        && $leaseId > 0
        && $outboxStatus($leaseId) === 'sent'
        && $afterLease === $beforeLease + 1, [
        'detail'        => $leaseJson,
        'worker'        => $round3Json,
        'row_status'    => $leaseId > 0 ? $outboxStatus($leaseId) : null,
        'notifications' => ['before' => $beforeLease, 'after' => $afterLease],
    ]);

    // ── 6. 积压：25 条跨两批 ──────────────────────────────────────────
    $beforeBacklog = $notificationsFor($customerId);
    $backlog = qa_spawn(__DIR__.'/backlog.php', [
        (string) $fixture['attempt_id'],
        (string) $fixture['registration_id'],
        (string) $fixture['event_id'],
        (string) $fixture['attempt_status'],
        (string) $fixture['attempt_row_version'],
        '25',
    ]);
    $backlogJson = qa_last_json($backlog['stdout']);
    $backlogIds = array_map('intval', (array) ($backlogJson['ids'] ?? []));

    $batchA = qa_spawn(__DIR__.'/worker.php', ['20']);
    $batchAJson = qa_last_json($batchA['stdout']);
    $batchB = qa_spawn(__DIR__.'/worker.php', ['20']);
    $batchBJson = qa_last_json($batchB['stdout']);

    $backlogSent = $backlogIds === [] ? 0 : (int) DB::table('ecosystem_outbox')
        ->whereIn('id', $backlogIds)
        ->where('status', 'sent')
        ->count();
    $afterBacklog = $notificationsFor($customerId);

    $check('backlog_drained_across_batches', count($backlogIds) === 25
        && $backlogSent === 25
        && $afterBacklog === $beforeBacklog + 25, [
        'inserted'      => count($backlogIds),
        'sent'          => $backlogSent,
        'batch_a'       => $batchAJson,
        'batch_b'       => $batchBJson,
        'notifications' => ['before' => $beforeBacklog, 'after' => $afterBacklog],
    ]);

    // ── 7. 补偿：failed 重排送达，dead 不复活 ─────────────────────────
    $beforeComp = $notificationsFor($customerId);
    $compensation = qa_spawn(__DIR__.'/compensation_probe.php', [
        (string) $fixture['attempt_id'],
        (string) $fixture['registration_id'],
        (string) $fixture['event_id'],
        (string) $fixture['attempt_status'],
        (string) $fixture['attempt_row_version'],
    ]);
    $compensationJson = qa_last_json($compensation['stdout']);

    $round4 = qa_spawn(__DIR__.'/worker.php', ['20']);
    $round4Json = qa_last_json($round4['stdout']);

    $failedId = (int) ($compensationJson['failed_outbox_id'] ?? 0);
    $deadId = (int) ($compensationJson['dead_outbox_id'] ?? 0);
    $afterComp = $notificationsFor($customerId);

    $check('compensation_failed_retry_and_dead_terminal', ($compensationJson['ok'] ?? false) === true
        && $failedId > 0 && $deadId > 0
        && $outboxStatus($failedId) === 'sent'
        && $outboxStatus($deadId) === 'dead'
        && $afterComp === $beforeComp + 1, [
        'detail'        => $compensationJson,
        'worker'        => $round4Json,
        'failed_status' => $failedId > 0 ? $outboxStatus($failedId) : null,
        'dead_status'   => $deadId > 0 ? $outboxStatus($deadId) : null,
        'notifications' => ['before' => $beforeComp, 'after' => $afterComp],
    ]);
} catch (Throwable $exception) {
    $check('harness_exception', false, [
        'class'   => $exception::class,
        'message' => $exception->getMessage(),
    ]);
} finally {
    if (is_array($fixture) && ($fixture['event_id'] ?? 0) > 0) {
        $cleanup = qa_spawn(__DIR__.'/../q02/cleanup.php', [
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
