<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Services\Payment\MockGateway;
use Webkul\Shop\Services\Payment\PaymentManager;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * 支付网关守卫（计划「拆假真相」批次）。
 *
 * 背景：全仓此前**零支付测试**，而链路上有两处会说谎的地方：
 *   1. `PaymentManager` 没有任何环境判断 —— 生产上不设 PAYMENT_GATEWAY 时，
 *      mock 就是默认网关；`config/payment_gateways.php` 却声称"已按 env 兜底"。
 *   2. mock 的"支付成功"由回调参数 `mock_paid=1` 自报，无验签，而回调路由
 *      **不挂鉴权**。生产上等于：任何人 POST 一下就能把订单翻成已支付并发授权。
 *
 * 本文件锁的是**执行期守卫**：生产环境里，凡注册表未声明 `production_safe => true`
 * 的网关一律解析失败。宁可引起 503，也不能静默走错。
 *
 * ⚠ 这些测试全部改 `app('env')` 为 production，跑完由 Pest 的每个用例重建容器复位。
 */

function payCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Pay',
        'last_name'   => 'User',
        'email'       => 'pay'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function payOrder(Customer $customer): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => 'PAY'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'pending',
        'grand_total'  => 77.00,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

/** 把应用伪装成生产环境。 */
function asProduction(): void
{
    app()->detectEnvironment(fn () => 'production');

    expect(app()->environment('production'))->toBeTrue();
}

/** 造一个格式正确的 mock 商户订单号（格式由 MockGateway 单一定义）。 */
function mockOutTradeNo(int $orderId): string
{
    return MockGateway::makeOutTradeNo(new DateTimeImmutable('2026-01-01 00:00:00'), $orderId);
}

// ==================== 守卫本身 ====================

it('非生产环境：mock 网关照常可解析（守卫不打断本地联调）', function () {
    expect(app()->environment('production'))->toBeFalse();

    $gateway = app(PaymentManager::class)->gateway();

    expect($gateway)->toBeInstanceOf(MockGateway::class);
});

it('生产环境：拒绝解析 mock 网关', function () {
    asProduction();

    expect(fn () => app(PaymentManager::class)->gateway())
        ->toThrow(RuntimeException::class, '不得在生产环境使用');
});

it('生产环境：拒绝解析 wechat 网关（它目前也是抛异常的占位，不是"切过去就活"）', function () {
    asProduction();

    expect(fn () => app(PaymentManager::class)->gateway('wechat'))
        ->toThrow(RuntimeException::class, '不得在生产环境使用');
});

it('生产环境：注册表里凡未声明 production_safe=true 的网关，逐个都被拒绝', function () {
    asProduction();

    $keys = array_keys(config('payment_gateways.gateways'));

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        if (config("payment_gateways.gateways.{$key}.production_safe") === true) {
            continue;
        }

        expect(fn () => app(PaymentManager::class)->gateway($key))
            ->toThrow(RuntimeException::class);
    }

    // 反向断言：当前**没有任何**网关声明为生产可用 ——
    // 也就是说，此刻的生产环境拒收一切支付。这是事实（资质未接入），
    // 不是遗憾。等 wechat 真正接好并加验签后，这里会失败，
    // 届时把 wechat 翻成 true 即可 —— 那是接入完成的标志，不是抹掉一行测试。
    expect(collect(config('payment_gateways.gateways'))->pluck('production_safe')->contains(true))
        ->toBeFalse();
});

// ==================== 走 HTTP 的实际后果 ====================

it('生产环境：建支付单返回 503，而不是返回一份可自报成功的 mock 参数', function () {
    asProduction();

    $customer = payCustomer();
    $orderId = payOrder($customer);

    actingAs($customer, 'customer');

    $response = postJson(route('shop.api.payment.create', $orderId));

    $response->assertStatus(503);

    // 关键：绝不能把 mock 那套「callback_hint 直接调一下就成功」的东西发出去
    expect(json_encode($response->json(), JSON_UNESCAPED_UNICODE))
        ->not->toContain('mock_paid')
        ->not->toContain('callback_hint');
});

it('生产环境：无鉴权回调不得凭 mock_paid=1 把订单翻成已支付', function () {
    asProduction();

    $customer = payCustomer();
    $orderId = payOrder($customer);

    // 回调路由不挂鉴权 —— 这正是危险所在：任何人都能 POST
    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => mockOutTradeNo($orderId),
        'mock_paid'    => 1,
        'amount'       => 77.00,
    ])->assertStatus(503);

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('pending');
});

// ==================== 商户订单号的格式契约 ====================
//
// 这一组是回归锁：补第一条支付测试时发现，mock 的 out_trade_no 原本是
// `MOCK{YmdHis}{id}`（无分隔符），而回调侧用 `/(\d+)$/` 反查订单 ——
// 结尾那串数字是"时间戳+id"黏在一起，取出来是天文数字，**永远 404**。
// 也就是说"mock 全链路已通"这条说法从未被跑通过。

it('商户订单号：生成的号能被自己解析回订单 id（往返一致）', function () {
    $at = new DateTimeImmutable('2026-01-01 00:00:00');

    foreach ([1, 77, 12345] as $orderId) {
        $no = MockGateway::makeOutTradeNo($at, $orderId);

        expect($no)->toBe('MOCK-20260101000000-'.$orderId)
            ->and(MockGateway::orderIdFrom($no))->toBe($orderId);
    }
});

it('商户订单号：解析不出就返回 null，绝不"取结尾数字"猜一个订单 id', function () {
    // 旧格式（时间戳与 id 直接相连）—— 正是它导致了回调永远 404
    expect(MockGateway::orderIdFrom('MOCK20260101000000123'))->toBeNull()
        // 真实网关常见的纯数字商户号
        ->and(MockGateway::orderIdFrom('4200001234202601011234567890'))->toBeNull()
        ->and(MockGateway::orderIdFrom(''))->toBeNull()
        // 前缀对但格式不对
        ->and(MockGateway::orderIdFrom('MOCK-123-456'))->toBeNull();
});

// ==================== 本地联调仍然通 ====================

it('本地：mock 回调可完成"支付"（守卫没有把联调路径打死）', function () {
    $customer = payCustomer();
    $orderId = payOrder($customer);

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => mockOutTradeNo($orderId),
        'mock_paid'    => 1,
        'amount'       => 77.00,
    ])->assertOk();

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing');
});

it('本地：mock 回调不认没有 mock_paid 标记的请求', function () {
    $customer = payCustomer();
    $orderId = payOrder($customer);

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => mockOutTradeNo($orderId),
    ])->assertStatus(422);

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('pending');
});
