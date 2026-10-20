<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * G4-02 / G4-03 · 收藏与关联。
 *
 * 运行必须指向独立库 summon_mecha_step10_test；所有用例在 DatabaseTransactions 内。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G4-02/03 tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'          => true,
        'ecosystem.content.public_enabled'         => true,
        'ecosystem_public_read.emergency_disabled' => false,
        'ecosystem_g4.search.enabled'              => true,
        'ecosystem_g4.favorites.enabled'           => true,
        'ecosystem_g4.favorites.max_per_customer'  => 500,
        'ecosystem_g4.relations.enabled'           => true,
    ]);
});

function g4Customer(string $suffix = ''): Customer
{
    return Customer::factory()->create([
        'email'  => 'g4-'.($suffix ?: bin2hex(random_bytes(4))).'@example.test',
        'status' => 1,
    ]);
}

function g4Relation(string $sourceType, int $sourceId, string $targetType, string $targetKey, array $overrides = []): void
{
    DB::table('ecosystem_relations')->insert(array_merge([
        'source_type' => $sourceType,
        'source_key'  => (string) $sourceId,
        'target_type' => $targetType,
        'target_key'  => $targetKey,
        'sort_order'  => 0,
        'status'      => 'active',
        'created_by'  => 1,
        'created_at'  => '2026-10-20 00:00:00',
        'updated_at'  => '2026-10-20 00:00:00',
    ], $overrides));
}

function g4DeviceModel(string $status = 'active'): int
{
    return (int) DB::table('device_model')->insertGetId([
        'brand'      => '召唤机甲',
        'model_code' => 'M-'.bin2hex(random_bytes(3)),
        'model_name' => '公开机型',
        'status'     => $status,
        'sort_order' => 0,
        'created_at' => '2026-10-20 00:00:00',
        'updated_at' => '2026-10-20 00:00:00',
    ]);
}

function g4Scene(int $status = 1): int
{
    return (int) DB::table('scenes')->insertGetId([
        'name'       => '公开场景',
        'category'   => 'enterprise',
        'status'     => $status,
        'sort'       => 0,
        'created_at' => '2026-10-20 00:00:00',
        'updated_at' => '2026-10-20 00:00:00',
    ]);
}

/**
 * @return array{product_id: int, version_id: int}
 */
function g4Skill(string $versionStatus = 'published'): array
{
    $suffix = bin2hex(random_bytes(4));

    $productId = (int) DB::table('products')->insertGetId([
        'sku'                 => 'G4-SKILL-'.$suffix,
        'type'                => 'simple',
        'attribute_family_id' => 1,
        'created_at'          => '2026-10-20 00:00:00',
        'updated_at'          => '2026-10-20 00:00:00',
    ]);

    DB::table('product_flat')->insert([
        'sku'        => 'G4-SKILL-'.$suffix,
        'name'       => '公开技能',
        'product_id' => $productId,
        'locale'     => 'en',
        'channel'    => 'default',
        'created_at' => '2026-10-20 00:00:00',
        'updated_at' => '2026-10-20 00:00:00',
    ]);

    $versionId = (int) DB::table('skill_versions')->insertGetId([
        'product_id' => $productId,
        'version'    => '1.0.0',
        'channel'    => 'stable',
        'status'     => $versionStatus,
        'created_at' => '2026-10-20 00:00:00',
        'updated_at' => '2026-10-20 00:00:00',
    ]);

    return ['product_id' => $productId, 'version_id' => $versionId];
}

it('G4-02 · 未登录 401；开关关闭时 POST 403、GET 空列表', function () {
    $event = Fixtures::event();

    getJson('/api/customer/favorites?type=event')
        ->assertStatus(401);

    $customer = g4Customer();
    config(['ecosystem_g4.favorites.enabled' => false]);

    actingAs($customer, 'customer');

    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $event['id']])
        ->assertStatus(403)
        ->assertJsonPath('code', ApiErrorCode::ECOSYSTEM_FAVORITES_DISABLED);

    getJson('/api/customer/favorites?type=event')
        ->assertOk()
        ->assertJsonPath('meta.enabled', false)
        ->assertJsonCount(0, 'data');
});

