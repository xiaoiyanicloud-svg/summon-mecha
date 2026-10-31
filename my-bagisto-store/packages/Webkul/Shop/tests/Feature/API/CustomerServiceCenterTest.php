<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 客服中心会话闭环：用户不仅能看到客服回复，也能在同一个会话里继续沟通。
 */

function customerServiceCustomer(): Customer
{
    return Customer::create([
        'first_name' => '客服',
        'last_name'  => '测试用户',
        'email'      => 'cs-'.uniqid().'@example.com',
        'password'   => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1,
        'is_verified'=> 1,
    ]);
}

it('用户可以继续发送消息，详情返回完整会话', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $created = postJson(route('shop.api.tickets.store'), [
        'content'  => '技能安装失败了',
        'category' => SupportTicket::CATEGORY_SKILL,
    ])->assertCreated();

    $ticketId = $created->json('data.id');

    postJson(route('shop.api.tickets.messages.store', $ticketId), [
        'content' => '补充一下，设备型号是 G1',
    ])->assertCreated()
        ->assertJsonPath('data.message.actor_type', 'customer')
        ->assertJsonPath('data.message.content', '补充一下，设备型号是 G1');

    getJson(route('shop.api.tickets.show', $ticketId))
        ->assertOk()
        ->assertJsonPath('data.can_reply', true)
        ->assertJsonCount(2, 'data.messages');

    expect(TicketMessage::where('ticket_id', $ticketId)->latest('id')->first()->created_at)->not->toBeNull();
});

it('已解决的会话收到用户补充后会自动重新打开并刷新 SLA', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'  => testUniqueNo('T'),
        'customer_id'=> $customer->id,
        'channel'    => 'app',
        'category'   => SupportTicket::CATEGORY_OTHER,
        'priority'   => 'normal',
        'status'     => SupportTicket::STATUS_RESOLVED,
        'sla_due_at' => now()->subDay(),
    ]);

    postJson(route('shop.api.tickets.messages.store', $ticket->id), [
        'content' => '问题又出现了',
    ])->assertCreated()
        ->assertJsonPath('data.status', SupportTicket::STATUS_PROCESSING);

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_PROCESSING)
        ->and($ticket->fresh()->sla_due_at->isFuture())->toBeTrue();
});

it('已关闭的会话拒绝继续发送，要求新建会话', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'  => testUniqueNo('T'),
        'customer_id'=> $customer->id,
        'channel'    => 'app',
        'category'   => SupportTicket::CATEGORY_OTHER,
        'priority'   => 'normal',
        'status'     => SupportTicket::STATUS_CLOSED,
    ]);

    postJson(route('shop.api.tickets.messages.store', $ticket->id), [
        'content' => '还在吗',
    ])->assertStatus(409)
        ->assertJsonPath('code', 'CONFLICT');

    expect(TicketMessage::where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('不能向其他用户的会话发送消息', function () {
    $owner = customerServiceCustomer();
    $other = customerServiceCustomer();

    $ticket = SupportTicket::create([
        'ticket_no'  => testUniqueNo('T'),
        'customer_id'=> $owner->id,
        'channel'    => 'app',
        'category'   => SupportTicket::CATEGORY_OTHER,
        'priority'   => 'normal',
        'status'     => SupportTicket::STATUS_OPEN,
    ]);

    actingAs($other, 'customer');

    postJson(route('shop.api.tickets.messages.store', $ticket->id), [
        'content' => '越权消息',
    ])->assertNotFound();

    expect(TicketMessage::where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('历史消息缺少 created_at 时，详情回退到工单时间', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'  => testUniqueNo('T'),
        'customer_id'=> $customer->id,
        'channel'    => 'app',
        'category'   => SupportTicket::CATEGORY_OTHER,
        'priority'   => 'normal',
        'status'     => SupportTicket::STATUS_OPEN,
    ]);

    TicketMessage::create([
        'ticket_id' => $ticket->id,
        'actor_type'=> 'customer',
        'actor_id'  => $customer->id,
        'content'   => '历史数据没有消息时间',
    ]);

    getJson(route('shop.api.tickets.show', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.messages.0.created_at', $ticket->created_at->toDateTimeString());
});

it('会话列表返回最后一条消息，供客服中心展示摘要', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $created = postJson(route('shop.api.tickets.store'), ['content' => '第一条消息'])->assertCreated();
    $ticketId = $created->json('data.id');

    postJson(route('shop.api.tickets.messages.store', $ticketId), ['content' => '最后一条消息'])->assertCreated();
    getJson(route('shop.api.tickets.index'))
        ->assertOk()
        ->assertJsonPath('data.0.last_message.content', '最后一条消息')
        ->assertJsonPath('data.0.last_message.actor_type', 'customer');
});
it('结单后用户可以评价一次，评价写回会话详情', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'   => testUniqueNo('T'),
        'customer_id' => $customer->id,
        'channel'     => 'app',
        'category'    => SupportTicket::CATEGORY_OTHER,
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_CLOSED,
        'sla_due_at'  => now()->subDay(),
        'ended_at'    => now()->subHour(),
    ]);

    postJson(route('shop.api.tickets.rate', $ticket->id), [
        'rating'  => 5,
        'tags'    => ['solved', 'fast'],
        'comment' => '处理很快',
    ])->assertOk()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.rating_tags.0', 'solved')
        ->assertJsonPath('data.can_rate', false);

    getJson(route('shop.api.tickets.show', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.rating_comment', '处理很快');

    expect($ticket->fresh()->rated_at)->not->toBeNull();
});

it('同一会话不能重复评价', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'   => testUniqueNo('T'),
        'customer_id' => $customer->id,
        'channel'     => 'app',
        'category'    => \Webkul\Shop\Models\SupportTicket::CATEGORY_OTHER,
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_RESOLVED,
    ]);

    postJson(route('shop.api.tickets.rate', $ticket->id), ['rating' => 4])->assertOk();

    postJson(route('shop.api.tickets.rate', $ticket->id), ['rating' => 1])
        ->assertStatus(409)
        ->assertJsonPath('code', \Webkul\Shop\Http\Controllers\API\ApiErrorCode::CONFLICT);
});

