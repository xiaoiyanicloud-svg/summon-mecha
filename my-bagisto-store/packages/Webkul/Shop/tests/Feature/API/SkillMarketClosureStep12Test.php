<?php

use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Services\SkillDelivery\SkillMarketService;
use Webkul\Shop\Services\Deploy\KillSwitchService;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function s12MarketProduct(?string $versionStatus = 'published', string $slug = 'skill')
{
    $category = Category::whereHas('translations', fn ($q) => $q->where('slug', $slug))->firstOrFail();
    $product = (new ProductFaker)->getSimpleProductFactory()->hasAttached($category)->create();
    if ($versionStatus !== null) {
        SkillVersion::create(['product_id' => $product->id, 'version' => '1.0.0', 'status' => $versionStatus,
            'channel' => 'stable', 'delivery_channels' => ['manual'], 'risk_level' => 'low', 'released_at' => now()]);
    }

    return $product;
}

function s12Customer(): Customer
{
    return Customer::create(['first_name' => 'Market', 'last_name' => 'Test', 'email' => uniqid('s12').'@example.com',
        'password' => bcrypt('test-secret'), 'channel_id' => core()->getCurrentChannel()->id,
        'customer_group_id' => DB::table('customer_groups')->value('id'), 'status' => 1, 'is_verified' => 1]);
}

function s12OrderPayload(int $id, string $type = 'skill'): array
{
    return ['product_id' => $id, 'qty' => 1, 'type' => $type, 'name' => '闭环测试', 'phone' => '13800000000',
        'city' => '上海', 'address' => '独立测试库', 'price' => 0];
}

beforeEach(function () {
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDatabaseName())->toMatch('/(?:_test|_testing)$/');
});

it('聚合与分类商品列表在分页前排除没有发布版本的技能，正常硬件保留', function () {
    $published = s12MarketProduct();
    $draft = s12MarketProduct('draft');
    $empty = s12MarketProduct(null);
    $hardware = s12MarketProduct(null, 'buy');
    $aggregate = getJson(route('shop.api.tob-catalog.index'))->assertOk()->json('data');
    expect(collect($aggregate['skill'])->pluck('id')->all())->toContain($published->id)->not->toContain($draft->id)->not->toContain($empty->id)
        ->and(collect($aggregate['buy'])->pluck('id')->all())->toContain($hardware->id);
    $category = DB::table('category_translations')->where('slug', 'skill')->value('category_id');
    $list = getJson(route('shop.api.products.index', ['category_id' => $category, 'limit' => 200]))->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toContain($published->id)->not->toContain($draft->id)->not->toContain($empty->id);
    expect((int) $list->json('meta.total'))->toBe(DB::table('product_categories')->where('category_id', $category)
        ->whereNotIn('product_id', app(SkillMarketService::class)->unavailableProductIds())->distinct()->count('product_id'));
});

it('详情和版本直链拒绝草稿技能，不影响硬件详情', function () {
    $draft = s12MarketProduct('draft');
    getJson(route('shop.api.products.show', $draft->id))->assertStatus(409)->assertJsonPath('code', 'SKILL_UNAVAILABLE');
    getJson(route('shop.api.skill-catalog.versions', $draft->id))->assertStatus(409)->assertJsonPath('code', 'SKILL_UNAVAILABLE');
    getJson(route('shop.api.products.show', s12MarketProduct(null, 'buy')->id))->assertOk();
});

it('类别被移除的历史技能不能绕过购买资格，旧买断下载策略由原测试继续覆盖', function () {
    $product = s12MarketProduct();
    DB::table('product_categories')->where('product_id', $product->id)->delete();
    getJson(route('shop.api.products.show', $product->id))->assertStatus(409);
    $this->actingAs(s12Customer(), 'customer');
    postJson(route('shop.api.tob-order.store'), s12OrderPayload($product->id))->assertStatus(409)->assertJsonPath('code', 'SKILL_UNAVAILABLE');
});

