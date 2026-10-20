<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G4-01 · 搜索、筛选、游标分页与 LIKE 转义。
 *
 * 运行必须指向独立库 summon_mecha_step10_test；所有用例在 DatabaseTransactions 内。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G4-01 tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'              => true,
        'ecosystem.content.public_enabled'             => true,
        'ecosystem_public_read.emergency_disabled'     => false,
        'ecosystem_g4.search.enabled'                  => true,
        'ecosystem_g4.search.keyword_max_length'       => 50,
        'ecosystem_g4.search.max_page'                 => 200,
        'ecosystem.events.registration_enabled'        => true,
        'ecosystem.events.registration_write_verified' => true,
    ]);
});

function g4Scope(int $eventId, array $overrides = []): void
{
    DB::table('event_registration_scopes')->insert(array_merge([
        'event_id'               => $eventId,
        'scope_key'              => 'default',
        'unit'                   => 'person',
        'capacity'               => null,
        'capacity_confirmed_at'  => '2026-10-19 00:00:00',
        'confirmed_count'        => 0,
        'review_mode'            => 'auto',
        'registration_opens_at'  => null,
        'registration_closes_at' => null,
        'review_closes_at'       => null,
        'status'                 => 'open',
        'policy_confirmed_at'    => '2026-10-19 00:00:00',
        'row_version'            => 0,
        'created_at'             => '2026-10-19 00:00:00',
        'updated_at'             => '2026-10-19 00:00:00',
    ], $overrides));
}

it('G4-01 · q 搜索标题/摘要/城市/地点，且 % _ \\ 按字面量处理', function () {
    $percent = Fixtures::event([], ['name' => '100% 机甲挑战赛']);
    $underscore = Fixtures::event([], ['name' => '机甲_公开日']);
    $backslash = Fixtures::event([], ['name' => '机甲\\工作坊']);
    $plain = Fixtures::event([], ['name' => '普通活动']);

    $ids = fn (string $q): array => collect(getJson('/api/events?'.http_build_query(['q' => $q]))->assertOk()->json('data'))
        ->pluck('id')->all();

    expect($ids('100%'))->toBe([$percent['id']]);
    expect($ids('机甲_'))->toBe([$underscore['id']]);
    expect($ids('机甲\\'))->toBe([$backslash['id']]);
    expect($ids('%'))->toBe([$percent['id']]);
    expect($ids('_'))->toBe([$underscore['id']]);
    expect($ids('普通'))->toBe([$plain['id']]);
});

it('G4-01 · 搜索关闭时带 q / registration_state 明确 400，不静默忽略', function () {
    Fixtures::event();
    config(['ecosystem_g4.search.enabled' => false]);

    getJson('/api/events?q=机甲')
        ->assertStatus(422)
        ->assertJsonPath('code', ApiErrorCode::VALIDATION_ERROR)
        ->assertJsonPath('reason', 'search_disabled');

    getJson('/api/events?registration_state=open')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'search_disabled');
});

it('G4-01 · 空白关键词视为未传；超长关键词、超过页上限稳定 422', function () {
    Fixtures::event();

    // TrimStrings 会把纯空白清成空值；空关键词按“未传”处理，返回正常列表。
    getJson('/api/events?q=%20%20')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    getJson('/api/events?q='.str_repeat('机', 51))
        ->assertStatus(422)
        ->assertJsonPath('code', ApiErrorCode::VALIDATION_ERROR);

    getJson('/api/events?page=201')
        ->assertStatus(422)
        ->assertJsonPath('code', ApiErrorCode::VALIDATION_ERROR);
});

it('G4-01 · 游标分页跨页不重不漏，翻页期间新增内容不插队', function () {
    $created = [];

    for ($index = 0; $index < 5; $index++) {
        $created[] = Fixtures::event([
            'published_at'       => '2026-10-19 04:00:00',
            'first_published_at' => '2026-10-19 04:00:00',
        ], ['name' => '同时间活动 '.$index]);
    }

    $first = getJson('/api/events?'.http_build_query([
        'mode'     => 'cursor',
        'per_page' => 2,
    ]))->assertOk();

    $firstIds = collect($first->json('data'))->pluck('id')->all();
    $next = $first->json('meta.next_cursor');

    expect($firstIds)->toHaveCount(2);
    expect($first->json('meta.has_more'))->toBeTrue();
    expect($next)->toBeString();

    // 翻页期间插入一条更新时间更晚的活动：它只能出现在“从头开始”的新会话里。
    $newcomer = Fixtures::event([
        'published_at'       => '2026-10-20 04:00:00',
        'first_published_at' => '2026-10-20 04:00:00',
    ], ['name' => '翻页期间新增']);

    $seen = $firstIds;

    while ($next !== null) {
        $page = getJson('/api/events?'.http_build_query([
            'mode'     => 'cursor',
            'per_page' => 2,
            'cursor'   => $next,
        ]))->assertOk();

        $ids = collect($page->json('data'))->pluck('id')->all();
        $seen = array_merge($seen, $ids);
        $next = $page->json('meta.next_cursor');
    }

    expect($seen)->toHaveCount(5);
    expect(array_unique($seen))->toHaveCount(5);
    expect($seen)->not->toContain($newcomer['id']);
    expect($seen)->toEqualCanonicalizing(array_column($created, 'id'));

    // 从头重新开始才会看到新增内容。
    $fresh = getJson('/api/events?'.http_build_query(['mode' => 'cursor', 'per_page' => 10]))->assertOk();
    expect(collect($fresh->json('data'))->pluck('id')->all())->toContain($newcomer['id']);
});

