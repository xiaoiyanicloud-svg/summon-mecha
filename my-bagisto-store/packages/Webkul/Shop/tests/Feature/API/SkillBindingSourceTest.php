<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * v1 三接口的「设备唯一真相源 = binding」对齐（v3.4 步骤 2）。
 *
 * 要证明的三件事：
 *   1. 归属以 **binding** 为准 —— binding 说不是你的，就不是你的（哪怕 devices 表说是）；
 *   2. 缺 binding 的**历史设备能自愈** —— 在写接口里补建，而不是把用户挡在门外；
 *   3. **读接口不写库** —— GET 兼容性校验只读 binding，不偷偷建。
 *
 * 为什么这件事重要：第 8 步的下发链路**只认 binding**（deploy_task.did 来自它）。
 * v1 若还按 devices.customer_id 判，两边口径就会分叉 —— 指派得到、下发发不出去。
 */

function bindingCustomer(string $email = null): Customer
{
    return Customer::create([
        'first_name'  => 'Bind',
        'last_name'   => 'Test',
        'email'       => $email ?: 'bind'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 模拟"binding 机制之前登记的历史设备"：devices 有行、binding_id 为空。 */
function legacyDevice(Customer $customer, string $sn): Device
{
    return Device::create([
        'customer_id' => $customer->id,
        'sn'          => $sn,
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);
}

function grantedEntitlement(Customer $customer, ?Device $device = null): SkillEntitlement
{
    // ⚠ order_item_id 有唯一键且 NOT NULL —— 不显式给值会全部落到 0 而互相撞车
    $orderItemId = 900000 + random_int(1, 90000);

    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 900000 + random_int(1, 90000),
        'order_item_id'    => $orderItemId,
        'product_id'       => 900000 + random_int(1, 9999),
        'device_id'        => $device?->id,
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'app',
        'granted_at'       => now(),
    ]);
}

it('bindDevice：历史设备（无 binding）在这里自愈 —— 补建 binding 并回填 devices.binding_id', function () {
    $customer = bindingCustomer();
    $device = legacyDevice($customer, 'LEGACY-'.uniqid());
    $entitlement = grantedEntitlement($customer);

    expect(DeviceBinding::where('sn', $device->sn)->exists())->toBeFalse();

    actingAs($customer, 'customer');

    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertOk()
        ->assertJsonPath('data.device_confirmed', true);

    $binding = DeviceBinding::where('sn', $device->sn)->first();

    expect($binding)->not->toBeNull()
        ->and((int) $binding->owner_customer_id)->toBe($customer->id)
        ->and((int) $device->fresh()->binding_id)->toBe((int) $binding->id);

    // 授权上记下真相源的 did —— 下发链路认的就是它
    expect($entitlement->fresh()->meta['bound_did'] ?? null)->toBe($binding->did);
});

it('bindDevice：binding 归属他人时拒绝（哪怕 devices 表说是你的）', function () {
    $me = bindingCustomer();
    $other = bindingCustomer();

    $sn = 'CONFLICT-'.uniqid();

    $device = legacyDevice($me, $sn);
    $entitlement = grantedEntitlement($me);

    // 同一 SN 已有一条属于**别人**的 binding（例如对方的 Agent 先注册了）
    DeviceBinding::create([
        'did'               => 'sn_'.$sn,
        'sn'                => $sn,
        'source'            => DeviceBinding::SOURCE_AGENT,
        'owner_customer_id' => $other->id,
        'bind_status'       => 'bound',
        'bound_at'          => now(),
    ]);

    actingAs($me, 'customer');

    // ⚠ 这条以前会通过 —— 因为只查了 devices.customer_id
    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertStatus(404);

    expect($entitlement->fresh()->device_id)->toBeNull();
});

it('confirmInstall：记录 did 与确权状态，并同样触发自愈', function () {
    $customer = bindingCustomer();
    $device = legacyDevice($customer, 'CONFIRM-'.uniqid());
    $entitlement = grantedEntitlement($customer, $device);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertOk()
        ->assertJsonPath('data.status', SkillEntitlement::STATUS_ACTIVE)
        ->assertJsonPath('data.device_confirmed', true);

    $binding = DeviceBinding::where('sn', $device->sn)->firstOrFail();
    $meta = $entitlement->fresh()->meta;

    expect($meta['installed_did'] ?? null)->toBe($binding->did)
        ->and($meta['installed_by'])->toBe('user_reported');
});

it('mine：如实透出设备真相源（binding / registration_only）', function () {
    $customer = bindingCustomer();

    $confirmed = legacyDevice($customer, 'OK-'.uniqid());
    $unconfirmed = legacyDevice($customer, 'RAW-'.uniqid());

    // 一台造出 binding，另一台刻意不造
    DeviceBinding::create([
        'did' => 'sn_'.$confirmed->sn, 'sn' => $confirmed->sn,
        'source' => DeviceBinding::SOURCE_MANUAL, 'owner_customer_id' => $customer->id,
        'devices_id' => $confirmed->id, 'bind_status' => 'bound', 'bound_at' => now(),
    ]);
    $confirmed->binding_id = DeviceBinding::where('sn', $confirmed->sn)->value('id');
    $confirmed->save();

    $e1 = grantedEntitlement($customer, $confirmed);
    $e2 = grantedEntitlement($customer, $unconfirmed);

    actingAs($customer, 'customer');

    $rows = collect(getJson(route('shop.api.skills.mine'))->assertOk()->json('data'))->keyBy('id');

    expect($rows[$e1->id]['device_truth_source'])->toBe('binding')
        ->and($rows[$e1->id]['device_did'])->not->toBeNull()
        ->and($rows[$e2->id]['device_truth_source'])->toBe('registration_only')
        ->and($rows[$e2->id]['device_did'])->toBeNull();
});

it('兼容性校验是只读的：GET 不会偷偷补建 binding，但会如实提示未确权', function () {
    $customer = bindingCustomer();
    $device = legacyDevice($customer, 'RO-'.uniqid());

    $productId = 900000 + random_int(1, 9999);

    SkillVersion::create([
        'product_id' => $productId, 'version' => '1.0.0', 'channel' => 'stable',
        'status' => SkillVersion::STATUS_PUBLISHED, 'license_mode' => 'per_device',
        'released_at' => now(),
    ]);

    actingAs($customer, 'customer');

    $res = getJson(
        route('shop.api.skills.compatibility', $productId).'?device_id='.$device->id
    )->assertOk();

    // GET 不该写库
    expect(DeviceBinding::where('sn', $device->sn)->exists())->toBeFalse();

    $notes = implode(' ', $res->json('data.notes') ?? []);
    expect($notes)->toContain('尚未确权');
});
