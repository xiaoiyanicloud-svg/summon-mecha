<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * G4-08 · 撤稿 / 取消 / 停读演练（独立测试库）。
 *
 * 覆盖：
 *   · 撤稿后 API 410、SSR 410、sitemap 排除、收藏不泄露旧标题、关联整项省略；
 *   · 活动取消后报名状态筛选不把其当作可报名；
 *   · kill switch 同时覆盖 API / SSR / sitemap（503，不伪装空列表）。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G4-08 drill requires the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'              => true,
        'ecosystem.content.public_enabled'             => true,
        'ecosystem_public_read.emergency_disabled'     => false,
        'ecosystem_g4.ssr.enabled'                     => true,
        'ecosystem_g4.ssr.base_url'                    => 'https://example.test',
        'ecosystem_g4.sitemap.enabled'                 => true,
        'ecosystem_g4.search.enabled'                  => true,
        'ecosystem_g4.favorites.enabled'               => true,
        'ecosystem_g4.relations.enabled'               => true,
        'ecosystem.events.registration_enabled'        => true,
        'ecosystem.events.registration_write_verified' => true,
    ]);
});

function g4DrillCustomer(): Customer
{
    return Customer::factory()->create([
        'email'  => 'g4-drill-'.bin2hex(random_bytes(4)).'@example.test',
        'status' => 1,
    ]);
}

it('G4-08 · 撤稿后所有分发入口同步降级', function () {
    $event = Fixtures::event([], ['name' => '演练活动标题', 'summary' => '演练活动摘要']);
    $source = Fixtures::event([], ['name' => '关联来源']);
    $customer = g4DrillCustomer();

    DB::table('ecosystem_relations')->insert([
        'source_type' => 'event',
        'source_key'  => (string) $source['id'],
        'target_type' => 'event',
        'target_key'  => (string) $event['id'],
        'sort_order'  => 0,
        'status'      => 'active',
        'created_by'  => 1,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    actingAs($customer, 'customer');
    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $event['id']])->assertOk();

    // 撤稿前：sitemap 有、关联有。
    expect((string) get('/sitemap.xml')->getContent())->toContain('/events/'.$event['id']);
    expect(collect(getJson("/api/events/{$source['id']}")->json('data.related.events'))->pluck('id')->all())
        ->toBe([$event['id']]);

    DB::table('events')->where('id', $event['id'])->update([
        'disposition' => 'retracted',
        'updated_at'  => now('UTC'),
    ]);

    // API：410 中性文案，不回显标题/摘要。
    $api = getJson("/api/events/{$event['id']}")->assertStatus(410);
    expect((string) $api->getContent())
        ->not->toContain('演练活动标题')
        ->not->toContain('演练活动摘要');

    // SSR：410 中性页。
    $ssr = get("/events/{$event['id']}")->assertStatus(410);
    expect((string) $ssr->getContent())->not->toContain('演练活动标题');

    // sitemap：不再包含。
    expect((string) get('/sitemap.xml')->getContent())->not->toContain('/events/'.$event['id']);

    // 收藏：只计 stale_count，不返回旧标题。
    $favorites = getJson('/api/customer/favorites?type=event')->assertOk();
    expect($favorites->json('data'))->toBe([]);
    expect($favorites->json('meta.stale_count'))->toBe(1);
    expect((string) $favorites->getContent())->not->toContain('演练活动标题');

    // 关联：整项省略，不输出占位 CTA。
    $related = getJson("/api/events/{$source['id']}")->assertOk();
    expect(collect($related->json('data.related.events'))->pluck('id')->all())->toBe([]);
});

it('G4-08 · 活动取消后报名状态筛选不再把其当作可报名', function () {
    $event = Fixtures::event(['hosting_status' => 'scheduled']);

    DB::table('event_registration_scopes')->insert([
        'event_id'              => $event['id'],
        'scope_key'             => 'default',
        'unit'                  => 'person',
        'capacity'              => null,
        'capacity_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'confirmed_count'       => 0,
        'review_mode'           => 'auto',
        'status'                => 'open',
        'policy_confirmed_at'   => now('UTC')->format('Y-m-d H:i:s'),
        'row_version'           => 0,
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);

    $openIds = fn (): array => collect(getJson('/api/events?registration_state=open')->json('data'))->pluck('id')->all();
    expect($openIds())->toContain($event['id']);

    DB::table('events')->where('id', $event['id'])->update([
        'hosting_status' => 'canceled',
        'updated_at'     => now('UTC'),
    ]);

    expect($openIds())->not->toContain($event['id']);

    // 详情仍可访问，但明确取消状态；不伪造报名成功。
    $detail = getJson("/api/events/{$event['id']}")->assertOk();
    expect($detail->json('data.hosting_status'))->toBe('canceled');
    expect($detail->json('data.registration.can_register'))->toBeFalse();
    expect($detail->json('data.registration.registration_reason'))->toBe('canceled');
});

it('G4-08 · kill switch 同时停读 API / SSR / sitemap', function () {
    $event = Fixtures::event();
    config(['ecosystem_public_read.emergency_disabled' => true]);

    getJson('/api/events')->assertStatus(503);
    getJson("/api/events/{$event['id']}")->assertStatus(503);
    get('/events')->assertStatus(503);
    get("/events/{$event['id']}")->assertStatus(503);
    get('/sitemap.xml')->assertStatus(503);
});
