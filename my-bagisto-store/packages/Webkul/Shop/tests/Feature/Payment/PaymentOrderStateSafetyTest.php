<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Listeners\SkillDelivery;
use Webkul\Shop\Models\Invoice;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Services\Billing\InvoiceService;
use Webkul\Shop\Services\Payment\MockGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    // Keep cancellation events real; unrelated mail templates are outside this safety test.
    Mail::fake();
});

function s10PaymentCustomer(): Customer
{
    return Customer::create([
        'first_name' => 'Payment', 'last_name' => 'Safety',
        'email'      => uniqid('s10-payment-', true).'@example.test',
        'password'   => bcrypt('local-test-only'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1, 'is_verified' => 1,
    ]);
}

function s10PaymentOrder(string $status, ?Customer $customer = null, ?string $guestKey = null): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => testUniqueNo('S10PAY'),
        'customer_id'  => $customer?->id,
        'guest_key'    => $guestKey,
        'status'       => $status, 'grand_total' => 199.00,
        'created_at'   => now(), 'updated_at' => now(),
    ]);
}

function s10PaymentPayload(int $orderId): array
{
    return [
        'out_trade_no'           => MockGateway::makeOutTradeNo(new DateTimeImmutable('2026-01-01'), $orderId),
        'mock_paid'              => 1, 'amount' => 199.00,
        'private_callback_field' => 'PRIVATE-CALLBACK-NOT-FOR-ALERTS',
    ];
}

it('S10 B1 only payable orders advance and callback does not claim successful skill delivery', function (string $status) {
    config(['payment_gateways.default' => 'mock']);
    $customer = s10PaymentCustomer();
    $orderId = s10PaymentOrder($status, $customer);
    $delivery = Mockery::mock(SkillDelivery::class);
    $delivery->shouldReceive('afterOrderCreated')->once();
    app()->instance(SkillDelivery::class, $delivery);

    $payload = s10PaymentPayload($orderId);
    $response = postJson(route('shop.api.callback.payment'), $payload)->assertOk();
    postJson(route('shop.api.callback.payment'), $payload)->assertOk();

    expect($response->json('message'))->not->toContain('授权已发放')
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing')
        ->and(PaymentTransaction::where('order_id', $orderId)->count())->toBe(1)
        ->and(Invoice::whereJsonContains('order_ids', $orderId)->count())->toBe(1);
})->with(['pending', 'pending_payment']);

