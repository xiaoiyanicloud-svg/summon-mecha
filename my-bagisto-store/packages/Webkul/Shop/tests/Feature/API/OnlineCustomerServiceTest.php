<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SupportAgentState;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 在线客服用户侧：真实在线状态、排队、增量同步、已读与结束。
 */

function onlineServiceCustomer(): Customer
{
    return Customer::create([
        'first_name' => '在线',
        'last_name'  => '客户',
        'email'      => 'online-cs-'.uniqid().'@example.com',
        'password'   => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1,
        'is_verified'=> 1,
    ]);
}

function onlineServiceAgent(int $adminId = 90001): SupportAgentState
{
    return SupportAgentState::updateOrCreate(
        ['admin_id' => $adminId],
        [
            'is_online'      => true,
            'max_concurrent' => 5,
            'last_seen_at'   => now(),
        ]
    );
}

function onlineServiceTicket(Customer $customer, array $overrides = []): SupportTicket
{
    return SupportTicket::create(array_merge([
        'ticket_no'                => testUniqueNo('O'),
        'customer_id'              => $customer->id,
        'channel'                  => 'app',
        'service_mode'             => SupportTicket::SERVICE_ONLINE,
        'category'                 => SupportTicket::CATEGORY_OTHER,
        'priority'                 => 'normal',
        'status'                   => SupportTicket::STATUS_OPEN,
        'queued_at'                => now(),
        'last_customer_message_at' => now(),
        'sla_due_at'               => now()->addHours(24),
    ], $overrides));
}

it('没有新鲜客服心跳时，在线状态为离线', function () {
    $customer = onlineServiceCustomer();
    actingAs($customer, 'customer');

    SupportAgentState::create([
        'admin_id'      => 90002,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now()->subMinutes(2),
    ]);

    getJson(route('shop.api.tickets.online-status'))
        ->assertOk()
        ->assertJsonPath('data.online', false)
        ->assertJsonPath('data.agents_online', 0);
});

it('有在线客服时返回真实在线人数和队列长度', function () {
    $customer = onlineServiceCustomer();
    actingAs($customer, 'customer');

    // 用例跑在开发库上（DatabaseTransactions 逐条回滚），库里可能本来就有排队中的会话 / 在线客服。
    // 断言「新增一张，队列 +1」，而不是写死 1 —— 后者会因为无关的历史数据变红。
    $baseline = SupportTicket::query()
        ->where('service_mode', SupportTicket::SERVICE_ONLINE)
        ->where('status', SupportTicket::STATUS_OPEN)
        ->count();

    $agentsBaseline = SupportAgentState::query()->fresh()->count();

    onlineServiceAgent();
    onlineServiceTicket($customer);

    getJson(route('shop.api.tickets.online-status'))
        ->assertOk()
        ->assertJsonPath('data.online', true)
        ->assertJsonPath('data.agents_online', $agentsBaseline + 1)
        ->assertJsonPath('data.queue_size', $baseline + 1);
});

it('无客服在线时仍可创建在线咨询并进入离线队列', function () {
    $customer = onlineServiceCustomer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.tickets.online'), ['content' => '在线咨询'])
        ->assertCreated()
        ->assertJsonPath('data.service_mode', SupportTicket::SERVICE_ONLINE)
        ->assertJsonPath('data.status_label', '排队中');

    $ticket = SupportTicket::findOrFail($res->json('data.id'));

    expect($ticket->queued_at)->not->toBeNull()
        ->and($ticket->last_customer_message_at)->not->toBeNull();

    // 另一个客户开留言单：在线/留言是“同一客户只允许一张进行中”之外的独立客户维度
    $otherCustomer = onlineServiceCustomer();
    actingAs($otherCustomer, 'customer');

    postJson(route('shop.api.tickets.store'), ['content' => '留言咨询'])
        ->assertCreated()
        ->assertJsonPath('data.service_mode', SupportTicket::SERVICE_ASYNC);
});

