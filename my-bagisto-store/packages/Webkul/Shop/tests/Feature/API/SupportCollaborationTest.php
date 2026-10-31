<?php

use Illuminate\Support\Facades\Cache;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 客服协作（2026-10-27）用户侧边界：
 *   · 内部备注永远不出现在用户接口里；
 *   · 挂起对用户可见，用户回复自动恢复；
 *   · 输入中状态能通过现有增量同步读出来。
 */

function collabCustomer(): Customer
{
    return Customer::create([
        'first_name' => '协作',
        'last_name'  => '测试用户',
        'email'      => 'collab-'.uniqid().'@example.com',
        'password'   => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1,
        'is_verified'=> 1,
    ]);
}

function collabTicket(Customer $customer, array $overrides = []): SupportTicket
{
    return SupportTicket::create(array_merge([
        'ticket_no'   => testUniqueNo('CB'),
        'customer_id' => $customer->id,
        'channel'     => 'app',
        'service_mode'=> SupportTicket::SERVICE_ASYNC,
        'category'    => SupportTicket::CATEGORY_OTHER,
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_OPEN,
        'sla_due_at'  => now()->addHours(24),
    ], $overrides));
}

it('内部备注不会出现在用户列表、详情和增量同步里', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');
    $ticket = collabTicket($customer);

    TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => TicketMessage::ACTOR_CUSTOMER,
        'actor_id'   => $customer->id,
        'content'    => '用户问题',
        'created_at' => now(),
    ]);

    // 备注最后写入：列表摘要若取“最后一条”就会把它暴露出去。
    TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => TicketMessage::ACTOR_NOTE,
        'content'    => '内部备注：客户情绪比较急',
        'created_at' => now()->addSecond(),
        'read_at'    => now(),
    ]);

    getJson(route('shop.api.tickets.index'))
        ->assertOk()
        ->assertJsonPath('data.0.last_message.content', '用户问题')
        ->assertJsonPath('data.0.unread_count', 0);

    $show = getJson(route('shop.api.tickets.show', $ticket->id))
        ->assertOk()
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.content', '用户问题');

    expect($show->getContent())->not->toContain('内部备注');

    $updates = getJson(route('shop.api.tickets.updates', $ticket->id).'?after_message_id=0')
        ->assertOk()
        ->assertJsonCount(1, 'data.messages');

    expect($updates->getContent())->not->toContain('内部备注');
});

it('未读消息统计不会把内部备注算进去', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');
    $ticket = collabTicket($customer);

    TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => TicketMessage::ACTOR_ADMIN,
        'content'    => '客服回复',
        'created_at' => now(),
    ]);
    TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => TicketMessage::ACTOR_NOTE,
        'content'    => '内部备注',
        'created_at' => now()->addSecond(),
    ]);

    getJson(route('shop.api.tickets.index'))
        ->assertOk()
        ->assertJsonPath('data.0.unread_count', 1)
        ->assertJsonPath('data.0.last_message.content', '客服回复');
});

