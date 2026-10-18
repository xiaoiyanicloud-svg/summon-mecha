<?php

use Webkul\CartRule\Models\CartRule;
use Webkul\CartRule\Models\CartRuleCoupon;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Services\Billing\SkillCouponService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * B-18 · 优惠券对技能单无路径。
 *
 * 缺口:全仓无券与技能/tob-order 的关联 —— 技能单走 tob-order **不经购物车**,
 *   而原生用券链路(`CartRule\Helpers\CartRule`)整条绑在购物车上,够不着它。
 *   库里可以有一堆券,技能单却没有任何入口能用。
 *
 * 修法(不新造):沿用同一批 `cart_rules`/`cart_rule_coupons` 表与同一套校验语义,
 *   只在 tob-order 这一侧补"按订单级汇总算折抵"的一层;用量记账仍交给**原生**
 *   `CartRule\Listeners\Order@manageCartRule`(挂 `checkout.order.save.after`,
 *   由 `OrderRepository::create()` 派发)—— 本测试第 6 例就是在锁这条"真的记了账"。
 *
 * 诚实边界(第 2/3 例):带条件限制、或不支持的优惠类型,一律**明确拒绝**,
 *   绝不静默按忽略条件后的口径给折扣。
 */

// ==================== 造数据 ====================

function b18ChannelId(): int
{
    return (int) core()->getDefaultChannel()->id;
}

function b18GroupId(): int
{
    return (int) DB::table('customer_groups')->orderBy('id')->value('id');
}

function b18Customer(int $groupId): Customer
{
    return Customer::create([
        'first_name'         => 'Coupon',
        'last_name'          => 'B18',
        'email'              => 'b18'.uniqid().'@example.com',
        'password'           => bcrypt('secret123'),
        'channel_id'         => b18ChannelId(),
        'customer_group_id'  => $groupId,
        'status'             => 1,
        'is_verified'        => 1,
    ]);
}

