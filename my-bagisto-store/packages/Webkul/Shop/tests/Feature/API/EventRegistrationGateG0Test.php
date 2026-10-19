<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as G1Fixtures;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * G0 公开读取闸 + 旧站内报名停写 + 公开合同去伪。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions,数据在事务内创建并回滚,
 * 不做 migrate:fresh / truncate / seed。运行必须指向独立库
 * summon_mecha_step10_test(APP_ENV=testing),禁止落到 root 主库。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G0 event tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        // G1 可信发布完成前,公开读取默认关闭。
        'ecosystem.events.public_enabled'               => false,
        'ecosystem.events.registration_enabled'         => false,
        'ecosystem.events.registration_write_verified'  => false,
        'ecosystem.events.registration_disabled_reason' => '测试期站内报名停写。',
    ]);
});

function g0EnableEventPublic(): void
{
    config(['ecosystem.events.public_enabled' => true]);
}

function g0EnableEventRegistration(): void
{
    config([
        'ecosystem.events.registration_enabled'        => true,
        'ecosystem.events.registration_write_verified' => true,
    ]);
}

it('G1 公开读取关闭时:即使库里已有事件,列表为空且详情 404', function () {
    $event = Event::create([
        'name'   => '库内样例赛事',
        'status' => 'registering',
    ]);

    getJson('/api/events')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    getJson("/api/events/{$event->id}")
        ->assertNotFound()
        ->assertJson([
            'success' => false,
            'code'    => ApiErrorCode::EVENT_NOT_FOUND,
            'message' => '赛事尚未公开发布或不存在。',
        ]);
});

it('G0-02 公开关闭时,报名的停写检查仍早于赛事查询', function () {
    // 注意:public_enabled=false 时 show 也 404,但报名写入口由双重闸独立控制。
    postJson('/api/events/999999999/register', [
        'name'  => '测试用户',
        'phone' => '13800000001',
    ])
        ->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code'    => ApiErrorCode::EVENT_REGISTRATION_DISABLED,
            'message' => '测试期站内报名停写。',
        ]);

    expect(EventRegistration::count())->toBe(0);
});

it('G0-06 双重闸:只打开产品开关、未通过写能力验收仍返回 403', function () {
    config(['ecosystem.events.registration_enabled' => true]);

    $event = Event::create([
        'name'   => '双闸测试赛事',
        'status' => 'registering',
    ]);

    postJson("/api/events/{$event->id}/register", [
        'name'  => '测试用户',
        'phone' => '13800000002',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', ApiErrorCode::EVENT_REGISTRATION_DISABLED);

    expect(EventRegistration::where('event_id', $event->id)->count())->toBe(0);
});

it('G0-06 双重闸都打开时,只有 registering 状态允许报名', function () {
    g0EnableEventRegistration();

    $upcoming = Event::create([
        'name'   => '未开始赛事',
        'status' => 'upcoming',
    ]);

    postJson("/api/events/{$upcoming->id}/register", [
        'name'  => '测试用户',
        'phone' => '13800000003',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', ApiErrorCode::EVENT_NOT_REGISTERING);

    expect(EventRegistration::where('event_id', $upcoming->id)->count())->toBe(0);

    $registering = Event::create([
        'name'   => '报名中赛事',
        'status' => 'registering',
    ]);

    postJson("/api/events/{$registering->id}/register", [
        'name'  => '测试用户',
        'phone' => '13800000004',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('event_registrations', [
        'event_id' => $registering->id,
        'phone'    => '13800000004',
    ]);
});

it('G0-02 公开合同只下发 registration 白名单,且不返回 hot/teams/fav', function () {
    g0EnableEventPublic();

    // G1-10：公开读取只认 published revision；旧 events 行不再直接公开。
    $event = G1Fixtures::event();

    $response = getJson('/api/events')->assertOk();

    $response->assertJsonPath('data.0.id', $event['id'])
        ->assertJsonPath('data.0.registration.can_register', false)
        ->assertJsonPath('data.0.registration.registration_reason', 'registration_disabled')
        ->assertJsonMissingPath('data.0.hot')
        ->assertJsonMissingPath('data.0.teams')
        ->assertJsonMissingPath('data.0.fav')
        ->assertJsonMissingPath('data.0.can_register');

    // 详情走同一合同,前端按 ID 重新取也能拿到稳定报名原因。
    getJson("/api/events/{$event['id']}")
        ->assertOk()
        ->assertJsonPath('data.registration.can_register', false)
        ->assertJsonPath('data.registration.registration_reason', 'registration_disabled')
        ->assertJsonMissingPath('data.hot')
        ->assertJsonMissingPath('data.teams')
        ->assertJsonMissingPath('data.fav');
});

it('G1-10 公开打开后 can_register 恒为 false（G2 写入口未接）', function () {
    g0EnableEventPublic();

    $event = G1Fixtures::event();

    // 双重闸未开:公开可读,但不可报名。
    getJson('/api/events')
        ->assertOk()
        ->assertJsonPath('data.0.registration.can_register', false)
        ->assertJsonPath('data.0.registration.registration_reason', 'registration_disabled');

    getJson("/api/events/{$event['id']}")
        ->assertOk()
        ->assertJsonPath('data.registration.can_register', false);

    // G1 即使双重闸被打开也不得返回 true —— 站内报名写入口属于 G2。
    g0EnableEventRegistration();

    getJson('/api/events')
        ->assertOk()
        ->assertJsonPath('data.0.registration.can_register', false)
        ->assertJsonPath('data.0.registration.registration_reason', 'registration_disabled');

    getJson("/api/events/{$event['id']}")
        ->assertOk()
        ->assertJsonPath('data.registration.can_register', false);
});

it('G0 路由只接受数字 ID', function () {
    getJson('/api/events/not-a-number')->assertNotFound();

    // 站内存在 GET 兜底路由(shop.product_or_category.index,{fallbackPlaceholder})。
    // POST 非数字 ID 不匹配 events 路由约束后会被该 GET 兜底捕获,因此是 405 而非 404;
    // 关键结论相同:请求被拒绝,没有进入报名写入口。
    postJson('/api/events/not-a-number/register', [
        'name'  => '测试用户',
        'phone' => '13800000005',
    ])->assertStatus(405);

    expect(EventRegistration::count())->toBe(0);
});
