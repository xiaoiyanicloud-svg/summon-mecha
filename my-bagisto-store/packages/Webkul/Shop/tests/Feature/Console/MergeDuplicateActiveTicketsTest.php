<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

/**
 * 存量整理命令：一个客户只保留一张进行中会话。
 */

function mergeDupCustomer(): Customer
{
    return Customer::create([
        'first_name' => '合并',
        'last_name'  => '测试用户',
        'email'      => 'merge-'.uniqid().'@example.com',
        'password'   => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1,
        'is_verified'=> 1,
    ]);
}

function mergeDupTicket(Customer $customer): SupportTicket
{
    return SupportTicket::create([
        'ticket_no'   => testUniqueNo('MD'),
        'customer_id' => $customer->id,
        'channel'     => 'app',
        'service_mode'=> SupportTicket::SERVICE_ASYNC,
        'category'    => SupportTicket::CATEGORY_OTHER,
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_OPEN,
        'sla_due_at'  => now()->addHours(24),
    ]);
}

it('默认 dry-run 不关闭任何会话', function () {
    $customer = mergeDupCustomer();
    $keep = mergeDupTicket($customer);
    $duplicate = mergeDupTicket($customer);
    $duplicate->forceFill(['updated_at' => now()->subHour()])->save();

    $this->artisan('support:merge-duplicate-active')->assertSuccessful();

    expect($duplicate->fresh()->status)->toBe(SupportTicket::STATUS_OPEN)
        ->and($keep->fresh()->status)->toBe(SupportTicket::STATUS_OPEN);
});

it('执行后保留最近更新的一张，其余关闭并写入合并说明', function () {
    $customer = mergeDupCustomer();
    $keep = mergeDupTicket($customer);
    $duplicate = mergeDupTicket($customer);
    $duplicate->forceFill(['updated_at' => now()->subHour()])->save();

    $this->artisan('support:merge-duplicate-active', ['--execute' => true])->assertSuccessful();

    expect($keep->fresh()->status)->toBe(SupportTicket::STATUS_OPEN)
        ->and($duplicate->fresh()->status)->toBe(SupportTicket::STATUS_CLOSED)
        ->and($duplicate->fresh()->ended_at)->not->toBeNull();

    $notice = TicketMessage::query()
        ->where('ticket_id', $duplicate->id)
        ->where('actor_type', TicketMessage::ACTOR_SYSTEM)
        ->where('content', 'like', '%已合并至你当前的会话%')
        ->first();

    expect($notice)->not->toBeNull()
        ->and($notice->read_at)->not->toBeNull()
        ->and(DB::table('operation_log')->where('action', 'ticket.merge_duplicate')->where('target_id', $duplicate->id)->count())->toBe(1);
});
