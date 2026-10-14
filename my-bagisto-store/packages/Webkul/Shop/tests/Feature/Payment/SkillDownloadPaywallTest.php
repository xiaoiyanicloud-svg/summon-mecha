<?php

use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\SkillEntitlement;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * M2 · 未付款即可拿到技能包（付费墙挂在"建单"而非"支付成功"）。
 *
 * 背景：授权在**建单事务内**就落库（Listeners/SkillDelivery 挂 checkout.order.save.after），
 * `license_key` 当场生成；而 `isUsable()` 只看 revoked/expired，**完全不含订单/支付状态**；
 * 下载接口只校验 findOwnedEntitlement + isUsable() —— 于是"下单不付款"即可下包。
 *
 * 修法：交付物下载以「订单已支付(processing/completed)」为前提（isOrderPaid()）。
 */

function paywallCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Wall',
        'last_name'   => 'Test',
        'email'       => 'wall'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function paywallOrder(Customer $customer, string $status): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => 'WALL'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => $status,
        'grand_total'  => 199.00,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function paywallEntitlement(Customer $customer, int $orderId): SkillEntitlement
{
    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);
}

it('未付款（订单 pending）→ 下载被拒 409', function () {
    $customer = paywallCustomer();
    $orderId = paywallOrder($customer, Order::STATUS_PENDING);
    $entitlement = paywallEntitlement($customer, $orderId);

    actingAs($customer, 'customer');

    getJson(route('shop.api.skills.download', ['entitlementId' => $entitlement->id, 'assetId' => 1]))
        ->assertStatus(409);
});

it('支付成功后（订单 processing）→ 付费墙放行（不再 409，走到交付物查找）', function () {
    $customer = paywallCustomer();
    $orderId = paywallOrder($customer, Order::STATUS_PROCESSING);
    $entitlement = paywallEntitlement($customer, $orderId);

    actingAs($customer, 'customer');

    // 该授权没有交付物 → 404（说明已越过付费墙，不是被 409 挡下）
    getJson(route('shop.api.skills.download', ['entitlementId' => $entitlement->id, 'assetId' => 1]))
        ->assertStatus(404);
});

it('isOrderPaid：只有 processing / completed 为真，pending / canceled / 无订单为假', function () {
    $customer = paywallCustomer();

    $paid = paywallEntitlement($customer, paywallOrder($customer, Order::STATUS_PROCESSING));
    $done = paywallEntitlement($customer, paywallOrder($customer, Order::STATUS_COMPLETED));
    $unpaid = paywallEntitlement($customer, paywallOrder($customer, Order::STATUS_PENDING));
    $canceled = paywallEntitlement($customer, paywallOrder($customer, Order::STATUS_CANCELED));

    // 无订单（order_id 指向不存在的订单）
    $orphan = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 999999999,
        'order_item_id'    => 990000 + random_int(1, 9999),
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'app',
    ]);

    expect($paid->isOrderPaid())->toBeTrue()
        ->and($done->isOrderPaid())->toBeTrue()
        ->and($unpaid->isOrderPaid())->toBeFalse()
        ->and($canceled->isOrderPaid())->toBeFalse()
        ->and($orphan->isOrderPaid())->toBeFalse();
});