it('有客服在线时创建在线咨询并进入队列', function () {
    $customer = onlineServiceCustomer();
    actingAs($customer, 'customer');
    onlineServiceAgent();

    $res = postJson(route('shop.api.tickets.online'), [
        'content'  => '设备连不上，需要在线协助',
        'category' => SupportTicket::CATEGORY_DEVICE,
    ])->assertCreated()
        ->assertJsonPath('data.service_mode', SupportTicket::SERVICE_ONLINE)
        ->assertJsonPath('data.status_label', '排队中');

    $ticket = SupportTicket::findOrFail($res->json('data.id'));

    expect($ticket->queued_at)->not->toBeNull()
        ->and($ticket->last_customer_message_at)->not->toBeNull();
});

it('增量同步只返回 after_message_id 之后的消息', function () {
    $customer = onlineServiceCustomer();
    actingAs($customer, 'customer');
    $ticket = onlineServiceTicket($customer);

    $first = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => 'customer',
        'actor_id'   => $customer->id,
        'content'    => '第一条',
        'created_at' => now(),
    ]);
    $second = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => 'admin',
        'actor_id'   => 90001,
        'content'    => '第二条',
        'created_at' => now(),
    ]);

    getJson(route('shop.api.tickets.updates', $ticket->id).'?after_message_id='.$first->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.id', $second->id)
        ->assertJsonPath('data.status_label', '排队中');
});

it('用户已读会标记客服和系统消息', function () {
    $customer = onlineServiceCustomer();
    actingAs($customer, 'customer');
    $ticket = onlineServiceTicket($customer);

    $message = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => 'admin',
        'actor_id'   => 90001,
        'content'    => '客服回复',
        'created_at' => now(),
    ]);

    postJson(route('shop.api.tickets.read', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.marked_read', 1);

    expect($message->fresh()->read_at)->not->toBeNull();
});

it('用户主动结束在线会话后禁止继续发送', function () {
    $customer = onlineServiceCustomer();
    actingAs($customer, 'customer');
    $ticket = onlineServiceTicket($customer);

    postJson(route('shop.api.tickets.end', $ticket->id))->assertOk();

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_CLOSED)
        ->and($ticket->fresh()->ended_at)->not->toBeNull();

    postJson(route('shop.api.tickets.messages.store', $ticket->id), ['content' => '还能继续吗'])
        ->assertStatus(409);
});

it('已解决在线会话会根据是否已有受理人重新排队或接入', function () {
    // 两条分支必须用不同客户：同一客户现在只允许一张进行中会话，
    // 先重开 queued 后再回复 accepted 会被“转投当前会话”接走（新规则，不是旧行为）。
    $queuedCustomer = onlineServiceCustomer();
    actingAs($queuedCustomer, 'customer');

    $queued = onlineServiceTicket($queuedCustomer, [
        'status'      => SupportTicket::STATUS_RESOLVED,
        'assignee_id' => null,
        'sla_due_at'  => now()->subDay(),
    ]);

    postJson(route('shop.api.tickets.messages.store', $queued->id), ['content' => '又出现了'])->assertCreated();

    $acceptedCustomer = onlineServiceCustomer();
    actingAs($acceptedCustomer, 'customer');

    $accepted = onlineServiceTicket($acceptedCustomer, [
        'status'      => SupportTicket::STATUS_RESOLVED,
        'assignee_id' => 90001,
        'accepted_at' => now()->subHour(),
        'sla_due_at'  => now()->subDay(),
    ]);

    postJson(route('shop.api.tickets.messages.store', $accepted->id), ['content' => '再补充一下'])->assertCreated();

    expect($queued->fresh()->status)->toBe(SupportTicket::STATUS_OPEN)
        ->and($queued->fresh()->queued_at)->not->toBeNull()
        ->and($accepted->fresh()->status)->toBe(SupportTicket::STATUS_PROCESSING);
});