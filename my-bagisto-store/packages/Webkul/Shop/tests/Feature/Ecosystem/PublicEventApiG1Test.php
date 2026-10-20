<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G1-10 · 公开活动 API（详情 DTO、统一 404、撤稿 410、kill switch、域开关、报名状态）。
 *
 * 运行必须指向独立库 summon_mecha_step10_test；所有用例在 DatabaseTransactions 内。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-10 public API tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'               => true,
        'ecosystem.content.public_enabled'              => false,
        'ecosystem_public_read.emergency_disabled'      => false,
        'ecosystem.events.registration_enabled'         => false,
        'ecosystem.events.registration_write_verified'  => false,
    ]);
});

it('G1-10 · 已发布活动详情返回白名单 DTO 与受控媒体 URL', function () {
    $media = Fixtures::media('event-image-bytes', 'image/png');
    $event = Fixtures::event([], [
        'detail_html' => '<p>公开正文</p><img data-media-id="'.$media['id'].'" alt="现场图">',
    ]);
    Fixtures::mediaRef('event_revision', $event['revision_id'], $media['id'], 'cover');

    $response = getJson("/api/events/{$event['id']}")->assertOk();

    $response
        ->assertJsonPath('data.id', $event['id'])
        ->assertJsonPath('data.kind', 'salon')
        ->assertJsonPath('data.name', '公开活动名称')
        ->assertJsonPath('data.summary', '公开活动摘要')
        ->assertJsonPath('data.organizer.name', '召唤机甲')
        ->assertJsonPath('data.organizer.platform_role', 'organizer')
        ->assertJsonPath('data.source.name', '召唤机甲官方')
        ->assertJsonPath('data.source.url', 'https://example.com/events/1')
        ->assertJsonPath('data.participation_mode', 'offline')
        ->assertJsonPath('data.city', '上海')
        ->assertJsonPath('data.location_text', '张江科学会堂')
        ->assertJsonPath('data.timezone', 'Asia/Shanghai')
        ->assertJsonPath('data.starts_at', '2026-11-01T09:00:00+08:00')
        ->assertJsonPath('data.ends_at', '2026-11-01T18:00:00+08:00')
        ->assertJsonPath('data.published_at', '2026-10-19T12:00:00+08:00')
        ->assertJsonPath('data.first_published_at', '2026-10-19T12:00:00+08:00')
        ->assertJsonPath('data.revision_no', 1)
        ->assertJsonPath('data.hosting_status', 'scheduled')
        ->assertJsonPath('data.media.0.id', $media['id'])
        ->assertJsonPath('data.media.0.role', 'cover')
        ->assertJsonPath('data.media.0.url', "/api/events/{$event['id']}/media/{$media['id']}");

    $body = (string) $response->json('data.body_html');

    expect($body)->toContain('/api/events/'.$event['id'].'/media/'.$media['id']);
    expect($body)->toContain('data-media-id="'.$media['id'].'"');
    expect($body)->not->toContain('storage_key');
});

it('G1-10 · 不存在 / 草稿 / 待审 / 已批准 / 已排期 / 暂缓统一 404 且响应体一致', function () {
    $states = [
        ['publish_status' => 'draft', 'disposition' => 'normal'],
        ['publish_status' => 'in_review', 'disposition' => 'normal'],
        ['publish_status' => 'approved', 'disposition' => 'normal'],
        ['publish_status' => 'scheduled', 'disposition' => 'normal'],
        ['publish_status' => 'published', 'disposition' => 'withheld'],
    ];

    $payloads = [];

    foreach ($states as $override) {
        $event = Fixtures::event($override);

        $payloads[] = getJson("/api/events/{$event['id']}")
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', ApiErrorCode::EVENT_NOT_FOUND)
            ->json();
    }

    // published 指针指向未批准 revision：同样不可见。
    $unapproved = Fixtures::event([], ['review_state' => 'in_review']);
    $payloads[] = getJson("/api/events/{$unapproved['id']}")
        ->assertNotFound()
        ->json();

    $payloads[] = getJson('/api/events/999999999')->assertNotFound()->json();

    expect(array_unique(array_map('json_encode', $payloads)))->toHaveCount(1);
    expect($payloads[0]['message'])->toBe('赛事尚未公开发布或不存在。');
});

