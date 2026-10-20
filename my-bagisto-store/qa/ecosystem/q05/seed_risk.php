<?php

/**
 * Q-05 · 制造一组“应该被 preflight 抓到”的风险数据（仅独立测试库）。
 *
 * 覆盖：scope 计数与事实不一致 / 超容量、同一报名多个活跃轮次、
 * 未知轮次状态、未知时区、起止时间颠倒、outbox 过期租约。
 *
 * 输出 JSON（ids），供 run.php 断言与清理。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$prepared = qa_spawn(__DIR__.'/../q02/prepare.php', ['3', 'auto']);
$fixture = qa_last_json($prepared['stdout']);

if ($fixture === null || ($fixture['event_id'] ?? 0) <= 0) {
    fwrite(STDERR, "q02 prepare failed: ".$prepared['stderr'].$prepared['stdout']);
    exit(1);
}

$eventId = (int) $fixture['event_id'];
$scopeId = (int) $fixture['scope_id'];
$customerA = (int) $fixture['customer_ids'][0];
$customerB = (int) $fixture['customer_ids'][1];
$now = qa_now();

$makeAttempt = static function (int $registrationId, int $customerId, int $attemptNo, string $status, string $requestKey) use ($eventId, $scopeId, $now): int {
    return (int) DB::table('event_registration_attempts')->insertGetId([
        'registration_id'      => $registrationId,
        'event_id'             => $eventId,
        'scope_id'             => $scopeId,
        'owner_customer_id'    => $customerId,
        'attempt_no'           => $attemptNo,
        'status'               => $status,
        'review_mode'          => 'auto',
        'form_version'         => 1,
        'form_snapshot'        => json_encode(['fields' => ['name', 'phone']]),
        'rules_version_id'     => null,
        'rules_fingerprint'    => null,
        'privacy_version'      => '2026-10-19',
        'privacy_fingerprint'  => str_repeat('a', 64),
        'consent_accepted_at'  => $now,
        'contact_name_enc'     => 'qa-cipher-name-'.bin2hex(random_bytes(4)),
        'contact_phone_enc'    => 'qa-cipher-phone-'.bin2hex(random_bytes(8)),
        'contact_phone_hash'   => hash('sha256', $requestKey),
        'contact_phone_last4'  => '0001',
        'contact_city'         => '上海',
        'note_enc'             => null,
        'request_key'          => $requestKey,
        'submitted_at'         => $now,
        'row_version'          => 0,
        'created_at'           => $now,
        'updated_at'           => $now,
    ]);
};

$makeRegistration = static function (int $customerId, string $subject, string $publicNo) use ($eventId, $scopeId, $now): int {
    return (int) DB::table('event_registrations')->insertGetId([
        'event_id'           => $eventId,
        'customer_id'        => $customerId,
        'name'               => 'Q05 数据演练',
        'phone'              => '13800000000',
        'city'               => '上海',
        'note'               => null,
        'scope_id'           => $scopeId,
        'team_id'            => null,
        'subject_key'        => $subject,
        'public_no'          => $publicNo,
        'current_attempt_id' => null,
        'identity_kind'      => 'customer',
        'row_version'        => 0,
        'created_at'         => $now,
        'updated_at'         => $now,
    ]);
};

$registrationA = $makeRegistration($customerA, 'c:'.$customerA, 'QAEV'.strtoupper(bin2hex(random_bytes(6))));
$attemptA1 = $makeAttempt($registrationA, $customerA, 1, 'confirmed', 'q05-a1-'.bin2hex(random_bytes(4)));
$attemptA2 = $makeAttempt($registrationA, $customerA, 2, 'confirmed', 'q05-a2-'.bin2hex(random_bytes(4)));
DB::table('event_registrations')->where('id', $registrationA)->update(['current_attempt_id' => $attemptA1]);

$registrationB = $makeRegistration($customerB, 'c:'.$customerB, 'QAEV'.strtoupper(bin2hex(random_bytes(6))));
$attemptB1 = $makeAttempt($registrationB, $customerB, 1, 'legacy_weird', 'q05-b1-'.bin2hex(random_bytes(4)));
DB::table('event_registrations')->where('id', $registrationB)->update(['current_attempt_id' => $attemptB1]);

// scope 计数与事实不一致，并超过容量。
DB::table('event_registration_scopes')->where('id', $scopeId)->update([
    'confirmed_count' => 5,
    'updated_at'      => $now,
]);

// 未知时区 + revision 起止时间颠倒。
DB::table('events')->where('id', $eventId)->update([
    'timezone'   => 'Mars/Base',
    'updated_at' => $now,
]);
DB::table('event_revisions')->where('event_id', $eventId)->update([
    'timezone'   => 'Mars/Base',
    'starts_at'  => now('UTC')->addDays(2)->format('Y-m-d H:i:s'),
    'ends_at'    => now('UTC')->addDay()->format('Y-m-d H:i:s'),
    'updated_at' => $now,
]);

// outbox 过期租约（模拟 worker 崩溃）。
$outboxId = (int) DB::table('ecosystem_outbox')->insertGetId([
    'aggregate_type' => 'registration',
    'aggregate_id'   => $registrationA,
    'event_type'     => 'registration.submitted',
    'payload'        => json_encode([
        'event_id'        => $eventId,
        'registration_id' => $registrationA,
        'attempt_id'      => $attemptA1,
        'status'          => 'confirmed',
        'attempt_row_version' => 0,
    ]),
    'dedupe_key'     => 'q05-expired-lease-'.bin2hex(random_bytes(6)),
    'status'         => 'processing',
    'attempts'       => 1,
    'available_at'   => now('UTC')->subHour()->format('Y-m-d H:i:s'),
    'lease_owner'    => 'qa-q05-crashed-worker',
    'lease_until'    => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
    'last_error'     => null,
    'created_at'     => $now,
    'updated_at'     => $now,
]);

$fixture['registration_a'] = $registrationA;
$fixture['registration_b'] = $registrationB;
$fixture['attempt_a1'] = $attemptA1;
$fixture['attempt_a2'] = $attemptA2;
$fixture['attempt_b1'] = $attemptB1;
$fixture['outbox_id'] = $outboxId;

qa_json($fixture);