it('G4-02 · 收藏幂等、跨账号隔离、列表与详情标记正确', function () {
    $event = Fixtures::event();
    $other = Fixtures::event([], ['name' => '另一个活动']);
    $customer = g4Customer();
    $otherCustomer = g4Customer('other');

    actingAs($customer, 'customer');

    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $event['id']])
        ->assertOk()
        ->assertJsonPath('data.favorited', true)
        ->assertJsonPath('data.created', true);

    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $event['id']])
        ->assertOk()
        ->assertJsonPath('data.created', false);

    // 列表标记只对本人生效。
    $mine = getJson('/api/events')->assertOk();
    $flags = collect($mine->json('data'))->pluck('favorited', 'id');
    expect($flags[$event['id']])->toBeTrue();
    expect($flags[$other['id']])->toBeFalse();

    // 另一个账号看不到。
    actingAs($otherCustomer, 'customer');
    $theirs = getJson('/api/events')->assertOk();
    expect(collect($theirs->json('data'))->pluck('favorited')->unique()->all())->toBe([false]);

    actingAs($customer, 'customer');
    getJson("/api/events/{$event['id']}")->assertOk()->assertJsonPath('data.favorited', true);

    $list = getJson('/api/customer/favorites?type=event')->assertOk();
    expect($list->headers->get('Cache-Control'))->toContain('private');
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$event['id']]);
    expect($list->json('data.0.title'))->toBe('公开活动名称');

    deleteJson("/api/customer/favorites/event/{$event['id']}")->assertOk()->assertJsonPath('data.removed', true);
    deleteJson("/api/customer/favorites/event/{$event['id']}")->assertOk()->assertJsonPath('data.removed', false);

    getJson("/api/events/{$event['id']}")->assertOk()->assertJsonPath('data.favorited', false);
});

it('G4-02 · 资讯收藏按 slug 生效', function () {
    $post = Fixtures::content();
    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');
    $customer = g4Customer();

    actingAs($customer, 'customer');

    postJson('/api/customer/favorites', ['type' => 'content', 'key' => $slug])
        ->assertOk()
        ->assertJsonPath('data.favorited', true);

    getJson("/api/content/posts/{$slug}")->assertOk()->assertJsonPath('data.favorited', true);

    $list = getJson('/api/customer/favorites?type=content')->assertOk();
    expect($list->json('data.0.slug'))->toBe($slug);
    expect($list->json('data.0.title'))->toBe('公开文章标题');
});

it('G4-02 · 草稿/撤稿/不存在统一 404，不泄露标题', function () {
    $draft = Fixtures::event(['publish_status' => 'draft']);
    $retracted = Fixtures::event(['disposition' => 'retracted']);
    $customer = g4Customer();

    actingAs($customer, 'customer');

    foreach ([$draft['id'], $retracted['id'], 999999999] as $id) {
        $response = postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $id])
            ->assertStatus(404)
            ->assertJsonPath('code', ApiErrorCode::FAVORITE_TARGET_NOT_FOUND);

        expect((string) $response->getContent())->not->toContain('公开活动名称');
    }
});

it('G4-02 · 达到单用户上限返回 409', function () {
    $first = Fixtures::event();
    $second = Fixtures::event([], ['name' => '第二个活动']);
    $customer = g4Customer();

    config(['ecosystem_g4.favorites.max_per_customer' => 1]);
    actingAs($customer, 'customer');

    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $first['id']])->assertOk();

    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $second['id']])
        ->assertStatus(409)
        ->assertJsonPath('code', ApiErrorCode::FAVORITE_LIMIT_REACHED);
});

it('G4-02 · 目标撤稿后收藏列表只计 stale_count，不回显旧标题', function () {
    $event = Fixtures::event();
    $customer = g4Customer();

    actingAs($customer, 'customer');
    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $event['id']])->assertOk();

    DB::table('events')->where('id', $event['id'])->update([
        'disposition' => 'retracted',
        'updated_at'  => '2026-10-20 01:00:00',
    ]);

    $response = getJson('/api/customer/favorites?type=event')->assertOk();

    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.stale_count'))->toBe(1);
    expect((string) $response->getContent())->not->toContain('公开活动名称');
});

