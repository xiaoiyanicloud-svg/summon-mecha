<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\SkillEntitlement;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * D1 · 自报安装无幂等 + 激活次数竞态 + max_activations 形同虚设。
 *
 * 背景:`SkillController::confirmInstall()` 用 read-modify-write
 * (`activations = activations + 1`)且**没有"已 active 就直接返回"的幂等分支** ——
 * 连点两次 = 计两次;并发两请求会丢失更新;`max_activations` 在写路径上**从未参与判定**。
 * 激活次数是许可与计费的核心账目,不能由客户端自助篡改。
 *
 * 修法:① 已 active → 幂等返回既有状态;② 用条件原子自增,并校验 max_activations。
 */

function d1Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Install',
        'last_name'   => 'Test',
        'email'       => 'install'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function d1Device(Customer $customer): Device
{
    return Device::create([
        'customer_id' => $customer->id,
        'sn'          => 'D1-'.uniqid(),
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);
}

function d1Entitlement(Customer $customer, Device $device, int $activations = 0, ?int $max = null): SkillEntitlement
{
    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 900000 + random_int(1, 90000),
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'device_id'        => $device->id,
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'app',
        'activations'      => $activations,
        'max_activations'  => $max,
        'granted_at'       => now(),
    ]);
}

it('连发两次 confirm-install → activations 只 +1(幂等)', function () {
    $customer = d1Customer();
    $device = d1Device($customer);
    $entitlement = d1Entitlement($customer, $device);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk();
    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk();

    $fresh = $entitlement->fresh();

    expect($fresh->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        ->and($fresh->activations)->toBe(1);
});

it('第二次调用返回幂等标记,且状态不变', function () {
    $customer = d1Customer();
    $device = d1Device($customer);
    $entitlement = d1Entitlement($customer, $device);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk();

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertOk()
        ->assertJsonPath('data.idempotent', true)
        ->assertJsonPath('data.activations', 1);
});

it('max_activations=1 且已用满 → 第二次安装被拒 409,状态不变', function () {
    $customer = d1Customer();
    $device = d1Device($customer);
    // 未 active 但 activations 已达上限 —— 再装应被拒
    $entitlement = d1Entitlement($customer, $device, activations: 1, max: 1);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertStatus(409)
        ->assertJson(['code' => 'MAX_ACTIVATIONS_REACHED']);

    $fresh = $entitlement->fresh();

    expect($fresh->status)->toBe(SkillEntitlement::STATUS_GRANTED)
        ->and($fresh->activations)->toBe(1);
});

it('max_activations 未超限时放行并读到上限之内', function () {
    $customer = d1Customer();
    $device = d1Device($customer);
    $entitlement = d1Entitlement($customer, $device, activations: 0, max: 3);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertOk()
        ->assertJsonPath('data.activations', 1);
});
