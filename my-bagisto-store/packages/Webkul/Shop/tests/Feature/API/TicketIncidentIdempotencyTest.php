<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Controllers\API\TicketController;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\SupportTicket;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * A2 · 单号生成竞态 —— 重复提交/并发直接 500。
 *
 * 改前:`TicketController` 用 `'T'.now()->format('ymdHis').$customer->id`、
 * `IncidentService::generateNo()` 用 `while (exists) 加后缀` —— 都是 check-then-act,
 * 非原子。同一客户同一秒点两下(弱网重试/双击)就撞唯一键 → 500。
 *
 * 改后:号里加随机后缀 + 唯一索引兜底 catch 冲突重试;并引入客户端 `Idempotency-Key`。
 */

function a2Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Ticket',
        'last_name'   => 'User',
        'email'       => 'a2'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

// ───────────────────────── 工单 ─────────────────────────

it('A2 · 同用户同一秒连发两次开工单 —— 不 500,且单号不同', function () {
    $customer = a2Customer();
    actingAs($customer, 'customer');

    // 把时间冻在同一秒:这正是原来必 500 的场景
    test()->travelTo('2026-10-15 10:00:00', function () use ($customer) {
        $first = postJson(route('shop.api.tickets.store'), ['content' => '装不上'])
            ->assertStatus(201);
        $second = postJson(route('shop.api.tickets.store'), ['content' => '装不上(重试)'])
            ->assertStatus(201);

        expect($first->json('data.ticket_no'))->not->toBe($second->json('data.ticket_no'));

        expect(SupportTicket::where('customer_id', $customer->id)->count())->toBe(2);

        // 两条留言都在(第一条建单失败不能连留言一起丢)
        expect(DB::table('ticket_message')->count())->toBe(2);
    });
});

it('A2 · 带同一 Idempotency-Key 连发两次 → 返回首次工单,只落一行', function () {
    $customer = a2Customer();
    actingAs($customer, 'customer');

    $headers = ['Idempotency-Key' => 'weak-net-retry-0001'];

    $first = postJson(route('shop.api.tickets.store'), ['content' => '第一次'], $headers)
        ->assertStatus(201);

    $second = postJson(route('shop.api.tickets.store'), ['content' => '第二次'], $headers)
        ->assertStatus(200)                      // 回放不是创建
        ->assertJsonPath('idempotent', true);

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.ticket_no'))->toBe($first->json('data.ticket_no'));

    // 只落了一张单、一条留言
    expect(SupportTicket::where('customer_id', $customer->id)->count())->toBe(1)
        ->and(DB::table('ticket_message')->count())->toBe(1);
});

it('A2 · 不同客户的相同 Idempotency-Key 互不影响(键按客户隔离)', function () {
    $a = a2Customer();
    $b = a2Customer();

    actingAs($a, 'customer');
    $ra = postJson(route('shop.api.tickets.store'), ['content' => 'A'], ['Idempotency-Key' => 'same-key'])
        ->assertStatus(201);

    actingAs($b, 'customer');
    $rb = postJson(route('shop.api.tickets.store'), ['content' => 'B'], ['Idempotency-Key' => 'same-key'])
        ->assertStatus(201);

    expect($rb->json('data.id'))->not->toBe($ra->json('data.id'));
});

it('A2 · 单号唯一索引兜底:生成器撞号时重试换号,不 500', function () {
    $customer = a2Customer();
    actingAs($customer, 'customer');

    // 先占掉一个号,再让生成器第一次吐出这个号(第二次吐新号)—— 逼出"撞唯一键→重试"这条路
    SupportTicket::create([
        'ticket_no'   => 'T-FIXED-1',
        'customer_id' => $customer->id,
        'channel'     => 'app',
        'category'    => 'other',
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_OPEN,
    ]);

    $this->partialMock(TicketController::class, function ($mock) {
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('generateTicketNo')->andReturn('T-FIXED-1', 'T-FIXED-2');
    });

    postJson(route('shop.api.tickets.store'), ['content' => 'x'])
        ->assertStatus(201)
        ->assertJsonPath('data.ticket_no', 'T-FIXED-2');
});

// ───────────────────────── 事故报案 ─────────────────────────

it('A2 · 同用户同一秒连发两次报案 —— 不 500,且事故编号不同', function () {
    $customer = a2Customer();
    actingAs($customer, 'customer');

    test()->travelTo('2026-10-15 10:00:00', function () use ($customer) {
        $first = postJson(route('shop.api.incidents.store'), [
            'severity' => 'minor', 'description' => '第一次',
        ])->assertStatus(201);

        $second = postJson(route('shop.api.incidents.store'), [
            'severity' => 'minor', 'description' => '第二次',
        ])->assertStatus(201);

        expect($first->json('data.incident_no'))->not->toBe($second->json('data.incident_no'))
            ->and(Incident::where('customer_id', $customer->id)->count())->toBe(2);
    });
});

it('A2 · 事故编号生成器不再"先查再取"(同秒两次都产号,无需查重)', function () {
    $customer = a2Customer();

    $service = app(\Webkul\Shop\Services\Incident\IncidentService::class);

    $gen = new ReflectionMethod($service, 'generateNo');
    $gen->setAccessible(true);

    test()->travelTo('2026-10-15 10:00:00', function () use ($service, $gen, $customer) {
        $a = $gen->invoke($service, $customer->id);
        $b = $gen->invoke($service, $customer->id);

        expect($a)->toStartWith('I261015100000-'.$customer->id.'-')
            ->and($b)->toStartWith('I261015100000-'.$customer->id.'-')
            ->and($a)->not->toBe($b);
    });
});
