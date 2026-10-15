<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Http\Controllers\API\CustomerController;
use Webkul\Shop\Services\Payment\MockGateway;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 加固第五轮 · 0 节横切 X2 / X3 + 资金 M8。
 *
 * X2  全局异常渲染 — ValidationException/ModelNotFound/Authentication/Throwable 统一信封
 * X3  限流与防刷 — login / ai 两档 + 登录失败计数锁定
 * M8  资金动作写 operation_log — 支付回调 / 退款审批 / 授权发放撤销
 */

// ───────────────────────────── X2 ─────────────────────────────

it('X2 · ValidationException → 422 且带 code + errors', function () {
    // /api/customer/login 走 LoginRequest(FormRequest 抛 ValidationException)
    postJson('/api/customer/login', ['email' => 'not-an-email', 'password' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['code', 'message', 'errors' => ['email']]);
});

it('X2 · ModelNotFoundException → 404 且不泄漏类名', function () {
    Route::get('/api/__test/x2-model', function () {
        return \Webkul\Product\Models\ProductProxy::modelClass()::findOrFail(999999);
    });

    $res = getJson('/api/__test/x2-model')->assertStatus(404);

    expect($res->json('code'))->toBe('NOT_FOUND')
        ->and($res->getContent())->not->toContain('No query results')
        ->and($res->getContent())->not->toContain('Model');
});

it('X2 · AuthenticationException → 401', function () {
    Route::get('/api/__test/x2-auth', function () {
        throw new AuthenticationException('nope');
    });

    getJson('/api/__test/x2-auth')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('X2 · 未捕获异常 → 500,生产模式隐藏细节', function () {
    Route::get('/api/__test/x2-boom', function () {
        throw new \RuntimeException('SECRET-DETAIL-ABC');
    });

    // 生产:APP_DEBUG=false → 不回显异常消息
    config(['app.debug' => false]);

    $res = getJson('/api/__test/x2-boom')->assertStatus(500);

    expect($res->json('code'))->toBe('SERVER_ERROR')
        ->and($res->getContent())->not->toContain('SECRET-DETAIL-ABC');

    // 开发:APP_DEBUG=true → 保留消息,便于排查
    config(['app.debug' => true]);

    $dev = getJson('/api/__test/x2-boom')->assertStatus(500);
    expect($dev->getContent())->toContain('SECRET-DETAIL-ABC');
});

it('X2 · HTTP 异常(404)继续 fall-through,不被压成 500', function () {
    Route::get('/api/__test/x2-abort', function () {
        abort(404, 'nope');
    });

    getJson('/api/__test/x2-abort')->assertStatus(404);
});

// ───────────────────────────── X3 ─────────────────────────────

function x3RouteMiddleware(string $name): string
{
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->getName() === $name);

    expect($route)->not->toBeNull();

    return implode('|', $route->gatherMiddleware());
}

it('X3 · 敏感路由都挂了对应限流档位', function () {
    expect(x3RouteMiddleware('shop.api.customers.session.create'))->toContain('throttle:login');
    expect(x3RouteMiddleware('shop.api.customers.register'))->toContain('throttle:login');

    foreach ([
        'shop.api.summon',
        'shop.api.summon-ai',
        'shop.api.summon-stream',
        'shop.api.asr',
        'shop.api.tts',
        'shop.api.callback.payment',
    ] as $name) {
        expect(x3RouteMiddleware($name))->toContain('throttle:ai');
    }

    // write 档:公开/登录态的写入口(计划 §0 X3 点名 tob-order;§5 A9 点名售后/工单/事故/评价)
    foreach ([
        'shop.api.tob-order.store',
        'shop.api.after-sale.store',
        'shop.api.tickets.store',
        'shop.api.incidents.store',
        'shop.api.products.reviews.store',
    ] as $name) {
        expect(x3RouteMiddleware($name))->toContain('throttle:write');
    }

    // 反向断言:同组的 GET 不能被顺手限流(否则正常翻页/轮询会把自己限死)
    foreach ([
        'shop.api.tickets.index',
        'shop.api.incidents.index',
        'shop.api.after-sale.index',
    ] as $name) {
        expect(x3RouteMiddleware($name))->not->toContain('throttle:write');
    }
});

it('X3 · 登录连续失败达阈值后锁定(慢速撞库防线)', function () {
    $customer = Customer::create([
        'first_name'  => 'Lock',
        'last_name'   => 'Out',
        'email'       => 'lockout'.uniqid().'@example.com',
        'password'    => bcrypt('correct-horse'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    $payload = ['email' => $customer->email, 'password' => 'wrong-password'];

    // 前 5 次失败:普通 403(不锁定)
    for ($i = 0; $i < CustomerController::MAX_LOGIN_FAILURES; $i++) {
        postJson('/api/customer/login', $payload)->assertStatus(403);
    }

    // 第 6 次:已锁定 → 429
    postJson('/api/customer/login', $payload)
        ->assertStatus(429)
        ->assertJsonPath('code', 'ACCOUNT_LOCKED');
});

it('X3 · ai 档限流生效(超过额度返回 429 信封)', function () {
    // 测试里把 ai 档临时收紧到 2/分,避免真跑 60 次(慢且会撞上 array 缓存的 60s TTL)。
    // 这同时验证了「路由确实挂在 ai 档限流上」这一接线。
    RateLimiter::for('ai', function (\Illuminate\Http\Request $request) {
        return Limit::perMinute(2)
            ->by($request->ip())
            ->response(fn () => response()->json([
                'code'    => 'RATE_LIMITED',
                'message' => '请求过于频繁,请稍后再试。',
            ], 429));
    });

    postJson('/api/tts', ['text' => 'a']);
    postJson('/api/tts', ['text' => 'b']);

    postJson('/api/tts', ['text' => 'c'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
});

it('X3 · write 档限流生效(公开写入口 tob-order)', function () {
    // 同上:临时收紧到 1/分,验证"写路由确实挂在 write 档"这一接线,而不真打 30 次。
    RateLimiter::for('write', function (\Illuminate\Http\Request $request) {
        return Limit::perMinute(1)
            ->by($request->ip())
            ->response(fn () => response()->json([
                'code'    => 'RATE_LIMITED',
                'message' => '提交过于频繁,请稍后再试。',
            ], 429));
    });

    $payload = [
        'type'         => 'custom',
        'name'         => 'X3 Write Probe',
        'phone'        => '13800000000',
        'product_name' => 'x3-write-probe',
    ];

    postJson(route('shop.api.tob-order.store'), $payload);

    postJson(route('shop.api.tob-order.store'), $payload)
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
});

// ───────────────────────────── M8 ─────────────────────────────

it('M8 · 支付回调写 operation_log(带金额与交易号)', function () {
    $customer = Customer::create([
        'first_name'  => 'Op', 'last_name' => 'Log',
        'email'       => 'oplog'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status' => 1, 'is_verified' => 1,
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'OPLOG'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'pending',
        'grand_total'  => 199.00,
        'created_at'   => now(), 'updated_at' => now(),
    ]);

    $outTradeNo = MockGateway::makeOutTradeNo(new DateTimeImmutable('2026-01-01 00:00:00'), $orderId);

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => $outTradeNo,
        'mock_paid'    => 1,
        'amount'       => 199.00,
    ])->assertOk();

    $log = DB::table('operation_log')->where('action', 'payment.callback.paid')->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(OperationLog::ACTOR_SYSTEM)
        ->and($log->target_id)->toBe($orderId)
        ->and(json_decode($log->payload, true)['amount'])->toEqual(199.00);
});

it('M8 · 售后审批写 operation_log(金额类,带操作者)', function () {
    $customer = Customer::create([
        'first_name'  => 'Afs', 'last_name' => 'Log',
        'email'       => 'afslog'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status' => 1, 'is_verified' => 1,
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'AFSLOG'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing',
        'grand_total'  => 520.00,
        'created_at'   => now(), 'updated_at' => now(),
    ]);

    $itemId = DB::table('order_items')->insertGetId([
        'order_id' => $orderId, 'product_id' => 900000 + random_int(1, 9999),
        'type' => 'simple', 'name' => '技能包', 'sku' => 'SKU'.uniqid(),
        'qty_ordered' => 1, 'price' => 520.00, 'total' => 520.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ent = SkillEntitlement::create([
        'customer_id' => $customer->id, 'order_id' => $orderId, 'order_item_id' => $itemId,
        'product_id' => 900000 + random_int(1, 9999),
        'status' => SkillEntitlement::STATUS_ACTIVE, 'delivery_channel' => 'app',
        'license_key' => SkillEntitlement::newLicenseKey(), 'granted_at' => now(),
    ]);

    $afterSaleId = AfterSale::create([
        'order_id' => $orderId, 'order_item_id' => $itemId, 'entitlement_id' => $ent->id,
        'customer_id' => $customer->id, 'type' => AfterSale::TYPE_REFUND,
        'policy_basis' => 'not_installed', 'status' => AfterSale::STATUS_APPLIED,
    ])->id;

    app(AfterSaleService::class)->approve($afterSaleId, 42);

    $log = DB::table('operation_log')->where('action', 'after_sale.approve')->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(OperationLog::ACTOR_ADMIN)
        ->and($log->actor_id)->toBe(42)
        ->and($log->target_id)->toBe($afterSaleId)
        ->and(json_decode($log->payload, true)['amount'])->toEqual(520.0);
});

it('M8 · 订单取消撤销授权写 operation_log(与发放对称)', function () {
    $customer = Customer::create([
        'first_name'  => 'Rev', 'last_name' => 'Log',
        'email'       => 'revlog'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status' => 1, 'is_verified' => 1,
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'REVLOG'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'pending',
        'grand_total'  => 88.00,
        'created_at'   => now(), 'updated_at' => now(),
    ]);

    $ent = SkillEntitlement::create([
        'customer_id' => $customer->id, 'order_id' => $orderId,
        'order_item_id' => 900000 + random_int(1, 9999),
        'product_id' => 900000 + random_int(1, 9999),
        'status' => SkillEntitlement::STATUS_GRANTED, 'delivery_channel' => 'app',
        'license_key' => SkillEntitlement::newLicenseKey(), 'granted_at' => now(),
    ]);

    $order = \Webkul\Sales\Models\OrderProxy::modelClass()::find($orderId);

    app(\Webkul\Shop\Listeners\SkillDelivery::class)->afterOrderCanceled($order);

    $log = DB::table('operation_log')->where('action', 'entitlement.revoke')->first();

    expect($log)->not->toBeNull()
        ->and($log->target_id)->toBe($ent->id)
        ->and($ent->fresh()->status)->toBe(SkillEntitlement::STATUS_REVOKED);
});
