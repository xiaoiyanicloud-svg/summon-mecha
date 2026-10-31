<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SupportAgentState;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;
use Webkul\User\Models\Admin as AdminModel;
use Webkul\User\Models\Role;

use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * 在线客服后台侧：上线心跳、原子认领、释放和回复边界。
 */

function onlineAdminUser(): AdminModel
{
    $role = Role::firstOrCreate(
        ['name' => '在线客服测试角色'],
        [
            'description'     => 'Online customer service tests',
            'permission_type' => 'all',
        ]
    );

    return AdminModel::factory()->create(['role_id' => $role->id]);
}

function onlineAdminCustomer(): Customer
{
    return Customer::create([
        'first_name' => '后台',
        'last_name'  => '客户',
        'email'      => 'admin-online-'.uniqid().'@example.com',
        'password'   => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1,
        'is_verified'=> 1,
    ]);
}

function onlineAdminTicket(array $overrides = []): SupportTicket
{
    $customer = $overrides['customer'] ?? onlineAdminCustomer();
    unset($overrides['customer']);

    return SupportTicket::create(array_merge([
        'ticket_no'                => testUniqueNo('OA'),
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

it('客服可以上线并通过心跳保持在线', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());

    postJson(route('admin.customers.support.presence'), [
        'is_online'      => true,
        'max_concurrent' => 4,
    ])->assertOk()
        ->assertJsonPath('data.is_online', true)
        ->assertJsonPath('data.max_concurrent', 4);

    expect(SupportAgentState::where('admin_id', $admin->id)->first()->isFresh())->toBeTrue();
});

it('客服可以在线认领排队会话', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $admin->id,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now(),
    ]);
    $ticket = onlineAdminTicket();

    post(route('admin.customers.tickets.claim', $ticket->id))->assertRedirect();

    expect($ticket->fresh()->assignee_id)->toBe($admin->id)
        ->and($ticket->fresh()->status)->toBe(SupportTicket::STATUS_PROCESSING)
        ->and($ticket->fresh()->accepted_at)->not->toBeNull();
});

it('同一条在线会话不能被第二个客服重复认领', function () {
    $first = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $first->id,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now(),
    ]);
    $ticket = onlineAdminTicket();
    post(route('admin.customers.tickets.claim', $ticket->id))->assertRedirect();

    $second = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $second->id,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now(),
    ]);

    post(route('admin.customers.tickets.claim', $ticket->id))
        ->assertRedirect(route('admin.customers.tickets.show', $ticket->id))
        ->assertSessionHas('error');

    expect($ticket->fresh()->assignee_id)->toBe($first->id);
});

it('已接入其他客服的在线会话不能被代答', function () {
    $owner = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $owner->id,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now(),
    ]);
    $ticket = onlineAdminTicket([
        'assignee_id' => $owner->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    $other = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $other->id,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now(),
    ]);

    postJson(route('admin.customers.tickets.reply', $ticket->id), ['content' => '代答'])
        ->assertStatus(409);

    expect(TicketMessage::where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('客服可以释放自己的在线会话并放回队列', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $admin->id,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now(),
    ]);
    $ticket = onlineAdminTicket([
        'assignee_id' => $admin->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    post(route('admin.customers.tickets.release', $ticket->id))->assertRedirect();

    expect($ticket->fresh()->assignee_id)->toBeNull()
        ->and($ticket->fresh()->status)->toBe(SupportTicket::STATUS_OPEN)
        ->and($ticket->fresh()->queued_at)->not->toBeNull();
});

it('接客上限用尽后不能再认领新的在线会话', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $admin->id,
        'is_online'     => true,
        'max_concurrent'=> 1,
        'last_seen_at'  => now(),
    ]);
    onlineAdminTicket([
        'assignee_id' => $admin->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);
    $queued = onlineAdminTicket();

    post(route('admin.customers.tickets.claim', $queued->id))
        ->assertRedirect(route('admin.customers.tickets.show', $queued->id))
        ->assertSessionHas('error');

    expect($queued->fresh()->assignee_id)->toBeNull();
});
it('在线客服回复会写入消息并推进为已接入', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'      => $admin->id,
        'is_online'     => true,
        'max_concurrent'=> 5,
        'last_seen_at'  => now(),
    ]);
    $ticket = onlineAdminTicket([
        'assignee_id' => $admin->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    postJson(route('admin.customers.tickets.reply', $ticket->id), ['content' => '我看到问题了，正在帮你检查。'])
        ->assertCreated()
        ->assertJsonPath('data.message.actor_type', 'admin');

    expect($ticket->fresh()->last_staff_message_at)->not->toBeNull()
        ->and(TicketMessage::where('ticket_id', $ticket->id)->where('actor_type', 'admin')->count())->toBe(1);
});

