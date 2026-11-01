<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

function createDevForOrders(string $prefix = 'orddev'): array
{
    $customer = Customer::create([
        'first_name'  => 'DevFirst',
        'last_name'   => 'DevLast',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'company', 'seller_name' => '机甲工坊-'.$prefix]);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), [
        'name'              => '自动巡检技能-'.$prefix,
        'price'             => 199.00,
        'short_description' => '巡检机器人核心控制包',
    ]);

    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    return compact('customer', 'profile', 'productId');
}

it('开发者后台正确核算 GMV、净销售额与授权生命周期状态，并支持按状态与关键词过滤', function () {
    $dev = createDevForOrders('metric');
    $sellerId = $dev['profile']->seller_id;
    $productId = $dev['productId'];

    // 模拟买家用户
    $buyer = Customer::create([
        'first_name'  => '建国',
        'last_name'   => '张',
        'email'       => 'zhangjianguo@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    // 模拟 Bagisto Order
    $bagistoOrder = Order::create([
        'increment_id'        => '202611020001',
        'status'              => 'completed',
        'channel_name'        => 'Default',
        'is_guest'            => 0,
        'customer_id'         => $buyer->id,
        'customer_email'      => $buyer->email,
        'customer_first_name' => $buyer->first_name,
        'customer_last_name'  => $buyer->last_name,
        'sub_total'           => 199.00,
        'base_sub_total'      => 199.00,
        'grand_total'         => 199.00,
        'base_grand_total'    => 199.00,
        'channel_id'          => core()->getDefaultChannel()->id,
    ]);

    $orderItem = OrderItem::create([
        'order_id'       => $bagistoOrder->id,
        'product_id'     => $productId,
        'sku'            => 'SKU-INSPECT-01',
        'type'           => 'simple',
        'name'           => '自动巡检技能',
        'qty_ordered'    => 1,
        'price'          => 199.00,
        'base_price'     => 199.00,
        'total'          => 199.00,
        'base_total'     => 199.00,
    ]);

    // 模拟终端设备
    $device = Device::create([
        'customer_id'   => $buyer->id,
        'sn'            => 'MECHA-SN-8888',
        'model_code'    => 'QUADRUPED_X1',
        'status'        => Device::STATUS_BOUND,
        'verify_status' => Device::VERIFY_REGISTERED,
    ]);

    // 创建授权记录 1：生效中 (active)
    $ent1 = SkillEntitlement::create([
        'customer_id'      => $buyer->id,
        'order_id'         => $bagistoOrder->id,
        'order_item_id'    => $orderItem->id,
        'product_id'       => $productId,
        'seller_id'        => $sellerId,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'license_key'      => 'LIC-ACT-'.strtoupper(uniqid()),
        'delivery_channel' => 'api',
        'device_id'        => $device->id,
        'granted_at'       => now()->subDays(2),
    ]);

    // 创建授权记录 2：已退款撤销 (revoked)
    $orderItem2 = OrderItem::create([
        'order_id'       => $bagistoOrder->id,
        'product_id'     => $productId,
        'sku'            => 'SKU-INSPECT-02',
        'type'           => 'simple',
        'name'           => '自动巡检技能-增购',
        'qty_ordered'    => 1,
        'price'          => 99.00,
        'base_price'     => 99.00,
        'total'          => 99.00,
        'base_total'     => 99.00,
    ]);

    $ent2 = SkillEntitlement::create([
        'customer_id'      => $buyer->id,
        'order_id'         => $bagistoOrder->id,
        'order_item_id'    => $orderItem2->id,
        'product_id'       => $productId,
        'seller_id'        => $sellerId,
        'status'           => SkillEntitlement::STATUS_REVOKED,
        'license_key'      => 'LIC-REV-'.strtoupper(uniqid()),
        'delivery_channel' => 'api',
        'granted_at'       => now()->subDays(5),
        'revoked_at'       => now()->subDay(),
        'meta'             => ['revoke_reason' => '客户误购，协商售后退款'],
    ]);

    // 1. Web SSR 订单列表与核算校验
    actingAs($dev['customer'], 'developer');

    $res = get(route('shop.dev.orders.index'));
    $res->assertOk()
        ->assertSee('交易与授权订单')
        ->assertSee('298.00') // GMV: 199 + 99 = 298.00
        ->assertSee('199.00') // Net Sales: 298 - 99 = 199.00
        ->assertSee('202611020001')
        ->assertSee('MECHA-SN-8888')
        ->assertSee('张*国') // PII 脱敏姓名
        ->assertSee('z***@example.com') // PII 脱敏邮箱
        ->assertSee('生效中')
        ->assertSee('已撤销');

    // 2. 状态过滤：仅查 active
    $activeRes = get(route('shop.dev.orders.index', ['status' => 'active']));
    $activeRes->assertOk()
        ->assertSee('LIC-ACT-')
        ->assertDontSee('LIC-REV-');

    // 3. 关键词过滤：搜设备 SN
    $searchRes = get(route('shop.dev.orders.index', ['keyword' => '8888']));
    $searchRes->assertOk()
        ->assertSee('MECHA-SN-8888');
});

it('开发者订单详情具备 Anti-IDOR 强隔离，买家 PII 严格脱敏并呈现完整履约时间线', function () {
    $devA = createDevForOrders('deva');
    $devB = createDevForOrders('devb');

    $buyer = Customer::create([
        'first_name'  => '四海',
        'last_name'   => '李',
        'email'       => 'lisihai@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    $order = Order::create([
        'increment_id'        => '202611020099',
        'status'              => 'completed',
        'channel_name'        => 'Default',
        'customer_id'         => $buyer->id,
        'customer_email'      => $buyer->email,
        'customer_first_name' => $buyer->first_name,
        'customer_last_name'  => $buyer->last_name,
        'sub_total'           => 150.00,
        'base_sub_total'      => 150.00,
        'grand_total'         => 150.00,
        'base_grand_total'    => 150.00,
        'channel_id'          => core()->getDefaultChannel()->id,
    ]);

    $item = OrderItem::create([
        'order_id'       => $order->id,
        'product_id'     => $devA['productId'],
        'sku'            => 'SKU-A-01',
        'type'           => 'simple',
        'name'           => 'Dev A 特有技能',
        'qty_ordered'    => 1,
        'price'          => 150.00,
        'base_price'     => 150.00,
        'total'          => 150.00,
        'base_total'     => 150.00,
    ]);

    $ent = SkillEntitlement::create([
        'customer_id'      => $buyer->id,
        'order_id'         => $order->id,
        'order_item_id'    => $item->id,
        'product_id'       => $devA['productId'],
        'seller_id'        => $devA['profile']->seller_id,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'license_key'      => 'LIC-SECURE-KEY-999',
        'delivery_channel' => 'api',
        'granted_at'       => now(),
    ]);

    // 1. Dev A 登录可正常访问自己的详情 (Web 重定向带参数或 JSON)
    actingAs($devA['customer'], 'developer');
    $resA = getJson(route('shop.dev.orders.show', $ent->id));
    $resA->assertOk()
        ->assertJsonPath('data.id', $ent->id)
        ->assertJsonPath('data.order_no', '202611020099')
        ->assertJsonPath('data.buyer_name_masked', '李*海')
        ->assertJsonPath('data.buyer_email_masked', 'l***@example.com')
        ->assertJsonPath('data.license_key', 'LIC-SECURE-KEY-999')
        ->assertJsonPath('data.status', 'active');

    // 时间线校验
    $timeline = $resA->json('data.timeline');
    expect($timeline)->toBeArray()->and(count($timeline))->toBeGreaterThanOrEqual(2);

    // 2. Dev B 登录越权访问 Dev A 的授权订单 -> 必须返回 404，绝不泄露信息
    actingAs($devB['customer'], 'developer');
    $resB = get(route('shop.dev.orders.show', $ent->id));
    $resB->assertNotFound();

    // 3. API 鉴权通道访问：GET /api/dev/sales-metrics 与 GET /api/dev/orders
    Sanctum::actingAs($devA['customer'], ['*']);
    $devA['customer']->withAccessToken(new \Laravel\Sanctum\TransientToken);

    $apiMetrics = getJson('/api/dev/sales-metrics');
    $apiMetrics->assertOk()
        ->assertJsonPath('data.gmv_yuan', '150.00')
        ->assertJsonPath('data.net_sales_yuan', '150.00')
        ->assertJsonPath('data.active_count', 1);

    $apiOrders = getJson('/api/dev/orders');
    $apiOrders->assertOk()
        ->assertJsonPath('data.0.id', $ent->id)
        ->assertJsonPath('data.0.buyer_name_masked', '李*海');

    // Dev B 访问 Dev A 的 order API -> 404
    Sanctum::actingAs($devB['customer'], ['*']);
    $devB['customer']->withAccessToken(new \Laravel\Sanctum\TransientToken);

    $apiBDetail = getJson('/api/dev/orders/'.$ent->id);
    $apiBDetail->assertNotFound();
});