it('S10 B1 late payment preserves blocked order and produces one safe alert and audit per transaction', function (string $status) {
    config(['payment_gateways.default' => 'mock']);
    $adminId = DB::table('admins')->insertGetId([
        'name'    => 'Payment safety reviewer',
        'email'   => uniqid('s10-admin-', true).'@example.test',
        'role_id' => DB::table('roles')->value('id'),
        'status'  => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $orderId = s10PaymentOrder($status, s10PaymentCustomer());
    $delivery = Mockery::mock(SkillDelivery::class);
    $delivery->shouldNotReceive('afterOrderCreated');
    app()->instance(SkillDelivery::class, $delivery);
    $invoices = Mockery::mock(InvoiceService::class);
    $invoices->shouldNotReceive('issueForOrder');
    app()->instance(InvoiceService::class, $invoices);

    $payload = s10PaymentPayload($orderId);

    for ($i = 0; $i < 3; $i++) {
        postJson(route('shop.api.callback.payment'), $payload)
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'CONFLICT')
            ->assertJsonPath('review_required', true)
            ->assertJsonPath('order_status', $status)
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('delivery_status', 'not_attempted');
    }

    $payment = PaymentTransaction::where('order_id', $orderId)->sole();
    $alerts = Notification::where('recipient_type', 'admin')
        ->where('recipient_id', $adminId)
        ->where('payload->payment_transaction_id', $payment->id)->get();
    $audit = OperationLog::where('target_type', 'order')->where('target_id', $orderId)
        ->where('action', 'payment.callback.review_required')->get();

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe($status)
        ->and($payment->status)->toBe(PaymentTransaction::STATUS_PAID)
        ->and($alerts)->toHaveCount(1)
        ->and($audit)->toHaveCount(1)
        ->and($alerts->first()->payload['level'])->toBe('urgent')
        ->and(json_encode($alerts->toArray()))->not->toContain('PRIVATE-CALLBACK-NOT-FOR-ALERTS')
        ->and(json_encode($audit->toArray()))->not->toContain('PRIVATE-CALLBACK-NOT-FOR-ALERTS')
        ->and(OperationLog::where('action', 'payment.callback.paid')->where('target_id', $orderId)->count())->toBe(0)
        ->and(Invoice::whereJsonContains('order_ids', $orderId)->count())->toBe(0);
})->with(['canceled', 'closed', 'fraud', 'unknown_hold']);

it('S10 B1 completed payment replay stays idempotent without late-payment review', function (string $status) {
    config(['payment_gateways.default' => 'mock']);
    $orderId = s10PaymentOrder($status, s10PaymentCustomer());
    $delivery = Mockery::mock(SkillDelivery::class);
    $delivery->shouldNotReceive('afterOrderCreated');
    app()->instance(SkillDelivery::class, $delivery);

    postJson(route('shop.api.callback.payment'), s10PaymentPayload($orderId))
        ->assertOk()->assertJsonPath('message', '已处理(幂等)。');

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe($status)
        ->and(OperationLog::where('action', 'payment.callback.review_required')->where('target_id', $orderId)->count())->toBe(0);
})->with(['processing', 'completed']);

it('S10 B1 cancellation event and update share a transaction and the order row lock', function () {
    $customer = s10PaymentCustomer();
    $orderId = s10PaymentOrder('pending', $customer);
    $baselineLevel = DB::transactionLevel();
    $eventLevel = null;
    Event::listen('sales.order.cancel.after', function ($order) use ($orderId, &$eventLevel) {
        if ($order->id === $orderId) {
            $eventLevel = DB::transactionLevel();
        }
    });
    actingAs($customer, 'customer');
    DB::enableQueryLog();
    DB::flushQueryLog();

    postJson(route('shop.api.customers.orders.cancel', $orderId))
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('status', 'canceled');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $locked = collect($queries)->first(fn ($query) => str_contains($query['query'], 'orders')
        && str_contains($query['query'], 'for update'));

    expect($eventLevel)->toBeGreaterThan($baselineLevel)
        ->and($locked)->not->toBeNull()
        ->and($locked['query'])->toContain('customer_id')
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('canceled');
});

it('S10 B1 cancellation rolls back its order update when a cancellation listener fails', function () {
    $customer = s10PaymentCustomer();
    $orderId = s10PaymentOrder('pending', $customer);
    Event::listen('sales.order.cancel.after', function ($order) use ($orderId) {
        if ($order->id === $orderId) {
            throw new RuntimeException('S10 cancellation transaction failure');
        }
    });
    actingAs($customer, 'customer');

    postJson(route('shop.api.customers.orders.cancel', $orderId))->assertStatus(500);

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('pending')
        ->and(OperationLog::where('action', 'order.cancel')->where('target_id', $orderId)->count())->toBe(0);
});

it('S10 B1 a payment completed before cancellation cannot be overwritten', function () {
    config(['payment_gateways.default' => 'mock']);
    $customer = s10PaymentCustomer();
    $orderId = s10PaymentOrder('pending', $customer);
    postJson(route('shop.api.callback.payment'), s10PaymentPayload($orderId))->assertOk();
    actingAs($customer, 'customer');

    postJson(route('shop.api.customers.orders.cancel', $orderId))->assertOk()
        ->assertJsonPath('success', false)->assertJsonPath('code', 'ORDER_NOT_CANCELABLE');

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing');
});

it('S10 B1 a payment arriving after cancellation does not resurrect the order', function () {
    config(['payment_gateways.default' => 'mock']);
    $customer = s10PaymentCustomer();
    $orderId = s10PaymentOrder('pending', $customer);
    actingAs($customer, 'customer');
    postJson(route('shop.api.customers.orders.cancel', $orderId))->assertOk()->assertJsonPath('success', true);

    postJson(route('shop.api.callback.payment'), s10PaymentPayload($orderId))->assertOk()
        ->assertJsonPath('review_required', true)->assertJsonPath('delivery_status', 'not_attempted');

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('canceled')
        ->and(PaymentTransaction::where('order_id', $orderId)->count())->toBe(1);
});

it('S10 B1 cancellation preserves guest identity checks and its response contract', function () {
    $guestKey = 's10-guest-key-0123456789abcdef';
    $orderId = s10PaymentOrder('pending', guestKey: $guestKey);

    postJson(route('shop.api.guest-orders.cancel', $orderId))->assertOk()
        ->assertJsonPath('success', false)->assertJsonPath('code', 'GUEST_KEY_REQUIRED');
    postJson(route('shop.api.guest-orders.cancel', $orderId), [], ['X-Guest-Key' => 'other-guest-0123456789abcdef'])
        ->assertOk()->assertJsonPath('success', false)->assertJsonPath('code', 'ORDER_NOT_FOUND');
    postJson(route('shop.api.guest-orders.cancel', $orderId), [], ['X-Guest-Key' => $guestKey])
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('status', 'canceled');
});

it('S10 B1 a logged in customer cannot cancel another customers order', function () {
    $owner = s10PaymentCustomer();
    $other = s10PaymentCustomer();
    $orderId = s10PaymentOrder('pending', $owner);
    actingAs($other, 'customer');

    postJson(route('shop.api.customers.orders.cancel', $orderId))->assertOk()
        ->assertJsonPath('success', false)->assertJsonPath('code', 'ORDER_NOT_FOUND');

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('pending');
});