/** 建一个带真实目录价的技能商品(价格落 product_price_indices,与 getMinimalPrice 同源)。 */
function b18Product(float $price, int $groupId): int
{
    $categoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');

    if (! $categoryId) {
        $categoryId = DB::table('categories')->insertGetId([
            'status' => 1, '_lft' => 1, '_rgt' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'locale_id'   => DB::table('locales')->value('id') ?: 1,
            'locale'      => 'en',
            'name'        => 'skill',
            'slug'        => 'skill',
        ]);
    }

    $id = DB::table('products')->insertGetId([
        'sku'                 => 'B18-'.uniqid(),
        'type'                => 'simple',
        'attribute_family_id' => DB::table('attribute_families')->value('id') ?: 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('product_categories')->insert(['product_id' => $id, 'category_id' => $categoryId]);

    DB::table('product_flat')->insert(['product_id' => $id, 'sku' => 'B18-'.$id, 'type' => 'simple',
        'name' => 'B18 Published Skill', 'price' => $price, 'status' => 1, 'visible_individually' => 1,
        'locale' => app()->getLocale(), 'channel' => core()->getCurrentChannel()->code]);
    \Webkul\Shop\Models\SkillVersion::create(['product_id' => $id, 'version' => '1.0.0', 'status' => 'published',
        'channel' => 'stable', 'delivery_channels' => ['manual'], 'released_at' => now()]);

    DB::table('product_price_indices')->insert([
        'product_id'        => $id,
        'customer_group_id' => $groupId,
        'channel_id'        => b18ChannelId(),
        'min_price'         => $price,
        'regular_min_price' => $price,
        'max_price'         => $price,
        'regular_max_price' => $price,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    return $id;
}

/**
 * 建一条券规则 + 一张券,并把它挂到当前渠道与客群上(与后台建券的最终形态一致)。
 *
 * @param  array<string,mixed>  $ruleOverrides
 * @param  array<string,mixed>  $couponOverrides
 * @return array{rule: CartRule, coupon: CartRuleCoupon}
 */
function b18Coupon(string $code, array $ruleOverrides = [], array $couponOverrides = []): array
{
    $groupId = b18GroupId();

    $rule = CartRule::create(array_merge([
        'name'            => 'B18 Test Rule',
        'status'          => 1,
        'coupon_type'     => 1,
        'action_type'     => 'by_percent',
        'discount_amount' => 10,
        'uses_per_coupon' => 0,
        'usage_per_customer' => 0,
        'times_used'      => 0,
    ], $ruleOverrides));

    $rule->cart_rule_channels()->attach(b18ChannelId());
    $rule->cart_rule_customer_groups()->attach($groupId);

    $coupon = CartRuleCoupon::create(array_merge([
        'code'        => $code,
        'cart_rule_id' => $rule->id,
        'usage_limit' => 0,
        'usage_per_customer' => 0,
        'times_used'  => 0,
        'type'        => 0,
        'is_primary'  => 1,
    ], $couponOverrides));

    return ['rule' => $rule, 'coupon' => $coupon];
}

// ==================== 1 · 服务层:校验与折抵 ====================

it('B-18 · by_percent 10% → 折抵 10(小计 100)', function () {
    ['coupon' => $coupon] = b18Coupon('B18PCT'.uniqid(), ['action_type' => 'by_percent', 'discount_amount' => 10]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 100.0, 1,
    );

    expect($res['ok'])->toBeTrue()
        ->and($res['discount'])->toEqual(10.0);
});

it('B-18 · by_fixed 每件减 30 → 单件折抵 30', function () {
    ['coupon' => $coupon] = b18Coupon('B18FIX'.uniqid(), ['action_type' => 'by_fixed', 'discount_amount' => 30, 'discount_quantity' => 1]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 200.0, 1,
    );

    expect($res['ok'])->toBeTrue()
        ->and($res['discount'])->toEqual(30.0);
});

it('B-18 · cart_fixed 立减 500 但小计只有 200 → 封顶到小计', function () {
    ['coupon' => $coupon] = b18Coupon('B18CART'.uniqid(), ['action_type' => 'cart_fixed', 'discount_amount' => 500]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 200.0, 1,
    );

    expect($res['ok'])->toBeTrue()
        ->and($res['discount'])->toEqual(200.0);
});

// ==================== 2 · 诚实边界:不该放行的一律拒绝 ====================

it('B-18 · 带条件限制的券 → 明确拒绝(不按忽略条件的口径静默给折扣)', function () {
    ['coupon' => $coupon] = b18Coupon('B18COND'.uniqid(), [
        'conditions' => [['attribute' => 'cart|sub_total', 'operator' => '>=', 'value' => '9999']],
    ]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 100.0, 1,
    );

    expect($res['ok'])->toBeFalse()
        ->and($res['message'])->toContain('条件');
});

it('B-18 · 不支持的优惠类型(买赠 buy_x_get_y)→ 拒绝', function () {
    ['coupon' => $coupon] = b18Coupon('B18BUY'.uniqid(), ['action_type' => 'buy_x_get_y', 'discount_amount' => 1, 'discount_step' => 2]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 100.0, 1,
    );

    expect($res['ok'])->toBeFalse()
        ->and($res['message'])->toContain('类型');
});

it('B-18 · 停用的券规则 → 拒绝', function () {
    ['coupon' => $coupon] = b18Coupon('B18OFF'.uniqid(), ['status' => 0]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 100.0, 1,
    );

    expect($res['ok'])->toBeFalse();
});

it('B-18 · 已过期的券 → 拒绝', function () {
    ['coupon' => $coupon] = b18Coupon('B18EXP'.uniqid(), ['ends_till' => now()->subDay()]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 100.0, 1,
    );

    expect($res['ok'])->toBeFalse()
        ->and($res['message'])->toContain('过期');
});

it('B-18 · 券总量用尽 → 拒绝', function () {
    ['coupon' => $coupon] = b18Coupon('B18LIM'.uniqid(), [], ['usage_limit' => 5, 'times_used' => 5]);

    $res = app(SkillCouponService::class)->resolve(
        $coupon->code,
        null, b18GroupId(), b18ChannelId(), 100.0, 1,
    );

    expect($res['ok'])->toBeFalse()
        ->and($res['message'])->toContain('领完');
});

it('B-18 · 不存在的券码 → 拒绝', function () {
    $res = app(SkillCouponService::class)->resolve('NO-SUCH-CODE', null, b18GroupId(), b18ChannelId(), 100.0, 1);

    expect($res['ok'])->toBeFalse();
});

// ==================== 3 · 接口集成:技能单真的用上券,且用量真的被记账 ====================

it('B-18 · 技能单带券 → 实付减折抵,券落订单,且原生监听记了 times_used', function () {
    $customer = b18Customer(b18GroupId());
    $productId = b18Product(200.0, b18GroupId());

    ['coupon' => $coupon] = b18Coupon('B18OK'.uniqid(), ['action_type' => 'by_percent', 'discount_amount' => 10]);

    actingAs($customer, 'customer');

    $res = postJson('/api/tob-order', [
        'type' => 'skill', 'product_id' => $productId, 'qty' => 1,
        'name' => '王五', 'phone' => '13800000000',
        'city' => '上海', 'address' => '某路3号',
        'coupon_code' => $coupon->code,
    ]);

    $res->assertOk();

    $orderId = (int) $res->json('order_id');
    $order = DB::table('orders')->where('id', $orderId)->first();

    // 200 × 90% = 180
    expect((float) $order->sub_total)->toEqual(200.0)
        ->and((float) $order->discount_amount)->toEqual(20.0)
        ->and((float) $order->grand_total)->toEqual(180.0)
        ->and($order->coupon_code)->toBe($coupon->code)
        ->and((string) $order->applied_cart_rule_ids)->toBe((string) $coupon->cart_rule_id);

    // 原生 CartRule\Listeners\Order 在 checkout.order.save.after 里给券 +1 —— 这是"没新造用量机制"的证明
    expect((int) DB::table('cart_rule_coupons')->where('id', $coupon->id)->value('times_used'))->toBe(1)
        ->and((int) DB::table('cart_rules')->where('id', $coupon->cart_rule_id)->value('times_used'))->toBe(1);
});

it('B-18 · 无效券码 → 422,且不建单', function () {
    $customer = b18Customer(b18GroupId());
    $productId = b18Product(200.0, b18GroupId());

    actingAs($customer, 'customer');

    $before = DB::table('orders')->count();

    postJson('/api/tob-order', [
        'type' => 'skill', 'product_id' => $productId, 'qty' => 1,
        'name' => '赵六', 'phone' => '13800000000',
        'city' => '上海', 'address' => '某路4号',
        'coupon_code' => 'B18-DOES-NOT-EXIST',
    ])->assertStatus(422);

    expect(DB::table('orders')->count())->toBe($before);
});

it('B-18 · 非技能单带券 → 422(券只对技能单开放,不静默吞掉)', function () {
    $productId = b18Product(200.0, b18GroupId());
    // Exercise a real non-skill order; skill type impersonation is rejected earlier.
    DB::table('product_categories')->where('product_id', $productId)->delete();
    DB::table('skill_versions')->where('product_id', $productId)->delete();

    postJson('/api/tob-order', [
        'type' => 'buy', 'product_id' => $productId, 'qty' => 1,
        'name' => '孙七', 'phone' => '13800000000',
        'city' => '上海', 'address' => '某路5号',
        'coupon_code' => 'ANY-CODE',
    ])->assertStatus(422);
});