it('G1-10 · 已撤稿且曾发布返回 410 中性文案，不回显旧内容', function () {
    $event = Fixtures::event(['disposition' => 'retracted']);

    $response = getJson("/api/events/{$event['id']}")
        ->assertStatus(410)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', ApiErrorCode::EVENT_UNAVAILABLE);

    $raw = (string) $response->getContent();

    expect($raw)->not->toContain('公开活动名称');
    expect($raw)->not->toContain('公开活动摘要');
    expect($raw)->not->toContain('公开正文');
    expect($raw)->not->toContain('召唤机甲官方');
    expect($response->json('data'))->toBeNull();
});

it('G1-10 · 从未发布过的 retracted 仍按 404 处理', function () {
    $event = Fixtures::event([
        'disposition'        => 'retracted',
        'published_at'       => null,
        'first_published_at' => null,
    ]);

    getJson("/api/events/{$event['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::EVENT_NOT_FOUND);
});

it('G1-10 · kill switch 503 优先于域开关且不查业务表', function () {
    Fixtures::event();
    config([
        'ecosystem_public_read.emergency_disabled' => true,
        'ecosystem.events.public_enabled'          => false,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    getJson('/api/events')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    getJson('/api/events/1')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    $eventQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_contains($query['query'], 'events'),
    );

    DB::disableQueryLog();

    expect($eventQueries)->toBe([]);
});

it('G1-10 · 域开关关闭时列表为空、详情 404，且不查业务表', function () {
    $event = Fixtures::event();
    config(['ecosystem.events.public_enabled' => false]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    getJson('/api/events')->assertOk()->assertExactJson(['data' => []]);

    getJson("/api/events/{$event['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::EVENT_NOT_FOUND);

    $eventQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_contains($query['query'], 'events'),
    );

    DB::disableQueryLog();

    expect($eventQueries)->toBe([]);
});

it('G1-10 · G1 报名状态恒为 false，reason 按优先级且 allowed_actions 不含 register', function () {
    $platform = Fixtures::event();
    $platformDto = getJson("/api/events/{$platform['id']}")->assertOk()->json('data.registration');

    expect($platformDto)->toBe([
        'can_register'        => false,
        'registration_reason' => 'registration_disabled',
        'allowed_actions'     => ['view_detail'],
        // G2 公开报名表元数据；该夹具没有 scope，因此全部为 null。
        'form_version'        => null,
        'privacy_version'     => null,
        'privacy_summary'     => null,
        'rules_version_id'    => null,
    ]);

    $external = Fixtures::event(
        [],
        [
            'source_type'               => 'external',
            'platform_role'             => 'directory_only',
            'external_registration_url' => 'https://example.com/register',
        ],
    );
    $externalDto = getJson("/api/events/{$external['id']}")->assertOk()->json('data.registration');

    expect($externalDto['can_register'])->toBeFalse();
    expect($externalDto['registration_reason'])->toBe('external_only');
    expect($externalDto['allowed_actions'])->toBe(['view_detail', 'open_external_registration']);

    $canceled = Fixtures::event(['hosting_status' => 'canceled']);
    expect(getJson("/api/events/{$canceled['id']}")->json('data.registration.registration_reason'))
        ->toBe('canceled');

    $completed = Fixtures::event(['hosting_status' => 'completed']);
    expect(getJson("/api/events/{$completed['id']}")->json('data.registration.registration_reason'))
        ->toBe('ended');

    foreach ([$platformDto, $externalDto] as $registration) {
        expect($registration['allowed_actions'])->not->toContain('register');
        expect($registration['allowed_actions'])->not->toContain('waitlist');
    }
});