it('上架到归档后，从聚合搜索详情购买同时消失', function () {
    $product = s12MarketProduct();
    getJson(route('shop.api.products.show', $product->id))->assertOk();
    SkillVersion::where('product_id', $product->id)->update(['status' => 'archived']);
    expect(collect(getJson(route('shop.api.tob-catalog.index'))->assertOk()->json('data.skill'))->pluck('id')->all())->not->toContain($product->id);
    expect(collect(getJson(route('shop.api.skill-catalog.search'))->assertOk()->json('data'))->pluck('id')->all())->not->toContain($product->id);
    getJson(route('shop.api.products.show', $product->id))->assertStatus(409);
    $this->actingAs(s12Customer(), 'customer');
    postJson(route('shop.api.tob-order.store'), s12OrderPayload($product->id))->assertStatus(409);
});

it('技能伪装成其它订单类型被拒绝且不建单', function (string $type) {
    $product = s12MarketProduct();
    $this->actingAs(s12Customer(), 'customer');
    $count = DB::table('orders')->count();
    postJson(route('shop.api.tob-order.store'), s12OrderPayload($product->id, $type))->assertStatus(409)->assertJsonPath('code', 'SKILL_ORDER_TYPE_MISMATCH');
    expect(DB::table('orders')->count())->toBe($count);
})->with(['buy', 'rent', 'accessory', 'plan', 'custom']);

it('游客改写订单类型也不能购买技能', function () {
    $product = s12MarketProduct();
    postJson(route('shop.api.tob-order.store'), s12OrderPayload($product->id, 'buy'))->assertStatus(401)->assertJsonPath('code', 'SKILL_LOGIN_REQUIRED');
});

it('真实已发布技能保持能够下单并按服务端目录价计价', function () {
    $product = s12MarketProduct();
    $this->actingAs(s12Customer(), 'customer');
    $payload = s12OrderPayload($product->id); $payload['price'] = 0.01;
    $response = postJson(route('shop.api.tob-order.store'), $payload)->assertOk()->assertJsonPath('success', true);
    expect((float) $response->json('grand_total'))->toBeGreaterThan(0.01);
    expect(DB::table('orders')->where('id', $response->json('order_id'))->value('order_type'))->toBe('skill');
});

it('混入不可售技能的多商品单整体拒绝，不产生半张订单', function () {
    $first = s12MarketProduct(); $second = s12MarketProduct('draft');
    $this->actingAs(s12Customer(), 'customer');
    $before = [DB::table('orders')->count(), DB::table('order_items')->count()];
    $payload = s12OrderPayload($first->id);
    $payload['items'] = [['product_id' => $first->id, 'qty' => 1], ['product_id' => $second->id, 'qty' => 1]];
    postJson(route('shop.api.tob-order.store'), $payload)->assertStatus(409);
    expect([DB::table('orders')->count(), DB::table('order_items')->count()])->toBe($before);
});

it('购物车加入时也校验技能发布资格', function () {
    $this->actingAs(s12Customer(), 'customer');
    postJson(route('shop.api.checkout.cart.store'), ['product_id' => s12MarketProduct('draft')->id, 'quantity' => 1])
        ->assertStatus(409)->assertJsonPath('code', 'SKILL_UNAVAILABLE');
});

