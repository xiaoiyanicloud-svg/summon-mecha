<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Checkout\Models\Cart;
use Webkul\Checkout\Models\CartAddress;
use Webkul\Checkout\Models\CartItem;
use Webkul\Checkout\Models\CartPayment;
use Webkul\Checkout\Models\CartShippingRate;
use Webkul\Customer\Models\Customer;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * X4 · Shop 侧审计留痕。
 *
 * 改前:OperationLogger 的调用点全在 Admin —— Shop 侧的下单/取消、设备登记/绑定、
 * 技能下载/绑定/确认安装、用户报案**一处都不写 operation_log**。
 * 出事要追"谁按了熔断、谁改绑了设备、谁下了哪一单"时查无此人,且历史无法补。
 *
 * (熔断/灰度/支付回调/授权发放 已在 M8/P10 批次接上,本文件不重复。)
 */

function x4Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Audit',
        'last_name'   => 'Trail',
        'email'       => 'x4'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function x4HasLog(string $action, string $targetType, int $targetId, ?int $actorId = null): bool
{
    $q = DB::table('operation_log')
        ->where('action', $action)
        ->where('target_type', $targetType)
        ->where('target_id', $targetId);

    if ($actorId !== null) {
        $q->where('actor_id', $actorId);
    }

    return $q->exists();
}

it('X4 · 用户报案写 operation_log(actor=customer)', function () {
    $customer = x4Customer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => 'minor',
        'description' => 'x',
    ])->assertStatus(201);

    $id = DB::table('incident')->where('customer_id', $customer->id)->value('id');

    expect(x4HasLog('incident.report', 'incident', $id, $customer->id))->toBeTrue();
});

it('X4 · 设备登记 / 改机型 / 解绑 各写一条留痕', function () {
    $customer = x4Customer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.devices.store'), ['sn' => 'X4-'.uniqid()])
        ->assertStatus(201);

    $deviceId = $res->json('data.id');

    expect(x4HasLog('device.register', 'device', $deviceId, $customer->id))->toBeTrue();

    putJson(route('shop.api.devices.update', $deviceId), ['display_name' => '车间一号'])
        ->assertOk();

    expect(x4HasLog('device.update', 'device', $deviceId, $customer->id))->toBeTrue();

    deleteJson(route('shop.api.devices.destroy', $deviceId))->assertOk();

    expect(x4HasLog('device.unbind', 'device', $deviceId, $customer->id))->toBeTrue();
});

it('X4 · 技能绑定设备写留痕', function () {
    $customer = x4Customer();
    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => 'X4B-'.uniqid(),
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);
    $entitlement = x4Entitlement($customer, SkillEntitlement::STATUS_PENDING_BIND);

    actingAs($customer, 'customer');

    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertOk();

    expect(x4HasLog('skill.bind_device', 'skill_entitlement', $entitlement->id, $customer->id))->toBeTrue();
});

it('X4 · 自报安装写留痕', function () {
    $customer = x4Customer();
    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => 'X4C-'.uniqid(),
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);
    $entitlement = x4Entitlement($customer, SkillEntitlement::STATUS_GRANTED, $device->id);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk();

    expect(x4HasLog('skill.confirm_install', 'skill_entitlement', $entitlement->id, $customer->id))->toBeTrue();
});

