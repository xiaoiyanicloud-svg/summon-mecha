<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G1-10 · 公开可见性、列表筛选 / 稳定分页、正文与媒体集合的白名单渲染。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-10 visibility tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'          => true,
        'ecosystem.content.public_enabled'         => true,
        'ecosystem_public_read.emergency_disabled' => false,
    ]);
});

it('G1-10 · 活动列表只包含 published + normal + 已批准公开版本', function () {
    $visible = Fixtures::event();
    $draft = Fixtures::event(['publish_status' => 'draft']);
    $withheld = Fixtures::event(['disposition' => 'withheld']);
    $retracted = Fixtures::event(['disposition' => 'retracted']);
    $scheduled = Fixtures::event(['publish_status' => 'scheduled']);
    $unapproved = Fixtures::event([], ['review_state' => 'draft']);

    $ids = array_column(getJson('/api/events?per_page=50')->assertOk()->json('data'), 'id');

    expect($ids)->toContain($visible['id']);
    expect($ids)->not->toContain($draft['id']);
    expect($ids)->not->toContain($withheld['id']);
    expect($ids)->not->toContain($retracted['id']);
    expect($ids)->not->toContain($scheduled['id']);
    expect($ids)->not->toContain($unapproved['id']);
});

it('G1-10 · 活动分页稳定：同一 published_at 按 id DESC 决胜，跨页无重复', function () {
    $first = Fixtures::event(['published_at' => '2026-10-19 04:00:00']);
    $second = Fixtures::event(['published_at' => '2026-10-19 04:00:00']);
    $third = Fixtures::event(['published_at' => '2026-10-19 04:00:00']);

    $page1 = getJson('/api/events?per_page=2&page=1')->assertOk()->json();
    $page2 = getJson('/api/events?per_page=2&page=2')->assertOk()->json();

    expect(array_column($page1['data'], 'id'))->toBe([$third['id'], $second['id']]);
    expect(array_column($page2['data'], 'id'))->toBe([$first['id']]);
    expect($page1['meta'])->toMatchArray([
        'current_page' => 1,
        'last_page'    => 2,
        'per_page'     => 2,
        'total'        => 3,
    ]);
});

it('G1-10 · 活动筛选参数生效且非法参数返回 422', function () {
    $salon = Fixtures::event(['kind' => 'salon']);
    $competition = Fixtures::event(['kind' => 'competition']);

    $ids = array_column(getJson('/api/events?kind=competition')->json('data'), 'id');
    expect($ids)->toBe([$competition['id']]);

    $online = Fixtures::event([], [
        'participation_mode' => 'online',
        'city'               => '北京',
        'starts_at'          => '2026-12-01 01:00:00',
    ]);

    expect(array_column(getJson('/api/events?participation_mode=online')->json('data'), 'id'))
        ->toBe([$online['id']]);
    expect(array_column(getJson('/api/events?city=%E5%8C%97%E4%BA%AC')->json('data'), 'id'))
        ->toBe([$online['id']]);

    $canceled = Fixtures::event(['hosting_status' => 'canceled']);
    expect(array_column(getJson('/api/events?hosting_status=canceled')->json('data'), 'id'))
        ->toBe([$canceled['id']]);

    expect(array_column(getJson('/api/events?starts_from=2026-11-15T00:00:00%2B08:00')->json('data'), 'id'))
        ->toBe([$online['id']]);

    expect(array_column(getJson('/api/events?starts_to=2026-11-15T00:00:00%2B08:00')->json('data'), 'id'))
        ->toEqualCanonicalizing([$salon['id'], $competition['id'], $canceled['id']]);

    foreach ([
        'kind=unknown',
        'participation_mode=hybridx',
        'hosting_status=unknown',
        'per_page=51',
        'page=0',
        'starts_from=not-a-date',
        'starts_from=2026-12-01&starts_to=2026-11-01',
    ] as $query) {
        getJson('/api/events?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('code', ApiErrorCode::VALIDATION_ERROR);
    }
});

it('G1-10 · 正文与 media[] 只使用已批准引用 + 已批准媒体，未知 ID 删除', function () {
    $approved = Fixtures::media('approved', 'image/png');
    $pendingRef = Fixtures::media('pending-ref', 'image/png');
    $pendingMedia = Fixtures::media('pending-media', 'image/png', ['status' => 'pending']);
    $unknownId = 999999;

    $event = Fixtures::event([], [
        'detail_html' => '<p>正文</p>'
            .'<img data-media-id="'.$approved['id'].'" alt="A">'
            .'<img data-media-id="'.$pendingRef['id'].'" alt="B">'
            .'<img data-media-id="'.$pendingMedia['id'].'" alt="C">'
            .'<img data-media-id="'.$unknownId.'" alt="D">',
    ]);

    Fixtures::mediaRef('event_revision', $event['revision_id'], $approved['id']);
    Fixtures::mediaRef('event_revision', $event['revision_id'], $pendingRef['id'], 'inline', [
        'review_status' => 'pending',
        'sort_order'    => 1,
    ]);
    Fixtures::mediaRef('event_revision', $event['revision_id'], $pendingMedia['id'], 'inline', [
        'sort_order' => 2,
    ]);

    $data = getJson("/api/events/{$event['id']}")->assertOk()->json('data');

    expect(array_column($data['media'], 'id'))->toBe([$approved['id']]);

    $body = (string) $data['body_html'];
    expect($body)->toContain('/media/'.$approved['id']);
    expect($body)->not->toContain('/media/'.$pendingRef['id']);
    expect($body)->not->toContain('/media/'.$pendingMedia['id']);
    expect($body)->not->toContain('/media/'.$unknownId);
    expect($body)->not->toContain('alt="B"');
    expect($body)->not->toContain('alt="C"');
    expect($body)->not->toContain('alt="D"');
});

it('G1-10 · kill switch 对列表 / 详情 / 媒体一致 503', function () {
    Fixtures::event();
    config(['ecosystem_public_read.emergency_disabled' => true]);

    getJson('/api/events')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    getJson('/api/events/1')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    getJson('/api/events/1/media/1')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    getJson('/api/content/posts')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    getJson('/api/content/posts/anything')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    getJson('/api/content/posts/anything/media/1')
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);
});
