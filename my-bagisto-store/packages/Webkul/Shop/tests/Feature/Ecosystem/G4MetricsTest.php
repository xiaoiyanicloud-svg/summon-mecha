<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Services\Ecosystem\ContentViewRecorder;
use Webkul\Shop\Services\Ecosystem\MetricsService;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G4-06 · 指标口径与访问记录。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G4-06 tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');
    Cache::flush();

    config([
        'ecosystem_g4.metrics.enabled'                 => true,
        'ecosystem_g4.metrics.content_views_enabled'   => true,
        'ecosystem_g4.metrics.view_dedupe_seconds'     => 3600,
    ]);
});

function g4MetricAttempt(int $eventId, array $overrides = []): int
{
    $vault = app(RegistrationContactVault::class);
    $contact = $vault->buildContact('张三', '13800138000', '上海');

    $scopeId = (int) DB::table('event_registration_scopes')
        ->where('event_id', $eventId)
        ->where('scope_key', 'default')
        ->value('id');

    if (! $scopeId) {
        $scopeId = (int) DB::table('event_registration_scopes')->insertGetId([
            'event_id'               => $eventId,
            'scope_key'              => 'default',
            'unit'                   => 'person',
            'capacity'               => 10,
            'capacity_confirmed_at'  => '2026-10-19 00:00:00',
            'confirmed_count'        => 1,
            'review_mode'            => 'auto',
            'status'                 => 'open',
            'policy_confirmed_at'    => '2026-10-19 00:00:00',
            'row_version'            => 0,
            'created_at'             => '2026-10-19 00:00:00',
            'updated_at'             => '2026-10-19 00:00:00',
        ]);
    }

    $registrationId = (int) DB::table('event_registrations')->insertGetId([
        'event_id'     => $eventId,
        'scope_id'     => $scopeId,
        'subject_key'  => 'c:'.random_int(100000, 999999),
        'public_no'    => 'G4-'.bin2hex(random_bytes(6)),
        'identity_kind'=> 'customer',
        'row_version'  => 0,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $id = (int) DB::table('event_registration_attempts')->insertGetId(array_merge([
        'registration_id'       => $registrationId,
        'event_id'              => $eventId,
        'scope_id'              => $scopeId,
        'owner_customer_id'     => 1,
        'attempt_no'            => 1,
        'status'                => 'confirmed',
        'review_mode'           => 'auto',
        'form_version'          => 1,
        'privacy_version'       => 'p1',
        'privacy_fingerprint'   => str_repeat('a', 64),
        'consent_accepted_at'   => now('UTC')->format('Y-m-d H:i:s'),
        'contact_name_enc'      => $contact['contact_name_enc'],
        'contact_phone_enc'     => $contact['contact_phone_enc'],
        'contact_phone_hash'    => $contact['contact_phone_hash'],
        'contact_phone_last4'   => $contact['contact_phone_last4'],
        'contact_city'          => '上海',
        'request_key'           => 'g4-'.bin2hex(random_bytes(6)),
        'submitted_at'          => now('UTC')->format('Y-m-d H:i:s'),
        'decided_at'            => now('UTC')->format('Y-m-d H:i:s'),
        'row_version'           => 0,
        'created_at'            => now(),
        'updated_at'            => now(),
    ], $overrides));

    DB::table('event_registrations')->where('id', $registrationId)->update([
        'current_attempt_id' => $id,
    ]);

    return $id;
}

it('G4-06 · 访问记录按访客去重并落到日聚合表', function () {
    $event = Fixtures::event();
    $recorder = app(ContentViewRecorder::class);

    $recorder->record(ContentViewRecorder::TARGET_EVENT, (string) $event['id']);
    $recorder->record(ContentViewRecorder::TARGET_EVENT, (string) $event['id']);

    $row = DB::table('ecosystem_content_view_daily')
        ->where('target_type', 'event')
        ->where('target_key', (string) $event['id'])
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->views)->toBe(1);
    expect(DB::table('ecosystem_content_view_daily')->get())->toHaveCount(1);
    // 不落 IP / UA / 用户 ID：表里只有日期、对象、次数。
    expect(DB::select('SHOW COLUMNS FROM ecosystem_content_view_daily'))->toHaveCount(7);

    Cache::flush();
    $recorder->record(ContentViewRecorder::TARGET_EVENT, (string) $event['id']);
    expect((int) DB::table('ecosystem_content_view_daily')->value('views'))->toBe(2);
});

it('G4-06 · 访问记录关闭时不写库，指标页明确不可用', function () {
    $event = Fixtures::event();
    config(['ecosystem_g4.metrics.content_views_enabled' => false]);

    app(ContentViewRecorder::class)->record(ContentViewRecorder::TARGET_EVENT, (string) $event['id']);

    expect(DB::table('ecosystem_content_view_daily')->count())->toBe(0);

    $data = app(MetricsService::class)->build();
    expect($data['content']['available'])->toBeFalse();
    expect($data['content']['reason'])->toBe('content_views_disabled');
});

it('G4-06 · 公开详情成功渲染才记访问，404 不写，重复请求去重', function () {
    config([
        'ecosystem.events.public_enabled'          => true,
        'ecosystem_public_read.emergency_disabled' => false,
    ]);

    $event = Fixtures::event();

    getJson("/api/events/{$event['id']}")->assertOk();
    getJson("/api/events/{$event['id']}")->assertOk();
    getJson('/api/events/999999999')->assertNotFound();

    expect(DB::table('ecosystem_content_view_daily')->count())->toBe(1);
    expect((int) DB::table('ecosystem_content_view_daily')->value('views'))->toBe(1);
});

it('G4-06 · 报名指标按 attempt 计，取消/重报不混算', function () {
    $event = Fixtures::event();
    $first = g4MetricAttempt($event['id']);

    g4MetricAttempt($event['id'], [
        'attempt_no'  => 2,
        'status'      => 'canceled',
        'canceled_at' => now('UTC')->format('Y-m-d H:i:s'),
        'decided_at'  => null,
    ]);

    // 已取消的旧 attempt 不应被算成当前席位。
    DB::table('event_registration_attempts')->where('id', $first)->update(['status' => 'canceled', 'canceled_at' => now('UTC')]);

    $data = app(MetricsService::class)->build();

    expect($data['registrations']['available'])->toBeTrue();
    expect($data['registrations']['submitted'])->toBe(2);
    expect($data['registrations']['confirmed_seats'])->toBe(0);
    expect($data['registrations']['canceled'])->toBe(2);
    expect($data['registrations']['reapplications'])->toBe(1);
    expect($data['registrations']['by_event'][0]['event_id'])->toBe($event['id']);
});