it('X4 · 技能包下载写留痕(不记明文路径之外的东西)', function () {
    Storage::fake(SkillAsset::disk());

    $customer = x4Customer();
    $versionId = 900000 + random_int(1, 90000);
    $path = 'skills/x4/demo.skill';

    Storage::disk(SkillAsset::disk())->put($path, 'SKILL-BYTES');

    // 版本行必须存在:assetsOf() 经 versionOf() 取版本,没有版本行 → 交付物查找为空 → 404
    DB::table('skill_versions')->insert([
        'id'          => $versionId,
        'product_id'  => 900000 + random_int(1, 90000),
        'version'     => '1.0.0',
        'status'      => 'published',
        'released_at' => now(),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $assetId = DB::table('skill_assets')->insertGetId([
        'skill_version_id' => $versionId,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => $path,
        'file_name'        => 'demo.skill',
        'file_size'        => 11,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'X4'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing', // 已支付,越过付费墙
        'grand_total'  => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $entitlement = x4Entitlement($customer, SkillEntitlement::STATUS_ACTIVE, null, $versionId, $orderId);

    actingAs($customer, 'customer');

    get(route('shop.api.skills.download', ['entitlementId' => $entitlement->id, 'assetId' => $assetId]))
        ->assertOk();

    expect(x4HasLog('skill.download', 'skill_entitlement', $entitlement->id, $customer->id))->toBeTrue();
});

it('X4 · 订单自助取消写留痕', function () {
    $customer = x4Customer();
    $orderId = DB::table('orders')->insertGetId([
        'increment_id'        => 'X4C'.uniqid(),
        'customer_id'         => $customer->id,
        'customer_email'      => $customer->email,
        'customer_first_name' => $customer->first_name,
        'customer_last_name'  => $customer->last_name,
        'status'              => 'pending',
        'grand_total'         => 100,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.customers.orders.cancel', $orderId))->assertOk();

    expect(x4HasLog('order.cancel', 'order', $orderId, $customer->id))->toBeTrue();
});

it('X4 · 下单写留痕(游客下单 actor_id 为 null 也要留痕)', function () {
    // Arrange:一个可游客下单的简单商品 + 完整购物车(照 CheckoutTest 的最小夹具)
    $product = (new ProductFaker([
        'attributes' => [
            5  => 'new',
            26 => 'guest_checkout',
        ],
        'attribute_value' => [
            'new'            => ['boolean_value' => true],
            'guest_checkout' => ['boolean_value' => true],
        ],
    ]))
        ->getSimpleProductFactory()
        ->create();

    $cart = Cart::factory()->create(['shipping_method' => 'free_free']);

    CartItem::factory()->create([
        'cart_id'             => $cart->id,
        'product_id'          => $product->id,
        'sku'                 => $product->sku,
        'quantity'            => 1,
        'name'                => $product->name,
        'price'               => $price = core()->convertPrice($product->price),
        'price_incl_tax'      => $price,
        'base_price'          => $product->price,
        'base_price_incl_tax' => $product->price,
        'total'               => $price,
        'total_incl_tax'      => $price,
        'base_total'          => $product->price,
        'weight'              => 0,
        'total_weight'        => 0,
        'base_total_weight'   => 0,
        'type'                => $product->type,
        'additional'          => ['product_id' => $product->id, 'quantity' => '1'],
    ]);

    CartAddress::factory()->create(['cart_id' => $cart->id, 'address_type' => CartAddress::ADDRESS_TYPE_BILLING]);
    $shipping = CartAddress::factory()->create(['cart_id' => $cart->id, 'address_type' => CartAddress::ADDRESS_TYPE_SHIPPING]);

    CartPayment::factory()->create([
        'cart_id'      => $cart->id,
        'method'       => $paymentMethod = 'cashondelivery',
        'method_title' => core()->getConfigData('sales.payment_methods.'.$paymentMethod.'.title'),
    ]);

    CartShippingRate::factory()->create([
        'carrier'         => 'free',
        'carrier_title'   => 'Free shipping',
        'method'          => 'free_free',
        'method_title'    => 'Free Shipping',
        'cart_address_id' => $shipping->id,
        'cart_id'         => $cart->id,
    ]);

    cart()->setCart($cart);
    cart()->collectTotals();

    postJson(route('shop.checkout.onepage.orders.store'))->assertOk();

    $orderId = DB::table('orders')->orderByDesc('id')->value('id');

    // 订单确实建了,并且留了痕(游客单 customer_id 为空 → actor_id 也为空)
    expect($orderId)->not->toBeNull()
        ->and(x4HasLog('order.create', 'order', $orderId))->toBeTrue();
});

function x4Entitlement(
    Customer $customer,
    string $status,
    ?int $deviceId = null,
    ?int $versionId = null,
    ?int $orderId = null,
): SkillEntitlement {
    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId ?? 900000 + random_int(1, 90000),
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'skill_version_id' => $versionId,
        'device_id'        => $deviceId,
        'status'           => $status,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);
}