it('G4-03 · 活动详情返回真实关联，草稿/撤稿/下架目标整项省略', function () {
    $source = Fixtures::event([], ['name' => '来源活动']);

    $publicEvent = Fixtures::event([], ['name' => '关联活动']);
    $draftEvent = Fixtures::event(['publish_status' => 'draft'], ['name' => '草稿活动']);
    $retractedEvent = Fixtures::event(['disposition' => 'retracted'], ['name' => '撤稿活动']);

    $content = Fixtures::content([], ['title' => '关联资讯']);
    $contentSlug = (string) DB::table('content_posts')->where('id', $content['id'])->value('slug');

    $activeModel = g4DeviceModel('active');
    $inactiveModel = g4DeviceModel('inactive');
    $scene = g4Scene(1);
    $offlineScene = g4Scene(0);
    $skill = g4Skill('published');
    $draftSkill = g4Skill('draft');

    g4Relation('event', $source['id'], 'event', (string) $publicEvent['id']);
    g4Relation('event', $source['id'], 'event', (string) $draftEvent['id']);
    g4Relation('event', $source['id'], 'event', (string) $retractedEvent['id']);
    g4Relation('event', $source['id'], 'content', $contentSlug);
    g4Relation('event', $source['id'], 'device_model', (string) $activeModel);
    g4Relation('event', $source['id'], 'device_model', (string) $inactiveModel);
    g4Relation('event', $source['id'], 'scene', (string) $scene);
    g4Relation('event', $source['id'], 'scene', (string) $offlineScene);
    g4Relation('event', $source['id'], 'skill', (string) $skill['product_id']);
    g4Relation('event', $source['id'], 'skill', (string) $draftSkill['product_id']);

    $response = getJson("/api/events/{$source['id']}")->assertOk();

    $related = $response->json('data.related');
    expect(collect($related['events'])->pluck('id')->all())->toBe([$publicEvent['id']]);
    expect(collect($related['contents'])->pluck('slug')->all())->toBe([$contentSlug]);
    expect(collect($related['device_models'])->pluck('id')->all())->toBe([$activeModel]);
    expect($related['device_models'][0]['availability'])->toBe('active');
    expect(collect($related['scenes'])->pluck('id')->all())->toBe([$scene]);
    expect(collect($related['skills'])->pluck('product_id')->all())->toBe([$skill['product_id']]);

    $raw = (string) $response->getContent();
    expect($raw)->not->toContain('草稿活动');
    expect($raw)->not->toContain('撤稿活动');
    expect($raw)->not->toContain('storage_key');
    expect($raw)->not->toContain('PRIVATE-AUTH-EVIDENCE-REF');
});

it('G4-03 · 关联开关关闭时详情不返回关联；资讯详情同样解析', function () {
    $source = Fixtures::event([], ['name' => '来源活动']);
    $related = Fixtures::event([], ['name' => '关联活动']);
    g4Relation('event', $source['id'], 'event', (string) $related['id']);

    config(['ecosystem_g4.relations.enabled' => false]);
    $response = getJson("/api/events/{$source['id']}")->assertOk();
    expect(collect($response->json('data.related.events'))->all())->toBe([]);

    config(['ecosystem_g4.relations.enabled' => true]);

    $post = Fixtures::content([], ['title' => '来源资讯']);
    $target = Fixtures::event([], ['name' => '资讯关联活动']);
    g4Relation('content', $post['id'], 'event', (string) $target['id']);

    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');
    $contentResponse = getJson("/api/content/posts/{$slug}")->assertOk();

    expect(collect($contentResponse->json('data.related.events'))->pluck('id')->all())->toBe([$target['id']]);
});

it('G4-02/03 · 停读与域关闭时，收藏/关联不能成为绕过侧信道', function () {
    $source = Fixtures::event();
    $target = Fixtures::event([], ['name' => '关联目标']);
    g4Relation('event', $source['id'], 'event', (string) $target['id']);

    $customer = g4Customer();
    actingAs($customer, 'customer');

    // 域关闭：收藏新增 404，列表为空；关联解析为空。
    config(['ecosystem.events.public_enabled' => false]);

    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $target['id']])
        ->assertStatus(404)
        ->assertJsonPath('code', ApiErrorCode::FAVORITE_TARGET_NOT_FOUND);

    expect(getJson('/api/customer/favorites?type=event')->assertOk()->json('data'))->toBe([]);

    $related = app(\Webkul\Shop\Services\Ecosystem\RelationService::class)
        ->forSource('event', (int) $source['id']);
    expect(collect($related['events'])->all())->toBe([]);

    // kill switch：收藏入口 503，关联解析为空。
    config([
        'ecosystem.events.public_enabled'          => true,
        'ecosystem_public_read.emergency_disabled' => true,
    ]);

    postJson('/api/customer/favorites', ['type' => 'event', 'key' => (string) $target['id']])
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    getJson('/api/customer/favorites?type=event')->assertStatus(503);

    $related = app(\Webkul\Shop\Services\Ecosystem\RelationService::class)
        ->forSource('event', (int) $source['id']);
    expect(collect($related['events'])->all())->toBe([]);
});