it('后台增量同步只返回新消息', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket();
    $first = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => 'customer',
        'content'    => '第一条',
        'created_at' => now(),
    ]);
    $second = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => 'customer',
        'content'    => '第二条',
        'created_at' => now(),
    ]);

    getJson(route('admin.customers.tickets.updates', $ticket->id).'?after_message_id='.$first->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.id', $second->id);
});

it('后台已读会标记用户消息', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket();
    $message = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => 'customer',
        'content'    => '用户问题',
        'created_at' => now(),
    ]);

    postJson(route('admin.customers.tickets.read', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.marked_read', 1);

    expect($message->fresh()->read_at)->not->toBeNull();
});
it('客户收件箱按客户聚合未读与等待时长', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'       => $admin->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);

    $customer = onlineAdminCustomer();

    $online = onlineAdminTicket([
        'customer'                 => $customer,
        'last_customer_message_at' => now()->subMinutes(20),
        'queued_at'                => now()->subMinutes(20),
    ]);
    TicketMessage::create([
        'ticket_id'  => $online->id,
        'actor_type' => 'customer',
        'content'    => '在线咨询问题',
        'created_at' => now()->subMinutes(20),
    ]);

    $async = onlineAdminTicket([
        'customer'                 => $customer,
        'service_mode'             => SupportTicket::SERVICE_ASYNC,
        'status'                   => SupportTicket::STATUS_PROCESSING,
        'assignee_id'              => $admin->id,
        'last_customer_message_at' => now()->subMinutes(5),
    ]);
    TicketMessage::create([
        'ticket_id'  => $async->id,
        'actor_type' => 'customer',
        'content'    => '留言补充',
        'created_at' => now()->subMinutes(5),
    ]);

    $other = onlineAdminCustomer();
    onlineAdminTicket([
        'customer'                 => $other,
        'service_mode'             => SupportTicket::SERVICE_ASYNC,
        'status'                   => SupportTicket::STATUS_OPEN,
        'last_customer_message_at' => now()->subMinutes(30),
    ]);

    // 本机库可能还有其他真实会话，因此这里只断言“至少包含本用例创建的”，
    // 不断言全局精确数量 —— 否则测试会依赖库里干不干净。
    $response = getJson(route('admin.customers.support.conversations').'?ticket_id='.$async->id)
        ->assertOk();

    $data = $response->json('data');
    $html = $data['html'];

    expect($data['counts']['total'])->toBeGreaterThanOrEqual(2)
        ->and($data['counts']['unread'])->toBeGreaterThanOrEqual(1)
        ->and($data['counts']['queue'])->toBeGreaterThanOrEqual(1)
        ->and($data['counts']['mine'])->toBeGreaterThanOrEqual(1)
        ->and($html)->toContain('data-inbox-row')
        ->and($html)->toContain('data-customer-id="'.$customer->id.'"')
        ->and($html)->toContain('data-mine="1"')
        ->and($html)->toContain('2 个会话')
        // 同一客户的两张会话未读合并为 2
        ->and($html)->toMatch('/data-customer-id="'.$customer->id.'"[^>]*data-unread="2"/s');
});

it('客户历史列出同一客户的其他工单', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $customer = onlineAdminCustomer();

    $active = onlineAdminTicket(['customer' => $customer]);
    $old = onlineAdminTicket([
        'customer'     => $customer,
        'service_mode' => SupportTicket::SERVICE_ASYNC,
        'status'       => SupportTicket::STATUS_CLOSED,
    ]);

    $this->get(route('admin.customers.tickets.show', $active->id))
        ->assertOk()
        ->assertSee('客户历史')
        ->assertSee($old->ticket_no)
        ->assertSee('data-inbox-filter="waiting"', false);
});

it('embed=1 只返回工作台主体，不套后台布局', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket();

    $response = $this->get(route('admin.customers.tickets.show', $ticket->id).'?embed=1')
        ->assertOk()
        ->assertSee('id="support-workspace"', false)
        ->assertSee('data-support-config', false);

    $response->assertDontSee('<html', false)
        ->assertDontSee('超级搜索', false);
});

