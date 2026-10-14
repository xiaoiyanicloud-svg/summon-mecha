<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Services\Payment\MockGateway;

use function Pest\Laravel\postJson;

/**
 * M3 · 支付回调信任请求体 order_id、不核金额。
 *
 * 背景:回调此前允许 `if ($request->filled('order_id')) return Order::find($request->input('order_id'));`
 * —— 而本接口**不挂鉴权**,任何人 `?order_id=<他人订单>&mock_paid=1` 即可把别人的订单翻成已支付。
 * 且 `PaymentResult::amount` 拿到了却从未与订单应付核对。
 *
 * 修法:① 订单身份只来自网关解析出的商户订单号(out_trade_no),删除 order_id 分支;
 *       ② 回调金额必须等于订单应付,否则 409 + code。
 *
 * ⚠ 真实验签按设计**推迟**(依赖微信资质),本文件不伪造验签,只锁"身份来源"与"金额核对"。
 */

function hardenCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Harden',
        'last_name'   => 'Test',
        'email'       => 'harden'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function hardenOrder(Customer $customer, float $grandTotal = 199.00): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => 'HARDEN'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'pending',
        'grand_total'  => $grandTotal,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function hardenOutTradeNo(int $orderId): string
{
    return MockGateway::makeOutTradeNo(new DateTimeImmutable('2026-01-01 00:00:00'), $orderId);
}

it('订单身份不再来自请求体 order_id:仅带 order_id 的回调不认,订单保持 pending', function () {
    $customer = hardenCustomer();
    $victimOrderId = hardenOrder($customer, 199.00);

    // 不挂鉴权 + 只带他人订单 id —— 修复前会把该订单翻成 processing
    postJson(route('shop.api.callback.payment'), [
        'order_id'  => $victimOrderId,
        'mock_paid' => 1,
        'amount'    => 199.00,
    ])->assertStatus(422);

    expect(DB::table('orders')->where('id', $victimOrderId)->value('status'))->toBe('pending');
    expect(PaymentTransaction::query()->count())->toBe(0);
});

it('order_id 与合法 out_trade_no 同时出现时,以 out_trade_no 解析出的订单为准', function () {
    $customer = hardenCustomer();
    $realOrderId = hardenOrder($customer, 50.00);
    $decoyOrderId = hardenOrder($customer, 50.00);

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => hardenOutTradeNo($realOrderId),
        'order_id'     => $decoyOrderId,
        'mock_paid'    => 1,
        'amount'       => 50.00,
    ])->assertOk();

    expect(DB::table('orders')->where('id', $realOrderId)->value('status'))->toBe('processing')
        ->and(DB::table('orders')->where('id', $decoyOrderId)->value('status'))->toBe('pending');
});

it('篡改金额的回调被拒(409 + AMOUNT_MISMATCH),订单不变、不落流水', function () {
    $customer = hardenCustomer();
    $orderId = hardenOrder($customer, 999.00);

    $response = postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => hardenOutTradeNo($orderId),
        'mock_paid'    => 1,
        'amount'       => 0.01, // 篡改
    ]);

    $response->assertStatus(409)->assertJson(['code' => 'AMOUNT_MISMATCH']);

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('pending');
    expect(PaymentTransaction::query()->count())->toBe(0);
});

it('金额相符时放行', function () {
    $customer = hardenCustomer();
    $orderId = hardenOrder($customer, 999.00);

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => hardenOutTradeNo($orderId),
        'mock_paid'    => 1,
        'amount'       => 999.00,
    ])->assertOk();

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing');
});