it('未结单的会话不能评价', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'   => testUniqueNo('T'),
        'customer_id' => $customer->id,
        'channel'     => 'app',
        'category'    => \Webkul\Shop\Models\SupportTicket::CATEGORY_OTHER,
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_PROCESSING,
    ]);

    postJson(route('shop.api.tickets.rate', $ticket->id), ['rating' => 5])
        ->assertStatus(422)
        ->assertJsonPath('code', \Webkul\Shop\Http\Controllers\API\ApiErrorCode::VALIDATION_ERROR);
});

it('不能评价其他用户的会话', function () {
    $owner = customerServiceCustomer();
    $other = customerServiceCustomer();
    actingAs($other, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'   => testUniqueNo('T'),
        'customer_id' => $owner->id,
        'channel'     => 'app',
        'category'    => \Webkul\Shop\Models\SupportTicket::CATEGORY_OTHER,
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_CLOSED,
    ]);

    postJson(route('shop.api.tickets.rate', $ticket->id), ['rating' => 5])
        ->assertStatus(404)
        ->assertJsonPath('code', \Webkul\Shop\Http\Controllers\API\ApiErrorCode::TICKET_NOT_FOUND);
});

it('评分必须是 1-5，标签必须在白名单内', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $ticket = SupportTicket::create([
        'ticket_no'   => testUniqueNo('T'),
        'customer_id' => $customer->id,
        'channel'     => 'app',
        'category'    => \Webkul\Shop\Models\SupportTicket::CATEGORY_OTHER,
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_CLOSED,
    ]);

    postJson(route('shop.api.tickets.rate', $ticket->id), ['rating' => 6])
        ->assertStatus(422);

    postJson(route('shop.api.tickets.rate', $ticket->id), ['rating' => 5, 'tags' => ['not-a-real-tag']])
        ->assertStatus(422);
});

it('商品详情咨询会以结构化商品卡写入会话并返回给用户', function () {
    $customer = customerServiceCustomer();
    actingAs($customer, 'customer');

    $product = (new \Webkul\Faker\Helpers\Product([
        'attributes' => [
            5  => 'new',
            11 => 'price',
        ],
        'attribute_value' => [
            'new'   => ['boolean_value' => true],
            'price' => ['float_value' => 899],
        ],
    ]))->getSimpleProductFactory()->create();

    $created = postJson(route('shop.api.tickets.store'), [
        'content'  => '咨询配件「机器人备用电池包」：接口是否匹配？',
        'category' => SupportTicket::CATEGORY_OTHER,
        'attachments' => [[
            'type'       => 'product',
            'product_id' => $product->id,
            'name'       => '机器人备用电池包 48V/20Ah',
            'sku'        => 'TEST-ACC-BATTERY',
            'price'      => '899',
            'image'      => 'https://example.com/battery.png',
            'kind'       => 'accessory',
        ]],
    ])->assertCreated();

    $ticketId = $created->json('data.id');

    getJson(route('shop.api.tickets.show', $ticketId))
        ->assertOk()
        ->assertJsonPath('data.messages.0.attachments.0.type', 'product')
        ->assertJsonPath('data.messages.0.attachments.0.product_id', $product->id)
        ->assertJsonPath('data.messages.0.attachments.0.name', '机器人备用电池包 48V/20Ah')
        ->assertJsonPath('data.messages.0.attachments.0.kind', 'accessory');

    expect(TicketMessage::where('ticket_id', $ticketId)->first()->attachments[0]['sku'])
        ->toBe('TEST-ACC-BATTERY');
});
