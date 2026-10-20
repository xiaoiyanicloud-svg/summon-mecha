<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * G2-B · 本人报名 HTTP 合同。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem.events.registration_enabled'        => true,
        'ecosystem.events.registration_write_verified' => true,
        'ecosystem_registration.enabled'               => true,
    ]);
});

function g2ApiCustomer(string $suffix = ''): Customer
{
    return Customer::factory()->create([
        'email' => 'g2-api-'.($suffix ?: bin2hex(random_bytes(4))).'@example.test',
        'status' => 1,
    ]);
}

function g2ApiScope(int $eventId, array $overrides = []): EventRegistrationScope
{
    $id = Fixtures::scope($eventId, array_merge([
        'status'              => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'capacity'            => 2,
    ], $overrides));

    return EventRegistrationScope::findOrFail($id);
}

function g2ApiPayload(EventRegistrationScope $scope, array $overrides = []): array
{
    return array_merge([
        'request_key'    => 'api-'.bin2hex(random_bytes(8)),
        'form_version'   => (int) $scope->form_version,
        'privacy_version'=> (string) $scope->privacy_version,
        'consent'        => true,
        'name'           => '张三',
        'phone'          => '13800000001',
        'city'           => '上海',
    ], $overrides);
}

it('G2-B API · 提交 → 我的报名 → 取消 → 重报 全链路', function () {
    $event = Fixtures::event(['kind' => 'salon', 'name' => 'API 报名活动']);
    $scope = g2ApiScope($event['id']);
    $customer = g2ApiCustomer();

    actingAs($customer, 'customer');

    $payload = g2ApiPayload($scope);

    $submit = postJson("/api/events/{$event['id']}/register", $payload)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.attempt_no', 1);

    $publicNo = (string) $submit->json('data.public_no');
    $attemptId = (int) $submit->json('data.attempt_id');

    expect($publicNo)->not->toBe('');

    getJson('/api/customer/event-registrations')
        ->assertOk()
        ->assertJsonPath('data.0.public_no', $publicNo)
        ->assertJsonPath('data.0.status', 'confirmed')
        ->assertJsonPath('data.0.contact.name', '张*')
        ->assertJsonPath('data.0.contact.phone', '138****0001');

    $cancelPayload = [
        'request_key'         => 'cancel-'.bin2hex(random_bytes(6)),
        'expected_attempt_id' => $attemptId,
    ];

    postJson("/api/customer/event-registrations/{$publicNo}/cancel", $cancelPayload)
        ->assertOk()
        ->assertJsonPath('data.status', 'canceled')
        ->assertJsonPath('data.can_reapply', true);

    getJson("/api/customer/event-registrations/{$publicNo}")
        ->assertOk()
        ->assertJsonPath('data.status', 'canceled');

    $reapply = postJson("/api/customer/event-registrations/{$publicNo}/reapply", [
        'request_key'         => 're-'.bin2hex(random_bytes(6)),
        'expected_attempt_id' => $attemptId,
        'form_version'        => (int) $scope->form_version,
        'privacy_version'     => (string) $scope->privacy_version,
        'consent'             => true,
        'name'                => '张三',
        'phone'               => '13800000001',
        'city'                => '上海',
    ])->assertOk();

    expect((int) $reapply->json('data.attempt_no'))->toBe(2);
    expect($reapply->json('data.status'))->toBe('confirmed');
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);
});

it('G2-B API · 幂等重放与请求查回', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ApiScope($event['id']);
    $customer = g2ApiCustomer();

    actingAs($customer, 'customer');

    $payload = g2ApiPayload($scope);

    $first = postJson("/api/events/{$event['id']}/register", $payload)->assertOk();
    $second = postJson("/api/events/{$event['id']}/register", $payload)
        ->assertOk()
        ->assertJsonPath('replayed', true);

    expect($second->json('data.public_no'))->toBe($first->json('data.public_no'));

    getJson('/api/customer/registration-requests/'.$payload['request_key'])
        ->assertOk()
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.public_no', $first->json('data.public_no'));

    // 同 key 异载荷：409。
    postJson("/api/events/{$event['id']}/register", array_merge($payload, ['name' => '李四']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'REGISTRATION_REQUEST_CONFLICT');
});

it('G2-B API · 他人不能读取/取消我的报名；未登录 401', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ApiScope($event['id']);
    $owner = g2ApiCustomer('owner');
    $other = g2ApiCustomer('other');

    actingAs($owner, 'customer');
    $submit = postJson("/api/events/{$event['id']}/register", g2ApiPayload($scope))->assertOk();
    $publicNo = (string) $submit->json('data.public_no');
    $attemptId = (int) $submit->json('data.attempt_id');

    actingAs($other, 'customer');

    getJson("/api/customer/event-registrations/{$publicNo}")->assertNotFound();
    postJson("/api/customer/event-registrations/{$publicNo}/cancel", [
        'request_key'         => 'x-'.bin2hex(random_bytes(4)),
        'expected_attempt_id' => $attemptId,
    ])->assertNotFound();
});

it('G2-B API · 未登录报名返回 401', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ApiScope($event['id']);

    postJson("/api/events/{$event['id']}/register", g2ApiPayload($scope))
        ->assertUnauthorized();
});

it('G2-B API · 双闸关闭时写入口 403，不产生报名', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ApiScope($event['id']);
    $customer = g2ApiCustomer();

    config(['ecosystem.events.registration_write_verified' => false]);

    actingAs($customer, 'customer');

    postJson("/api/events/{$event['id']}/register", g2ApiPayload($scope))
        ->assertForbidden()
        ->assertJsonPath('code', 'EVENT_REGISTRATION_DISABLED');

    expect(DB::table('event_registration_attempts')->count())->toBe(0);
});
