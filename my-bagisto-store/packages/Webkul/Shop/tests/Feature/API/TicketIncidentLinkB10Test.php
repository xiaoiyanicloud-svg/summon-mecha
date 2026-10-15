<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Incident;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * B-10 · 工单 ↔ 事故互链(用户侧)。
 *
 * 改前 `Services/Incident/` 与工单服务零互引 —— 用户开的工单与事故是两条不相交的线,
 * 计划要求的链「用户投诉 → 工单 → 若定性事故 → incident」没有落地。
 *
 * 这里覆盖用户侧:
 *   - 报案可带 `ticket_id`(只允许自己的工单);
 *   - 工单详情 GET /api/tickets/{id} 如实回显"已转为事故"(编号 + 严重度 + 状态)。
 */

function b10Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'B10',
        'last_name'   => 'User',
        'email'       => 'b10'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function b10Ticket(int $customerId): int
{
    return (int) DB::table('support_ticket')->insertGetId([
        'ticket_no'   => 'T'.uniqid(),
        'customer_id' => $customerId,
        'channel'     => 'app',
        'category'    => 'other',
        'priority'    => 'normal',
        'status'      => 'open',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

it('B-10 · 报案可关联自己的工单,ticket_id 落库', function () {
    $customer = b10Customer();
    actingAs($customer, 'customer');
    $ticketId = b10Ticket($customer->id);

    postJson(route('shop.api.incidents.store'), [
        'severity'    => 'moderate',
        'description' => '机器人在展会上撞到了人',
        'ticket_id'   => $ticketId,
    ])->assertStatus(201);

    $incident = Incident::where('customer_id', $customer->id)->first();

    expect($incident)->not->toBeNull()
        ->and($incident->ticket_id)->toBe($ticketId);
});

it('B-10 · 关联别人的工单 → 422,不落事故', function () {
    $me = b10Customer();
    $other = b10Customer();
    $foreignTicket = b10Ticket($other->id);

    actingAs($me, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => 'minor',
        'description' => '试图挂到别人的工单',
        'ticket_id'   => $foreignTicket,
    ])->assertStatus(422);

    expect(Incident::where('customer_id', $me->id)->count())->toBe(0);
});

it('B-10 · 工单详情回显来源事故(编号/严重度/状态)', function () {
    $customer = b10Customer();
    actingAs($customer, 'customer');
    $ticketId = b10Ticket($customer->id);

    // 直接造一起"由工单定性而来"的事故
    Incident::create([
        'incident_no'  => 'I'.uniqid(),
        'customer_id'  => $customer->id,
        'ticket_id'    => $ticketId,
        'severity'     => 'severe',
        'occurred_at'  => now(),
        'description'  => '由工单升级',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'       => Incident::STATUS_INVESTIGATING,
    ]);

    $res = getJson(route('shop.api.tickets.show', $ticketId))->assertOk();

    expect($res->json('data.incident'))->not->toBeNull()
        ->and($res->json('data.incident.severity_label'))->toBe('严重')
        ->and($res->json('data.incident.status_label'))->toBe('调查中');
});

it('B-10 · 未定性事故的工单,detail.incident 为 null(不编)', function () {
    $customer = b10Customer();
    actingAs($customer, 'customer');
    $ticketId = b10Ticket($customer->id);

    $res = getJson(route('shop.api.tickets.show', $ticketId))->assertOk();

    expect($res->json('data.incident'))->toBeNull();
});
