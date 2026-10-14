<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Services\Payment\MockGateway;

use function Pest\Laravel\postJson;

/**
 * M6 · 支付流水从不落库(无幂等键、无对账源)。
 *
 * 背景:回调此前只做 `$order->status='processing'; $order->save();` ——
 * `PaymentResult::transactionId`(mock 生成的 MOCKTXN...)被丢弃,全库没有任何
 * 支付/交易写入。项目自己也承认:`OpsMetricsService::notConnected()` 写着
 * 「支付交易号 out_trade_no 从未持久化」。
 *
 * 修法:新增 `payment_transaction` 表,回调成功即落一行,并以其 `out_trade_no`
 * **唯一键**做幂等(不再依赖"先查订单状态再改"的 check-then-act)。
 */

function ledgerCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Ledger',
        'last_name'   => 'Test',
        'email'       => 'ledger'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function ledgerOrder(Customer $customer, float $grandTotal = 199.00): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => 'LEDGER'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'pending',
        'grand_total'  => $grandTotal,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function ledgerOutTradeNo(int $orderId): string
{
    return MockGateway::makeOutTradeNo(new DateTimeImmutable('2026-01-01 00:00:00'), $orderId);
}

it('回调成功即落一行支付流水(含 out_trade_no / transaction_id / 金额 / 渠道)', function () {
    $customer = ledgerCustomer();
    $orderId = ledgerOrder($customer, 199.00);
    $outTradeNo = ledgerOutTradeNo($orderId);

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => $outTradeNo,
        'mock_paid'    => 1,
        'amount'       => 199.00,
    ])->assertOk();

    $txn = PaymentTransaction::query()->where('out_trade_no', $outTradeNo)->first();

    expect($txn)->not->toBeNull()
        ->and($txn->order_id)->toBe($orderId)
        ->and($txn->amount)->toBe(199.00)
        ->and($txn->gateway)->toBe('mock')
        ->and($txn->transaction_id)->not->toBeNull()
        ->and($txn->status)->toBe(PaymentTransaction::STATUS_PAID);

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing');
});

it('重复回调 → 流水只有一行,订单只被推进一次(唯一键幂等)', function () {
    $customer = ledgerCustomer();
    $orderId = ledgerOrder($customer, 199.00);
    $outTradeNo = ledgerOutTradeNo($orderId);

    $payload = ['out_trade_no' => $outTradeNo, 'mock_paid' => 1, 'amount' => 199.00];

    postJson(route('shop.api.callback.payment'), $payload)->assertOk();
    postJson(route('shop.api.callback.payment'), $payload)->assertOk();

    expect(PaymentTransaction::query()->where('out_trade_no', $outTradeNo)->count())->toBe(1)
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing');
});

it('能凭 out_trade_no 反查到订单(对账源)', function () {
    $customer = ledgerCustomer();
    $orderId = ledgerOrder($customer, 88.00);
    $outTradeNo = ledgerOutTradeNo($orderId);

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => $outTradeNo,
        'mock_paid'    => 1,
        'amount'       => 88.00,
    ])->assertOk();

    $resolvedOrderId = PaymentTransaction::query()
        ->where('out_trade_no', $outTradeNo)
        ->value('order_id');

    expect($resolvedOrderId)->toBe($orderId);
});