it('G4-01 · 游标与筛选条件不一致时 400，不静默从头翻页', function () {
    Fixtures::event();
    Fixtures::event([], ['name' => '另一个活动']);

    $first = getJson('/api/events?'.http_build_query([
        'mode'     => 'cursor',
        'per_page' => 1,
        'q'        => '活动',
    ]))->assertOk();

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString();

    getJson('/api/events?'.http_build_query([
        'mode'     => 'cursor',
        'per_page' => 1,
        'q'        => '另一个',
        'cursor'   => $cursor,
    ]))
        ->assertStatus(422)
        ->assertJsonPath('code', ApiErrorCode::VALIDATION_ERROR)
        ->assertJsonPath('reason', 'cursor_filter_mismatch');

    getJson('/api/events?'.http_build_query([
        'mode'   => 'cursor',
        'cursor' => 'not-a-real-cursor',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_cursor');
});

it('G4-01 · page 与 cursor 不能同时使用', function () {
    Fixtures::event();

    getJson('/api/events?page=1&cursor=abc')
        ->assertStatus(422)
        ->assertJsonPath('code', ApiErrorCode::VALIDATION_ERROR);
});

it('G4-01 · registration_state 按 scope 事实过滤', function () {
    $open = Fixtures::event([], ['name' => '开放活动']);
    g4Scope($open['id']);

    $upcoming = Fixtures::event([], ['name' => '未开始活动']);
    g4Scope($upcoming['id'], [
        'registration_opens_at' => '2099-01-01 00:00:00',
    ]);

    $full = Fixtures::event([], ['name' => '已满活动']);
    g4Scope($full['id'], ['capacity' => 2, 'confirmed_count' => 2]);

    $closed = Fixtures::event([], ['name' => '已关闭活动']);
    g4Scope($closed['id'], ['registration_closes_at' => '2020-01-01 00:00:00']);

    $ended = Fixtures::event([
        'hosting_status' => 'completed',
        'ends_at'        => '2020-01-01 00:00:00',
    ], ['name' => '已结束活动']);

    $canceled = Fixtures::event(['hosting_status' => 'canceled'], ['name' => '已取消活动']);

    $external = Fixtures::event([], [
        'name'                      => '外部活动',
        'external_registration_url' => 'https://example.com/register',
    ]);

    $unavailable = Fixtures::event([], ['name' => '无报名入口活动']);

    $ids = fn (string $state): array => collect(
        getJson('/api/events?'.http_build_query(['registration_state' => $state]))->assertOk()->json('data')
    )->pluck('id')->all();

    expect($ids('open'))->toContain($open['id'])->not->toContain($full['id']);
    expect($ids('upcoming'))->toBe([$upcoming['id']]);
    expect($ids('full'))->toBe([$full['id']]);
    expect($ids('closed'))->toContain($closed['id'])->not->toContain($open['id']);
    expect($ids('ended'))->toContain($ended['id']);
    expect($ids('canceled'))->toBe([$canceled['id']]);
    expect($ids('external'))->toBe([$external['id']]);
    expect($ids('unavailable'))->toContain($unavailable['id'])->not->toContain($open['id']);
});

it('G4-01 · 平台报名双闸关闭时 open 状态不返回任何活动', function () {
    $event = Fixtures::event();
    g4Scope($event['id']);

    config(['ecosystem.events.registration_write_verified' => false]);

    $response = getJson('/api/events?registration_state=open')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('G4-01 · 资讯搜索与游标分页同源生效', function () {
    $first = Fixtures::content([], ['title' => '机甲资讯 100%']);
    Fixtures::content([], ['title' => '普通资讯']);

    $firstSlug = (string) DB::table('content_posts')->where('id', $first['id'])->value('slug');

    $search = getJson('/api/content/posts?'.http_build_query(['q' => '100%']))->assertOk();
    expect(collect($search->json('data'))->pluck('slug')->all())->toBe([$firstSlug]);

    $page = getJson('/api/content/posts?'.http_build_query([
        'mode'     => 'cursor',
        'per_page' => 1,
    ]))->assertOk();

    expect($page->json('meta.mode'))->toBe('cursor');
    expect($page->json('meta.has_more'))->toBeTrue();
    expect($page->json('meta.next_cursor'))->toBeString();
    expect($page->json('meta.filters'))->toBeArray();
});
