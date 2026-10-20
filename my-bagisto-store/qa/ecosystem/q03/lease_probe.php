<?php

/**
 * Q-03 · 租约恢复 / 旧 worker 拒绝探针。
 *
 *   php lease_probe.php <attempt_id> <registration_id> <event_id> <status> <row_version>
 *
 * 1. 写入一条合法报名 outbox；
 * 2. 人为把它置为 processing 且租约已过期（模拟 worker 崩溃）；
 * 3. recoverExpired() 必须把它恢复为 pending；
 * 4. 旧 owner 再 settle 必须抛 OutboxLeaseLostException，且不能改写成功状态。
 *
 * 输出的 id 由 runner 交给真实 worker 消费 —— 证明“崩溃后恢复”不是只改状态。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\OutboxLeaseLostException;
use Webkul\Shop\Services\Ecosystem\OutboxLeaseService;
use Webkul\Shop\Services\Ecosystem\OutboxWriter;

$attemptId = (int) ($argv[1] ?? 0);
$registrationId = (int) ($argv[2] ?? 0);
$eventId = (int) ($argv[3] ?? 0);
$status = (string) ($argv[4] ?? '');
$rowVersion = (int) ($argv[5] ?? 0);

if ($attemptId <= 0 || $registrationId <= 0 || $eventId <= 0 || $status === '') {
    fwrite(STDERR, "usage: lease_probe.php <attempt_id> <registration_id> <event_id> <status> <row_version>\n");
    exit(1);
}

$dedupe = 'qa-q03-lease-'.bin2hex(random_bytes(6));
$staleOwner = 'qa-stale:'.getmypid();

$row = app(OutboxWriter::class)->write('registration', $registrationId, 'registration.submitted', [
    'event_id'            => $eventId,
    'registration_id'     => $registrationId,
    'attempt_id'          => $attemptId,
    'status'              => $status,
    'attempt_row_version' => $rowVersion,
], $dedupe);

DB::table('ecosystem_outbox')->where('id', (int) $row->id)->update([
    'status'       => 'processing',
    'attempts'     => 1,
    'lease_owner'  => $staleOwner,
    'lease_until'  => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
    'available_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
    'updated_at'   => now(),
]);

$leases = app(OutboxLeaseService::class);
$recovered = $leases->recoverExpired();

$stateAfterRecovery = DB::table('ecosystem_outbox')->where('id', (int) $row->id)->first();

$leaseLost = false;

try {
    $leases->settle((int) $row->id, $staleOwner);
} catch (OutboxLeaseLostException) {
    $leaseLost = true;
}

$final = DB::table('ecosystem_outbox')->where('id', (int) $row->id)->first();

$result = [
    'outbox_id'            => (int) $row->id,
    'dedupe_key'           => $dedupe,
    'recovered'            => $recovered,
    'state_after_recovery' => (string) ($stateAfterRecovery->status ?? ''),
    'lease_owner_cleared'  => ($stateAfterRecovery->lease_owner ?? null) === null,
    'stale_settle_rejected'=> $leaseLost,
    'status_after_stale'   => (string) ($final->status ?? ''),
    'ok'                   => $recovered >= 1
        && (string) ($stateAfterRecovery->status ?? '') === 'pending'
        && ($stateAfterRecovery->lease_owner ?? null) === null
        && $leaseLost
        && (string) ($final->status ?? '') === 'pending',
];

qa_json($result);
exit($result['ok'] ? 0 : 1);
