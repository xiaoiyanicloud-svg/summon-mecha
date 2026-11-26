<?php

use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillEntitlement;

use function Pest\Laravel\postJson;

/**
 * A5 · 设备执行前授权回源校验。
 *
 * 目标不是"查得到授权"，而是把每一种"不该放行"的情况固定成稳定 reason：
 * 未绑定 / 没买 / 版本不符 / 已吊销 / 已过期 / 未支付。
 */

function a5Binding(bool $withDevice = true): DeviceBinding
{
    $deviceId = null;

    if ($withDevice) {
        $deviceId = Device::create([
            'sn'          => 'A5-DEV-'.uniqid(),
            'model_code'  => 'mock',
            'bind_status' => 'bound',
            'bound_at'    => now(),
        ])->id;
    }

    return DeviceBinding::create([
        'did'         => 'did_a5_'.uniqid(),
        'sn'          => 'A5-SN-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
        'bound_at'    => now(),
        'devices_id'  => $deviceId,
    ]);
}

function a5Entitlement(DeviceBinding $binding, array $overrides = []): SkillEntitlement
{
    $order = Order::factory()->create([
        'status' => $overrides['order_status'] ?? Order::STATUS_PROCESSING,
    ]);

    unset($overrides['order_status']);

    return SkillEntitlement::create(array_merge([
        'order_id'         => $order->id,
        'order_item_id'    => null,
        'product_id'       => 901,
        'skill_version_id' => null,
        'device_id'        => $binding->devices_id,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'delivery_channel' => 'agent',
        'activations'      => 0,
        'max_activations'  => 1,
        'granted_at'       => now(),
        'expires_at'       => null,
        'revoked_at'       => null,
    ], $overrides));
}

function a5Headers(): array
{
    return ['X-Agent-Key' => 'pest-a5-key'];
}

function a5Check(DeviceBinding $binding, array $extra = [])
{
    return postJson(route('shop.api.agent.entitlement.check'), array_merge([
        'did'      => $binding->did,
        'skill_id' => 901,
    ], $extra), a5Headers());
}

beforeEach(function () {
    config(['deploy_gateways.agent_key' => 'pest-a5-key']);
});

it('A5-E-01 有效且已支付的授权 → allowed=true，返回授权信息', function () {
    $binding = a5Binding();
    $entitlement = a5Entitlement($binding);

    a5Check($binding)
        ->assertOk()
        ->assertJsonPath('data.allowed', true)
        ->assertJsonPath('data.reason', 'OK')
        ->assertJsonPath('data.entitlement_id', (int) $entitlement->id)
        ->assertJsonPath('data.max_activations', 1)
        ->assertJsonPath('data.activations', 0);
});

it('A5-E-02 已吊销 → allowed=false / REVOKED', function () {
    $binding = a5Binding();
    a5Entitlement($binding, [
        'status'     => SkillEntitlement::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    a5Check($binding)
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.reason', 'REVOKED');
});

it('A5-E-03 已过期 → allowed=false / EXPIRED', function () {
    $binding = a5Binding();
    a5Entitlement($binding, ['expires_at' => now()->subMinute()]);

    a5Check($binding)
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.reason', 'EXPIRED');
});

it('A5-E-04 订单未支付 → allowed=false / NOT_PAID', function () {
    $binding = a5Binding();
    a5Entitlement($binding, ['order_status' => Order::STATUS_PENDING]);

    a5Check($binding)
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.reason', 'NOT_PAID');
});

it('A5-E-05 指定版本与授权版本不符 → VERSION_MISMATCH；授权版本为空（跟随最新）则放行', function () {
    $binding = a5Binding();
    a5Entitlement($binding, ['skill_version_id' => 555]);

    a5Check($binding, ['skill_version_id' => 666])
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.reason', 'VERSION_MISMATCH');

    a5Check($binding, ['skill_version_id' => 555])
        ->assertOk()
        ->assertJsonPath('data.allowed', true);

    $followLatest = a5Binding();
    a5Entitlement($followLatest, ['skill_version_id' => null]);

    a5Check($followLatest, ['skill_version_id' => 666])
        ->assertOk()
        ->assertJsonPath('data.allowed', true);
});

it('A5-E-06 设备未绑定到 devices 行 → DEVICE_NOT_BOUND（不能证明归属）', function () {
    $binding = a5Binding(withDevice: false);
    a5Entitlement($binding, ['device_id' => null]);

    a5Check($binding)
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.reason', 'DEVICE_NOT_BOUND');
});

it('A5-E-07 没有该技能的授权 → NO_ENTITLEMENT', function () {
    $binding = a5Binding();

    a5Check($binding)
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.reason', 'NO_ENTITLEMENT');
});

it('A5-E-08 设备不存在 → 404，而不是"未授权"', function () {
    postJson(route('shop.api.agent.entitlement.check'), [
        'did'      => 'did_missing_'.uniqid(),
        'skill_id' => 901,
    ], a5Headers())
        ->assertStatus(404)
        ->assertJsonPath('code', 'DEVICE_NOT_REGISTERED');
});