it('挂起状态对用户可见，用户回复后自动恢复', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');
    $ticket = collabTicket($customer, [
        'status'      => SupportTicket::STATUS_PROCESSING,
        'held_at'     => now(),
        'held_reason' => '等待客户提供设备序列号',
    ]);

    getJson(route('shop.api.tickets.show', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.held', true)
        ->assertJsonPath('data.status_label', '等待补充')
        // 内部挂起原因不对用户暴露
        ->assertJsonMissingPath('data.held_reason');

    postJson(route('shop.api.tickets.messages.store', $ticket->id), [
        'content' => '序列号是 SN-123456',
    ])->assertCreated();

    $ticket->refresh();

    expect($ticket->isHeld())->toBeFalse()
        ->and($ticket->held_reason)->toBeNull();

    getJson(route('shop.api.tickets.show', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.held', false);
});

it('客服输入中状态通过增量同步返回，并有 8 秒缓存语义', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');
    $ticket = collabTicket($customer);

    Cache::put(
        SupportTicket::typingCacheKey($ticket->id, 'staff'),
        ['id' => 1, 'name' => '客服'],
        now()->addSeconds(SupportTicket::TYPING_TTL_SECONDS)
    );

    getJson(route('shop.api.tickets.updates', $ticket->id).'?after_message_id=0')
        ->assertOk()
        ->assertJsonPath('data.staff_typing', true);

    Cache::forget(SupportTicket::typingCacheKey($ticket->id, 'staff'));

    getJson(route('shop.api.tickets.updates', $ticket->id).'?after_message_id=0')
        ->assertOk()
        ->assertJsonPath('data.staff_typing', false);
});

it('用户输入中心跳写入缓存，且只能写自己的会话', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');
    $ticket = collabTicket($customer);

    postJson(route('shop.api.tickets.typing', $ticket->id))->assertOk();

    expect(Cache::has(SupportTicket::typingCacheKey($ticket->id, 'customer')))->toBeTrue();

    $otherTicket = collabTicket(collabCustomer());

    postJson(route('shop.api.tickets.typing', $otherTicket->id))->assertNotFound();

    expect(Cache::has(SupportTicket::typingCacheKey($otherTicket->id, 'customer')))->toBeFalse();
});

it('同一客户再次发起咨询会续接到进行中的会话，不新建工单', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');

    $first = postJson(route('shop.api.tickets.store'), [
        'content'  => '设备连不上',
        'category' => SupportTicket::CATEGORY_DEVICE,
    ])->assertCreated();

    $ticketId = $first->json('data.id');

    postJson(route('shop.api.tickets.store'), [
        'content'  => '补充一下，是 G1 型号',
        'category' => SupportTicket::CATEGORY_DEVICE,
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $ticketId)
        ->assertJsonPath('data.resumed', true);

    expect(SupportTicket::where('customer_id', $customer->id)->count())->toBe(1)
        ->and(TicketMessage::where('ticket_id', $ticketId)->count())->toBe(2);
});

it('无人接待的留言单在用户改选在线客服时升级为在线排队', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');

    $created = postJson(route('shop.api.tickets.store'), [
        'content' => '先留个言',
    ])->assertCreated();

    $ticketId = $created->json('data.id');

    postJson(route('shop.api.tickets.online'), [
        'content' => '现在想找人工',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $ticketId)
        ->assertJsonPath('data.resumed', true)
        ->assertJsonPath('data.service_mode', SupportTicket::SERVICE_ONLINE);

    $ticket = SupportTicket::find($ticketId);

    expect($ticket->service_mode)->toBe(SupportTicket::SERVICE_ONLINE)
        ->and($ticket->queued_at)->not->toBeNull()
        ->and($ticket->status)->toBe(SupportTicket::STATUS_OPEN);
});

it('已解决会话在存在进行中会话时会把补充消息转投当前会话', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');

    $resolved = collabTicket($customer, ['status' => SupportTicket::STATUS_RESOLVED]);
    $active = collabTicket($customer, ['status' => SupportTicket::STATUS_OPEN]);

    postJson(route('shop.api.tickets.messages.store', $resolved->id), [
        'content' => '补充一句',
    ])
        ->assertCreated()
        ->assertJsonPath('data.ticket_id', $active->id)
        ->assertJsonPath('data.redirect_ticket_id', $active->id);

    expect($resolved->fresh()->status)->toBe(SupportTicket::STATUS_RESOLVED)
        ->and(TicketMessage::where('ticket_id', $resolved->id)->count())->toBe(0)
        ->and(TicketMessage::where('ticket_id', $active->id)->count())->toBe(1);
});

it('增量同步会单独下发用户消息的已读回执', function () {
    $customer = collabCustomer();
    actingAs($customer, 'customer');
    $ticket = collabTicket($customer);

    $message = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => TicketMessage::ACTOR_CUSTOMER,
        'actor_id'   => $customer->id,
        'content'    => '我的消息',
        'created_at' => now(),
    ]);

    getJson(route('shop.api.tickets.updates', $ticket->id).'?after_message_id=0')
        ->assertOk()
        ->assertJsonCount(0, 'data.receipts');

    $message->forceFill(['read_at' => now()])->save();

    getJson(route('shop.api.tickets.updates', $ticket->id).'?after_message_id=0')
        ->assertOk()
        ->assertJsonPath('data.receipts.0.id', $message->id)
        ->assertJsonPath('data.receipts.0.read_at', fn ($value) => $value !== null);
});