it('已加入购物车的技能撤回发布后，最终结账再次拒绝且不建单', function () {
    $product = s12MarketProduct();
    $this->actingAs(s12Customer(), 'customer');
    postJson(route('shop.api.checkout.cart.store'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();
    SkillVersion::where('product_id', $product->id)->update(['status' => 'archived']);
    $count = DB::table('orders')->count();
    postJson(route('shop.checkout.onepage.orders.store'))->assertStatus(409)->assertJsonPath('code', 'SKILL_UNAVAILABLE');
    expect(DB::table('orders')->count())->toBe($count);
});

it('分页连续返回真实技能，末页不再显示加载更多', function () {
    $first = s12MarketProduct(); $second = s12MarketProduct();
    $response = getJson(route('shop.api.skill-catalog.search', ['limit' => 1]))->assertOk();
    expect($response->json('data.0.id'))->toBe($second->id);
    $response->assertJsonPath('pagination.has_more', true)->assertJsonPath('data.0.market_eligible', true);
    getJson(route('shop.api.skill-catalog.search', ['limit' => 1, 'page' => 2]))->assertOk()->assertJsonPath('data.0.id', $first->id);
});

it('筛选元数据不将仅展示机型当作可交付机型推荐', function () {
    $models = getJson(route('shop.api.skill-catalog.filters'))->assertOk()->json('data.models');
    $codes = DB::table('device_model')->where('status', 'display_only')->pluck('model_code');
    expect(array_intersect(collect($models)->pluck('code')->all(), $codes->all()))->toBe([]);
});

it('当前购买判断读取本事务刚更新的版本状态', function () {
    $product = s12MarketProduct();
    SkillVersion::where('product_id', $product->id)->update(['status' => 'archived']);
    $problem = app(SkillMarketService::class)->purchaseProblem([$product->id], s12Customer()->id, 'skill');
    expect($problem['code'])->toBe('SKILL_UNAVAILABLE');
});

it('不可公开或价格缺失的发布技能不能被展示成免费供给或继续购买', function (array $attributes) {
    $product = s12MarketProduct();
    DB::table('product_flat')->where('product_id', $product->id)->update($attributes);
    expect(collect(getJson(route('shop.api.skill-catalog.search'))->assertOk()->json('data'))->pluck('id')->all())->not->toContain($product->id);
    getJson(route('shop.api.products.show', $product->id))->assertStatus(409)->assertJsonPath('code', 'SKILL_UNAVAILABLE');
    $this->actingAs(s12Customer(), 'customer');
    postJson(route('shop.api.tob-order.store'), s12OrderPayload($product->id))->assertStatus(409)->assertJsonPath('code', 'SKILL_UNAVAILABLE');
})->with([
    'hidden' => [['visible_individually' => 0]], 'disabled' => [['status' => 0]],
    'missing-price' => [['price' => null]], 'negative-price' => [['price' => -1]],
]);

it('真实零元技能保持免费而不是被当作价格缺失', function () {
    $product = s12MarketProduct();
    $repo = app(\Webkul\Product\Repositories\ProductRepository::class);
    $repo->update(['price' => 0, 'locale' => app()->getLocale(), 'channel' => core()->getCurrentChannel()->code], $product->id, ['price']);
    \Illuminate\Support\Facades\Event::dispatch('catalog.product.update.after', $product->fresh());
    expect((float) DB::table('product_flat')->where('product_id', $product->id)->value('price'))->toBe(0.0);
    $rows = collect(getJson(route('shop.api.skill-catalog.search'))->assertOk()->json('data'));
    expect($rows->firstWhere('id', $product->id)['price'])->toBe(0);
    $detail = getJson(route('shop.api.products.show', $product->id))->assertOk();
    expect((float) $detail->json('data.prices.final.price'))->toBe(0.0);
    $this->actingAs(s12Customer(), 'customer');
    postJson(route('shop.api.tob-order.store'), s12OrderPayload($product->id))->assertOk()->assertJsonPath('success', true)->assertJsonPath('grand_total', 0);
});

it('回退到旧发布版后，超过二十条归档历史也不能挤掉当前可购买版本', function () {
    $product = s12MarketProduct();
    $current = SkillVersion::where('product_id', $product->id)->first();
    $current->update(['released_at' => now()->subYear()]);
    for ($i = 1; $i <= 21; $i++) {
        SkillVersion::create(['product_id' => $product->id, 'version' => '2.0.'.$i, 'status' => 'archived', 'channel' => 'stable', 'released_at' => now()->subMinutes($i)]);
    }
    $response = getJson(route('shop.api.skill-catalog.versions', $product->id))->assertOk()->assertJsonPath('current_version_id', $current->id);
    expect(collect($response->json('data'))->pluck('id')->all())->toContain($current->id);
});
