<?php

use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 客服工单受理台（v3.4 步骤 10 的平台端侧）。
 *
 * 在此之前：用户可以开单、SLA 已写库，但**平台端没有列表/详情/回复入口** ——
 * 工单进了一个没人看的池子。这个文件锁住"受理台真的能受理"。
 */

function stTicket(array $overrides = []): SupportTicket
{
    return SupportTicket::create(array_merge([
        'ticket_no'  => testUniqueNo('T'),
        'channel'    => 'app',
        'category'   => 'install_failed',
        'priority'   => 'normal',
        'status'     => SupportTicket::STATUS_OPEN,
        'sla_due_at' => now()->addHours(24),
    ], $overrides));
}

it('工单列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.customers.tickets.index'))
        ->assertOk()
        ->assertSeeText('客服工单');
});

it('工单详情页可以渲染，并显示已有对话', function () {
    $ticket = stTicket();

    TicketMessage::create([
        'ticket_id'  => $ticket->id,
        'actor_type' => 'customer',
        'actor_id'   => 1,
        'content'    => '装不上，提示固件版本不匹配',
        'created_at' => now(),
    ]);

    $this->loginAsAdmin();

    get(route('admin.customers.tickets.show', $ticket->id))
        ->assertOk()
        ->assertSeeText($ticket->ticket_no)
        ->assertSeeText('装不上，提示固件版本不匹配');
});

it('平台回复：写入 admin 消息，并把「待受理」推到「处理中」', function () {
    $ticket = stTicket();

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.tickets.reply', $ticket->id), [
        'content' => '请把设备型号和固件版本发我们看一下',
    ])->assertRedirect();

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_PROCESSING);

    expect(DB::table('ticket_message')
        ->where('ticket_id', $ticket->id)
        ->where('actor_type', 'admin')
        ->where('actor_id', $admin->id)
        ->exists())->toBeTrue();
});

it('平台回复：无人认领时自动指派给当前管理员', function () {
    $ticket = stTicket();

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.tickets.reply', $ticket->id), ['content' => '收到，我们查一下']);

    expect($ticket->fresh()->assignee_id)->toBe($admin->id);
});

it('平台回复：已有受理人时不抢单', function () {
    $other = $this->loginAsAdmin();

    $ticket = stTicket(['assignee_id' => $other->id, 'status' => SupportTicket::STATUS_PROCESSING]);

    // 换个管理员来回
    $this->loginAsAdmin();

    post(route('admin.customers.tickets.reply', $ticket->id), ['content' => '补充一句']);

    expect($ticket->fresh()->assignee_id)->toBe($other->id);
});

it('平台回复：内容必填', function () {
    $ticket = stTicket();

    $this->loginAsAdmin();

    post(route('admin.customers.tickets.reply', $ticket->id))
        ->assertSessionHasErrors('content');

    expect(DB::table('ticket_message')->where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('改状态与优先级', function () {
    $ticket = stTicket();

    $this->loginAsAdmin();

    post(route('admin.customers.tickets.status', $ticket->id), [
        'status'   => SupportTicket::STATUS_RESOLVED,
        'priority' => 'high',
    ])->assertRedirect();

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_RESOLVED)
        ->and($ticket->fresh()->priority)->toBe('high');
});

it('改状态：只接受白名单内的值', function () {
    $ticket = stTicket();

    $this->loginAsAdmin();

    post(route('admin.customers.tickets.status', $ticket->id), ['status' => 'whatever'])
        ->assertSessionHasErrors('status');

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_OPEN);
});

it('受理动作留痕到 operation_log', function () {
    $ticket = stTicket();

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.tickets.reply', $ticket->id), ['content' => '好的']);
    post(route('admin.customers.tickets.status', $ticket->id), ['status' => SupportTicket::STATUS_RESOLVED]);

    expect(DB::table('operation_log')
        ->where('action', 'ticket.reply')
        ->where('target_id', $ticket->id)
        ->where('actor_id', $admin->id)
        ->exists())->toBeTrue();

    expect(DB::table('operation_log')
        ->where('action', 'ticket.status')
        ->where('target_id', $ticket->id)
        ->exists())->toBeTrue();
});
