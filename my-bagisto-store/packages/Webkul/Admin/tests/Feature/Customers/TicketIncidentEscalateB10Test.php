<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\SupportTicket;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * B-10 · 工单 → 事故(平台端「定性为事故」)。
 *
 * 计划要求的链:**用户投诉 → 工单 → 若定性事故 → incident**。
 * 这是工单升级成事故的唯一入口 —— 走与用户报案同一个 `IncidentService::report()`,
 * 并在 incident 侧记下肇事工单 `ticket_id`(单向存储,不双写)。
 */

function b10AdminCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'B10',
        'last_name'   => 'Ticket',
        'email'       => 'b10a'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function b10AdminTicket(?int $customerId = null): SupportTicket
{
    return SupportTicket::create([
        'ticket_no'   => 'T'.uniqid(),
        'customer_id' => $customerId,
        'channel'     => 'app',
        'category'    => 'other',
        'priority'    => 'normal',
        'status'      => SupportTicket::STATUS_OPEN,
        'sla_due_at'  => now()->addHours(24),
    ]);
}

it('B-10 · 定性为事故:创建 incident 并回写 ticket_id,工单转「处理中」+ 系统留言', function () {
    $customer = b10AdminCustomer();
    $ticket = b10AdminTicket($customer->id);

    $this->loginAsAdmin();

    post(route('admin.customers.tickets.escalate', $ticket->id), [
        'severity'    => 'severe',
        'description' => '经核实为设备伤人,定性为事故',
    ])->assertRedirect();

    $incident = Incident::where('ticket_id', $ticket->id)->first();

    expect($incident)->not->toBeNull()
        ->and($incident->customer_id)->toBe($customer->id)
        ->and($incident->severity)->toBe('severe');

    $ticket->refresh();
    expect($ticket->status)->toBe(SupportTicket::STATUS_PROCESSING);

    // 工单上留了一条 system 留言(说明转事故了)
    expect(DB::table('ticket_message')->where('ticket_id', $ticket->id)->where('actor_type', 'system')->count())->toBe(1);
});

it('B-10 · 不能重复定性(一张工单最多一起事故)', function () {
    $customer = b10AdminCustomer();
    $ticket = b10AdminTicket($customer->id);

    $this->loginAsAdmin();

    post(route('admin.customers.tickets.escalate', $ticket->id), [
        'severity' => 'minor', 'description' => '第一次定性',
    ])->assertRedirect();

    post(route('admin.customers.tickets.escalate', $ticket->id), [
        'severity' => 'severe', 'description' => '重复定性',
    ])->assertRedirect(route('admin.customers.tickets.show', $ticket->id))
        ->assertSessionHas('error');

    expect(Incident::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('B-10 · 无归属客户的工单不能定性(incident.customer_id 非空)', function () {
    $ticket = b10AdminTicket(null);

    $this->loginAsAdmin();

    post(route('admin.customers.tickets.escalate', $ticket->id), [
        'severity' => 'minor', 'description' => '无主工单',
    ])->assertRedirect(route('admin.customers.tickets.show', $ticket->id))
        ->assertSessionHas('error');

    expect(Incident::where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('B-10 · 工单详情页显示已定性事故的互链', function () {
    $customer = b10AdminCustomer();
    $ticket = b10AdminTicket($customer->id);

    $incident = Incident::create([
        'incident_no'    => 'I'.uniqid(),
        'customer_id'    => $customer->id,
        'ticket_id'      => $ticket->id,
        'severity'       => 'moderate',
        'occurred_at'    => now(),
        'description'    => '由工单升级',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => Incident::STATUS_REPORTED,
    ]);

    $this->loginAsAdmin();

    get(route('admin.customers.tickets.show', $ticket->id))
        ->assertOk()
        ->assertSeeText('本工单已定性为事故')
        ->assertSeeText($incident->incident_no);
});