it('认领和释放会话支持 JSON 响应', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'       => $admin->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);
    $ticket = onlineAdminTicket();

    postJson(route('admin.customers.tickets.claim', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.assignee_id', $admin->id)
        ->assertJsonPath('data.status', SupportTicket::STATUS_PROCESSING);

    postJson(route('admin.customers.tickets.release', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.assignee_id', null)
        ->assertJsonPath('data.status', SupportTicket::STATUS_OPEN);
});

it('处置状态支持 JSON 响应', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket(['assignee_id' => $admin->id]);

    postJson(route('admin.customers.tickets.status', $ticket->id), [
        'status'   => SupportTicket::STATUS_RESOLVED,
        'priority' => 'high',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', SupportTicket::STATUS_RESOLVED)
        ->assertJsonPath('data.status_label', '已解决')
        ->assertJsonPath('data.priority_label', '高');

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_RESOLVED);
});

it('在线会话可以转接给在线且未满负荷的客服', function () {
    $from = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'       => $from->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);

    $to = onlineAdminUser();
    SupportAgentState::create([
        'admin_id'       => $to->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);

    $ticket = onlineAdminTicket([
        'assignee_id' => $from->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    postJson(route('admin.customers.tickets.transfer', $ticket->id), ['assignee_id' => $to->id])
        ->assertOk()
        ->assertJsonPath('data.assignee_id', $to->id);

    expect($ticket->fresh()->assignee_id)->toBe($to->id)
        ->and(TicketMessage::where('ticket_id', $ticket->id)->where('actor_type', 'system')->count())->toBe(1);
});

it('在线会话不能转给离线客服', function () {
    $from = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'       => $from->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);
    $offline = onlineAdminUser();
    $ticket = onlineAdminTicket([
        'assignee_id' => $from->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    postJson(route('admin.customers.tickets.transfer', $ticket->id), ['assignee_id' => $offline->id])
        ->assertStatus(409)
        ->assertJsonPath('message', fn ($message) => str_contains($message, '不在线'));

    expect($ticket->fresh()->assignee_id)->toBe($from->id);
});

it('挂起后进入已挂起分组，恢复后回到我的接待', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'       => $admin->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);
    $ticket = onlineAdminTicket([
        'assignee_id' => $admin->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    postJson(route('admin.customers.tickets.hold', $ticket->id), ['reason' => '等用户提供序列号'])
        ->assertOk()
        ->assertJsonPath('data.held', true)
        // 挂起不覆盖真实状态：状态徽标仍显示「已接入」，挂起是单独的琥珀色徽标
        ->assertJsonPath('data.status_label', '已接入');

    expect($ticket->fresh()->isHeld())->toBeTrue()
        ->and($ticket->fresh()->held_reason)->toBe('等用户提供序列号');

    getJson(route('admin.customers.support.conversations').'?ticket_id='.$ticket->id)
        ->assertOk()
        ->assertJsonPath('data.counts.held', fn ($count) => $count >= 1)
        ->assertJsonPath('data.html', fn ($html) => str_contains($html, $ticket->ticket_no));

    postJson(route('admin.customers.tickets.resume', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.held', false);

    expect($ticket->fresh()->isHeld())->toBeFalse()
        ->and($ticket->fresh()->held_reason)->toBeNull();
});

it('内部备注不会刷新客服回复时间，也不会推进状态', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket(['assignee_id' => $admin->id]);

    postJson(route('admin.customers.tickets.notes.store', $ticket->id), ['content' => '客户情绪比较急，注意安抚'])
        ->assertCreated()
        ->assertJsonPath('data.message.actor_type', 'note');

    $ticket->refresh();

    expect($ticket->last_staff_message_at)->toBeNull()
        ->and($ticket->status)->toBe(SupportTicket::STATUS_OPEN)
        ->and(TicketMessage::where('ticket_id', $ticket->id)->where('actor_type', 'note')->count())->toBe(1);

    // 后台增量同步需要能看到备注（客服侧可见）
    getJson(route('admin.customers.tickets.updates', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.messages.0.actor_type', 'note');
});

it('快捷话术事件只记录配置里存在的 key', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket(['assignee_id' => $admin->id]);

    postJson(route('admin.customers.support.quick-replies.event'), [
        'key'       => 'common.received',
        'event'     => 'insert',
        'ticket_id' => $ticket->id,
    ])->assertOk();

    postJson(route('admin.customers.support.quick-replies.event'), [
        'key'   => 'not.exists',
        'event' => 'insert',
    ])->assertOk();

    expect(DB::table('support_quick_reply_event')->where('reply_key', 'common.received')->count())->toBe(1)
        ->and(DB::table('support_quick_reply_event')->where('reply_key', 'not.exists')->count())->toBe(0);
});

it('带快捷话术 key 的回复会记录发送事件', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'       => $admin->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);
    $ticket = onlineAdminTicket([
        'assignee_id' => $admin->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    postJson(route('admin.customers.tickets.reply', $ticket->id), [
        'content'         => '已收到，我们正在为你排查。',
        'quick_reply_key' => 'common.received',
    ])->assertCreated();

    expect(DB::table('support_quick_reply_event')
        ->where('reply_key', 'common.received')
        ->where('event', 'send')
        ->count())->toBe(1);
});

it('工作台按工单类别下发快捷话术，并提供内部备注入口', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket([
        'assignee_id' => $admin->id,
        'category'    => SupportTicket::CATEGORY_DELIVERY,
    ]);

    $this->get(route('admin.customers.tickets.show', $ticket->id))
        ->assertOk()
        ->assertSee('data-reply-key="delivery.timeout"', false)
        ->assertSee('data-reply-key="common.received"', false)
        ->assertSee('内部备注', false);
});

it('话术效果按插入与发送计算采纳率', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket(['assignee_id' => $admin->id]);

    foreach (['insert', 'insert', 'send'] as $event) {
        DB::table('support_quick_reply_event')->insert([
            'reply_key'  => 'common.received',
            'event'      => $event,
            'admin_id'   => $admin->id,
            'ticket_id'  => $ticket->id,
            'category'   => $ticket->category,
            'created_at' => now(),
        ]);
    }

    $this->get(route('admin.customers.tickets.show', $ticket->id))
        ->assertOk()
        ->assertSee('插入 2 · 发出 1 · 采纳 50%', false);
});

it('客服输入中心跳写入缓存，回复后清除', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    SupportAgentState::create([
        'admin_id'       => $admin->id,
        'is_online'      => true,
        'max_concurrent' => 5,
        'last_seen_at'   => now(),
    ]);
    $ticket = onlineAdminTicket([
        'assignee_id' => $admin->id,
        'status'      => SupportTicket::STATUS_PROCESSING,
        'accepted_at' => now(),
    ]);

    postJson(route('admin.customers.tickets.typing', $ticket->id))->assertOk();

    expect(Cache::has(SupportTicket::typingCacheKey($ticket->id, 'staff')))->toBeTrue();

    postJson(route('admin.customers.tickets.reply', $ticket->id), ['content' => '收到'])
        ->assertCreated();

    expect(Cache::has(SupportTicket::typingCacheKey($ticket->id, 'staff')))->toBeFalse();
});

it('后台增量同步会下发客服消息的已读回执', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket();

    $message = TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => TicketMessage::ACTOR_ADMIN,
        'actor_id'   => $admin->id,
        'content'    => '客服消息',
        'created_at' => now(),
    ]);

    getJson(route('admin.customers.tickets.updates', $ticket->id))
        ->assertOk()
        ->assertJsonCount(0, 'data.receipts');

    $message->forceFill(['read_at' => now()])->save();

    getJson(route('admin.customers.tickets.updates', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.receipts.0.id', $message->id)
        ->assertJsonPath('data.receipts.0.read_at', fn ($value) => $value !== null);
});

it('后台增量同步会下发用户正在输入状态', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket();

    Cache::put(
        SupportTicket::typingCacheKey($ticket->id, 'customer'),
        ['id' => 1, 'name' => '用户'],
        now()->addSeconds(SupportTicket::TYPING_TTL_SECONDS)
    );

    getJson(route('admin.customers.tickets.updates', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.customer_typing', true);

    Cache::forget(SupportTicket::typingCacheKey($ticket->id, 'customer'));

    getJson(route('admin.customers.tickets.updates', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.customer_typing', false);
});

it('后台客服工作台展示商品卡并提供商品编辑入口', function () {
    $admin = $this->loginAsAdmin(onlineAdminUser());
    $ticket = onlineAdminTicket(['assignee_id' => $admin->id]);

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

    TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => TicketMessage::ACTOR_CUSTOMER,
        'actor_id'   => $ticket->customer_id,
        'content'    => '咨询这个配件',
        'attachments' => [[
            'type'       => 'product',
            'product_id' => $product->id,
            'name'       => '后台可见商品卡',
            'sku'        => 'ADMIN-CARD-001',
            'price'      => '899',
            'image'      => 'https://example.com/battery.png',
            'kind'       => 'accessory',
        ]],
        'created_at' => now(),
    ]);

    getJson(route('admin.customers.tickets.updates', $ticket->id))
        ->assertOk()
        ->assertJsonPath('data.messages.0.attachments.0.type', 'product')
        ->assertJsonPath('data.messages.0.attachments.0.admin_url', route('admin.catalog.products.edit', $product->id));

    $this->get(route('admin.customers.tickets.show', $ticket->id))
        ->assertOk()
        ->assertSee('后台可见商品卡', false)
        ->assertSee('support-product-attachment', false)
        ->assertSee('ADMIN-CARD-001', false);
});
