<?php

/**
 * Q-03 · 人工补偿探针：failed 可重排，dead 绝不被重排复活。
 *
 *   php compensation_probe.php <attempt_id> <registration_id> <event_id> <status> <row_version>
 *
 * 1. 写一条合法报名 outbox，置为 processing + 达到 max_attempts；
 * 2. `fail(retryable=true)` → failed（自动重试耗尽，等人工处置）；
 * 3. `OpsService::retryOutbox()` → pending / attempts=0（运营补偿）；
 * 4. 另一条 dead（不可重试事实）调用同一重排必须被拒绝，且状态保持 dead。
 *
 * runner 随后跑真实 worker：failed 行必须 sent，dead 行必须仍 dead。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\OpsService;
use Webkul\Shop\Services\Ecosystem\OutboxLeaseService;
use Webkul\Shop\Services\Ecosystem\OutboxWriter;

$attemptId = (int) ($argv[1] ?? 0);
$registrationId = (int) ($argv[2] ?? 0);
$eventId = (int) ($argv[3] ?? 0);
$status = (string) ($argv[4] ?? '');
$rowVersion = (int) ($argv[5] ?? 0);

if ($attemptId <= 0 || $registrationId <= 0 || $eventId <= 0 || $status === '') {
    fwrite(STDERR, "usage: compensation_probe.php <attempt_id> <registration_id> <event_id> <status> <row_version>\n");
    exit(1);
}

$writer = app(OutboxWriter::class);
$leases = app(OutboxLeaseService::class);
$ops = app(OpsService::class);

$payload = [
    'event_id'            => $eventId,
    'registration_id'     => $registrationId,
    'attempt_id'          => $attemptId,
    'status'              => $status,
    'attempt_row_version' => $rowVersion,
];

$failedRow = $writer->write('registration', $registrationId, 'registration.submitted', $payload, 'qa-q03-comp-failed-'.bin2hex(random_bytes(4)));
$deadRow = $writer->write('registration', $registrationId, 'registration.submitted', $payload, 'qa-q03-comp-dead-'.bin2hex(random_bytes(4)));

$owner = 'qa-compensation:'.getmypid();
$maxAttempts = (int) config('ecosystem_outbox.max_attempts', 5);

DB::table('ecosystem_outbox')->where('id', (int) $failedRow->id)->update([
    'status'       => 'processing',
    'attempts'     => $maxAttempts,
    'lease_owner'  => $owner,
    'lease_until'  => now('UTC')->addMinutes(5)->format('Y-m-d H:i:s'),
    'updated_at'   => now(),
]);

DB::table('ecosystem_outbox')->where('id', (int) $deadRow->id)->update([
    'status'      => 'dead',
    'last_error'  => 'dead:qa-terminal-fact',
    'updated_at'  => now(),
]);

$failedTarget = $leases->fail((int) $failedRow->id, $owner, $maxAttempts, 'qa injected transient', true);

$adminId = (int) (DB::table('admins')->orderBy('id')->value('id') ?: 1);
$ops->retryOutbox((int) $failedRow->id, $adminId);

$afterRetry = DB::table('ecosystem_outbox')->where('id', (int) $failedRow->id)->first();

$deadRetryRejected = false;

try {
    $ops->retryOutbox((int) $deadRow->id, $adminId);
} catch (RuntimeException) {
    $deadRetryRejected = true;
}

$deadAfter = DB::table('ecosystem_outbox')->where('id', (int) $deadRow->id)->first();

$result = [
    'failed_outbox_id'      => (int) $failedRow->id,
    'dead_outbox_id'        => (int) $deadRow->id,
    'fail_target'           => $failedTarget,
    'status_after_retry'    => (string) ($afterRetry->status ?? ''),
    'attempts_after_retry'  => (int) ($afterRetry->attempts ?? -1),
    'dead_retry_rejected'   => $deadRetryRejected,
    'dead_status_after'     => (string) ($deadAfter->status ?? ''),
    'ok'                    => $failedTarget === 'failed'
        && (string) ($afterRetry->status ?? '') === 'pending'
        && (int) ($afterRetry->attempts ?? -1) === 0
        && $deadRetryRejected
        && (string) ($deadAfter->status ?? '') === 'dead',
];

qa_json($result);
exit($result['ok'] ? 0 : 1);
