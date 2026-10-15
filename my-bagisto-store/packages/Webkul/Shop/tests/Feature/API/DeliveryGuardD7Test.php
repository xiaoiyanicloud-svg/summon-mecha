<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\SkillEntitlement;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * 加固第六轮 · D7 —— 直接调 deliver() 的两处必须自己兜住异常。
 *
 * 失败场景是真实存在的:`SkillDeliveryManager::channel()` 在通道**未注册**时抛
 * `InvalidArgumentException`(配置写错、或 config/skill_delivery.php 被改动),
 * 而这两个调用点在此之前**没有任何 try/catch** —— 于是一个配置错误会变成 500。
 *
 * 更糟的是这两处的库改动都发生在 `deliver()` **之前**:
 *   · SkillController::bindDevice   —— 授权已经写了 device_id / meta 并 save()
 *   · DeviceController::store       —— 设备已经建好,且每轮循环都在改库
 * 所以 500 会让用户以为"整个操作没成",去重复绑定 / 重复登记同 SN 设备。
 * 断言的重点因此是:接口**不返回 500**,并且如实说明"绑定成了、下发没成"。
 */

/** 未注册的交付通道 —— 用来触发 SkillDeliveryManager 的 InvalidArgumentException。 */
const D7_UNKNOWN_CHANNEL = '__no_such_channel__';

function d7Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'D7',
        'last_name'   => 'Guard',
        'email'       => 'd7'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function d7Device(Customer $customer, string $sn): Device
{
    return Device::create([
        'customer_id' => $customer->id,
        'sn'          => $sn,
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);
}

function d7Entitlement(Customer $customer, string $status): SkillEntitlement
{
    // ⚠ order_item_id 有唯一键且 NOT NULL —— 不显式给值会全部落到 0 而互相撞车
    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 900000 + random_int(1, 90000),
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => $status,
        'delivery_channel' => D7_UNKNOWN_CHANNEL,
        'granted_at'       => now(),
    ]);
}

it('D7 · 前置:这个通道确实是未注册的(否则下面的用例测不到东西)', function () {
    expect(array_keys((array) config('skill_delivery.channels', [])))
        ->not->toContain(D7_UNKNOWN_CHANNEL);

    expect(fn () => app(\Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager::class)
        ->channel(D7_UNKNOWN_CHANNEL))
        ->toThrow(InvalidArgumentException::class);
});

it('D7 · bindDevice 遇到未注册通道:200 + SKILL_DELIVERY_FAILED,不是 500', function () {
    $customer = d7Customer();
    $device = d7Device($customer, 'D7-BIND-'.uniqid());
    $entitlement = d7Entitlement($customer, SkillEntitlement::STATUS_GRANTED);

    actingAs($customer, 'customer');

    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SKILL_DELIVERY_FAILED');

    // ⚠ 关键:绑定确实落库了。所以响应**必须**说"已绑定,但下发没成"——
    //   报成整体失败会让用户以为没绑上,再反复重绑。
    $fresh = $entitlement->fresh();

    expect((int) $fresh->device_id)->toBe((int) $device->id);
});

it('D7 · bindDevice 成功路径:delivery 照旧回传,信封不变', function () {
    // 反向断言:加 try/catch 不能顺手改掉成功体的形状
    $customer = d7Customer();
    $device = d7Device($customer, 'D7-OK-'.uniqid());

    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 900000 + random_int(1, 90000),
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'app',
        'granted_at'       => now(),
    ]);

    actingAs($customer, 'customer');

    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertOk()
        ->assertJsonPath('data.device_confirmed', true)
        ->assertJsonPath('data.device_id', $device->id)
        ->assertJsonStructure(['message', 'data' => ['delivery']]);
});

it('D7 · 登记设备时认领授权,一条通道坏了不拖垮整批,也不把登记打成 500', function () {
    $customer = d7Customer();

    // 两条待认领的授权:一条通道未注册(必炸),一条通道正常
    d7Entitlement($customer, SkillEntitlement::STATUS_PENDING_BIND);

    $okEntitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 900000 + random_int(1, 90000),
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => SkillEntitlement::STATUS_PENDING_BIND,
        'delivery_channel' => 'app',
        'granted_at'       => now(),
    ]);

    actingAs($customer, 'customer');

    // 设备已经建好了 —— 这里必须 201,不能因为下发失败而 500
    $res = postJson(route('shop.api.devices.store'), ['sn' => 'D7-CLAIM-'.uniqid()])
        ->assertStatus(201)
        ->assertJsonPath('claim.failed', 1)
        ->assertJsonPath('claim.claimed', 1);

    expect($res->json('message'))->toContain('未能自动下发');

    // ⚠ 后一条不能被前一条的异常跳过 —— 这正是"逐条 try/catch"与"整段 try/catch"的区别
    expect((int) $okEntitlement->fresh()->device_id)->not->toBe(0);
});
