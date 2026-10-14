<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SkillEntitlement;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * 加固第二轮 · M1 / M4 / D6 / D8。
 *
 * M1  一/资金  · 成交价不能无界,技能走服务端目录价
 * M4  一/资金  · 取消订单必须对称撤销授权
 * D6  二/交付  · 技能分类缺失(配置漂移)要告警,不能静默停发
 * D8  二/交付  · 下载接口加节流 + 原子计数
 */

function hr3Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Harden',
        'last_name'   => 'R3',
        'email'       => 'hr3'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function hr3Product(string $slug = 'skill'): int
{
    $categoryId = DB::table('category_translations')->where('slug', $slug)->value('category_id');

    if (! $categoryId) {
        $categoryId = DB::table('categories')->insertGetId([
            'status' => 1, '_lft' => 1, '_rgt' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'locale_id'   => DB::table('locales')->value('id') ?: 1,
            'locale'      => 'en',
            'name'        => $slug,
            'slug'        => $slug,
        ]);
    }

    $id = DB::table('products')->insertGetId([
        'sku'                 => 'HR3-'.uniqid(),
        'type'                => 'simple',
        'attribute_family_id' => DB::table('attribute_families')->value('id') ?: 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('product_categories')->insert(['product_id' => $id, 'category_id' => $categoryId]);

    return $id;
}

// ───────────────────────────── M1 ─────────────────────────────

it('M1 · 负数成交价被拒(无下限的老洞)', function () {
    $productId = hr3Product();

    postJson('/api/tob-order', [
        'type' => 'buy', 'product_id' => $productId, 'qty' => 1,
        'name' => '张三', 'phone' => '13800000000',
        'city' => '上海', 'address' => '某路1号',
        'price' => -1,
    ])->assertStatus(422)->assertJsonValidationErrors('price');
});

it('M1 · 超大租期被拒', function () {
    $productId = hr3Product();

    postJson('/api/tob-order', [
        'type' => 'rent', 'product_id' => $productId, 'qty' => 1,
        'name' => '张三', 'phone' => '13800000000',
        'city' => '上海', 'address' => '某路1号',
        'rent_days' => 99999,
    ])->assertStatus(422)->assertJsonValidationErrors('rent_days');
});

it('M1 · 技能单不接受客户端改价(走服务端目录价)', function () {
    $customer = hr3Customer();
    $productId = hr3Product('skill');

    actingAs($customer, 'customer');

    $res = postJson('/api/tob-order', [
        'type' => 'skill', 'product_id' => $productId, 'qty' => 1,
        'name' => '李四', 'phone' => '13800000000',
        'city' => '上海', 'address' => '某路2号',
        'price' => 0.01,
    ]);

    $res->assertOk();

    // 客户端 0.01 被忽略:服务端价(此夹具无价属性 → 0)不可能是 0.01
    expect((float) $res->json('grand_total'))->not->toBe(0.01);
});

// ───────────────────────────── M4 ─────────────────────────────

it('M4 · 取消订单对称撤销技能授权(不留双花)', function () {
    $customer = hr3Customer();

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'HR3'.uniqid(),
        'customer_id'  => $customer->id,
        'customer_email' => $customer->email,
        'customer_first_name' => 'Harden',
        'customer_last_name'  => 'R3',
        'is_guest'     => 0,
        'status'       => 'pending',
        'grand_total'  => 999.00,
        'created_at'   => now(), 'updated_at' => now(),
    ]);

    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => hr3Product('skill'),
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.customers.orders.cancel', $orderId))->assertOk();

    expect($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_REVOKED);
});

// ───────────────────────────── D6 ─────────────────────────────

it('D6 · 技能分类缺失时告警(不再静默停发)', function () {
    // 删掉 skill 分类(或从未建)→ 配置漂移
    DB::table('category_translations')->where('slug', 'skill')->delete();
    DB::table('category_translations')->where('slug', 'hr3-missing')->delete();

    Log::spy();

    $listener = app(\Webkul\Shop\Listeners\SkillDelivery::class);
    $method = new ReflectionMethod($listener, 'skillProductIds');
    $method->setAccessible(true);
    $result = $method->invoke($listener);

    expect($result)->toBe([]);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

// ───────────────────────────── D8 ─────────────────────────────

it('D8 · 技能组路由挂了节流中间件', function () {
    $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->getName() === 'shop.api.skills.download');

    expect($route)->not->toBeNull();

    $middleware = implode('|', $route->gatherMiddleware());
    expect($middleware)->toContain('throttle');
});
