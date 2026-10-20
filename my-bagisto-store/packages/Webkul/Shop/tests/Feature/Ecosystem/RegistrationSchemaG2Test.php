<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2-A · schema 与历史数据隔离。
 *
 * 验证：
 *   · 9 个增量迁移的表/列/唯一约束存在；
 *   · event_registrations.event_id 已统一为 BIGINT；
 *   · 历史行 identity_kind=legacy 且 scope/subject 为空；
 *   · 新行在 uk(scope_id, subject_key) 下唯一，但历史 NULL 行互不冲突。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }
});

it('G2-A · 报名新增表与关键列存在', function () {
    $tables = [
        'event_registration_scopes',
        'event_registration_attempts',
        'event_registration_requests',
        'event_staff_assignments',
        'event_checkin_tokens',
        'event_changes',
        'event_change_acknowledgments',
        'ecosystem_export_files',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    expect(Schema::hasColumns('event_registrations', [
        'scope_id', 'subject_key', 'public_no', 'current_attempt_id', 'identity_kind', 'row_version',
    ]))->toBeTrue();

    expect(Schema::hasColumns('event_registration_attempts', [
        'registration_id', 'event_id', 'scope_id', 'owner_customer_id', 'attempt_no', 'status',
        'contact_name_enc', 'contact_phone_enc', 'contact_phone_hash', 'contact_phone_last4',
        'privacy_version', 'privacy_fingerprint', 'request_key', 'row_version',
    ]))->toBeTrue();

    expect(Schema::hasColumn('notification', 'dedupe_key'))->toBeTrue();
});

it('G2-A · event_registrations.event_id 已统一为 BIGINT，历史行不被认领', function () {
    $type = (string) DB::selectOne("SHOW COLUMNS FROM `event_registrations` LIKE 'event_id'")->Type;

    expect(strtolower($type))->toContain('bigint');

    $legacy = DB::table('event_registrations')
        ->whereNull('scope_id')
        ->get(['identity_kind', 'subject_key', 'current_attempt_id']);

    foreach ($legacy as $row) {
        expect($row->identity_kind)->toBe('legacy');
        expect($row->subject_key)->toBeNull();
        expect($row->current_attempt_id)->toBeNull();
    }
});

it('G2-A · 同一 scope 的 subject_key 唯一；历史 NULL 行可共存', function () {
    $event = Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures::event();
    $scopeId = Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures::scope($event['id']);

    $insert = fn (string $subject): int => (int) DB::table('event_registrations')->insertGetId([
        'event_id'      => $event['id'],
        'scope_id'      => $scopeId,
        'subject_key'   => $subject,
        'identity_kind' => 'customer',
        'public_no'     => 'EV'.bin2hex(random_bytes(6)),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $insert('c:1');

    expect(fn () => $insert('c:1'))->toThrow(Illuminate\Database\QueryException::class);

    // 历史行：两列 NULL，唯一索引不冲突。
    DB::table('event_registrations')->insert([
        ['event_id' => $event['id'], 'name' => '旧1', 'phone' => '1', 'scope_id' => null, 'subject_key' => null, 'identity_kind' => 'legacy', 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $event['id'], 'name' => '旧2', 'phone' => '2', 'scope_id' => null, 'subject_key' => null, 'identity_kind' => 'legacy', 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(DB::table('event_registrations')->whereNull('scope_id')->count())->toBe(2);
});
