<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;

/**
 * Q-05 · 数据 preflight 必须“只看不写”，并把风险逐项报出来。
 *
 * 这里用最小夹具制造四类问题：
 *   · scope 计数与事实不一致且超过容量；
 *   · 同一报名两个活跃轮次；
 *   · 未知轮次状态；
 *   · 未知时区。
 *
 * 断言：命令非零退出、JSON 含对应代码、数据库行数/计数完全未变。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('Q-05 tests require the dedicated summon_mecha_step10_test database.');
    }
});

function q5Attempt(int $registrationId, int $eventId, int $scopeId, int $customerId, int $attemptNo, string $status): int
{
    $now = now('UTC')->format('Y-m-d H:i:s');

    return (int) DB::table('event_registration_attempts')->insertGetId([
        'registration_id'     => $registrationId,
        'event_id'            => $eventId,
        'scope_id'            => $scopeId,
        'owner_customer_id'   => $customerId,
        'attempt_no'          => $attemptNo,
        'status'              => $status,
        'review_mode'         => 'auto',
        'form_version'        => 1,
        'form_snapshot'       => json_encode(['fields' => ['name']]),
        'privacy_version'     => '2026-10-19',
        'privacy_fingerprint' => str_repeat('b', 64),
        'consent_accepted_at' => $now,
        'contact_name_enc'    => 'qa-name-'.bin2hex(random_bytes(4)),
        'contact_phone_enc'   => 'qa-phone-'.bin2hex(random_bytes(4)),
        'contact_phone_hash'  => hash('sha256', (string) $registrationId.$attemptNo),
        'contact_phone_last4' => '0001',
        'request_key'         => 'q5-'.$registrationId.'-'.$attemptNo,
        'submitted_at'        => $now,
        'row_version'         => 0,
        'created_at'          => $now,
        'updated_at'          => $now,
    ]);
}

it('Q-05 · preflight 报出风险且不写库', function () {
    $event = Fixtures::event(['timezone' => 'Mars/Base']);
    $scopeId = Fixtures::scope($event['id'], [
        'capacity'        => 1,
        'confirmed_count' => 5,
        'status'          => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'policy_confirmed_by' => 1,
    ]);

    $now = now('UTC')->format('Y-m-d H:i:s');

    $registrationId = (int) DB::table('event_registrations')->insertGetId([
        'event_id'           => $event['id'],
        'customer_id'        => null,
        'name'               => 'Q5 测试',
        'phone'              => '13800000000',
        'city'               => '上海',
        'note'               => null,
        'scope_id'           => $scopeId,
        'team_id'            => null,
        'subject_key'        => 'q5-subject',
        'public_no'          => 'QAEV'.strtoupper(bin2hex(random_bytes(6))),
        'current_attempt_id' => null,
        'identity_kind'      => 'customer',
        'row_version'        => 0,
        'created_at'         => $now,
        'updated_at'         => $now,
    ]);

    q5Attempt($registrationId, $event['id'], $scopeId, 1, 1, 'confirmed');
    q5Attempt($registrationId, $event['id'], $scopeId, 1, 2, 'confirmed');
    q5Attempt($registrationId, $event['id'], $scopeId, 1, 3, 'legacy_weird');

    $before = [
        'events'        => DB::table('events')->count(),
        'registrations' => DB::table('event_registrations')->count(),
        'attempts'      => DB::table('event_registration_attempts')->count(),
        'scopes'        => DB::table('event_registration_scopes')->count(),
        'confirmed'     => DB::table('event_registration_scopes')->where('id', $scopeId)->value('confirmed_count'),
    ];

    $path = base_path('storage/q5-test-preflight.json');

    try {
        $this->artisan('ecosystem:data-preflight', ['--strict' => true, '--json' => $path, '--sample' => 3])
            ->assertExitCode(1);

        $document = json_decode((string) file_get_contents($path), true);
        $codes = array_column($document['findings'], 'code');

        expect($codes)->toContain('scope_counter_mismatch')
            ->and($codes)->toContain('scope_counter_exceeds_capacity')
            ->and($codes)->toContain('duplicate_active_attempts')
            ->and($codes)->toContain('attempt_status_unknown')
            ->and($codes)->toContain('event_timezone_invalid');

        expect(json_encode($document, JSON_UNESCAPED_UNICODE))->not->toContain('13800000000');

        $after = [
            'events'        => DB::table('events')->count(),
            'registrations' => DB::table('event_registrations')->count(),
            'attempts'      => DB::table('event_registration_attempts')->count(),
            'scopes'        => DB::table('event_registration_scopes')->count(),
            'confirmed'     => DB::table('event_registration_scopes')->where('id', $scopeId)->value('confirmed_count'),
        ];

        expect($after)->toBe($before);
    } finally {
        @unlink($path);
    }
});

it('Q-05 · 干净的测试库 preflight 通过', function () {
    $this->artisan('ecosystem:data-preflight', ['--strict' => true])
        ->assertExitCode(0);
});
