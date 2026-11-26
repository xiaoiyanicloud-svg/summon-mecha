<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\getJson;

/**
 * A4 · 声明式目标状态：desired / actual / drift / 自动收敛（默认关闭）。
 *
 * 这些用例固定三件事：
 *   1. 只有"已支付 + 有效 + 已发布版本"的授权才进入 desired；
 *   2. drift 区分 missing / version_mismatch / extra，且不自动卸载 extra；
 *   3. auto_converge 默认关闭；打开也只对"agent 通道 + 有包"的缺失项建任务，且幂等。
 */

function a4Binding(bool $withDevice = true): DeviceBinding
{
    $deviceId = null;

    if ($withDevice) {
        $deviceId = Device::create([
            'sn'          => 'A4-DEV-'.uniqid(),
            'model_code'  => 'mock',
            'bind_status' => 'bound',
            'bound_at'    => now(),
        ])->id;
    }

    return DeviceBinding::create([
        'did'         => 'did_a4_'.uniqid(),
        'sn'          => 'A4-SN-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
        'bound_at'    => now(),
        'devices_id'  => $deviceId,
    ]);
}

function a4Version(int $productId, array $overrides = []): SkillVersion
{
    return SkillVersion::create(array_merge([
        'product_id'        => $productId,
        'seller_id'         => null,
        'version'           => '1.0.'.random_int(100, 999),
        'channel'           => SkillVersion::CHANNEL_STABLE,
        'delivery_channels' => ['agent', 'manual'],
        'risk_level'        => 'low',
        'status'            => SkillVersion::STATUS_PUBLISHED,
        'license_mode'      => SkillVersion::LICENSE_PER_DEVICE,
        'pricing_mode'      => 'buyout',
        'released_at'       => now(),
    ], $overrides));
}

function a4Entitlement(DeviceBinding $binding, int $productId, ?int $versionId = null, array $overrides = []): SkillEntitlement
{
    $order = Order::factory()->create([
        'status' => $overrides['order_status'] ?? Order::STATUS_PROCESSING,
    ]);

    unset($overrides['order_status']);

    return SkillEntitlement::create(array_merge([
        'order_id'         => $order->id,
        'order_item_id'    => null,
        'product_id'       => $productId,
        'skill_version_id' => $versionId,
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

function a4Get(DeviceBinding $binding)
{
    return getJson(
        route('shop.api.agent.desired-state').'?did='.urlencode($binding->did),
        ['X-Agent-Key' => 'pest-a4-key']
    );
}

beforeEach(function () {
    config([
        'deploy_gateways.agent_key' => 'pest-a4-key',
        'deploy_gateways.desired_state.auto_converge' => false,
    ]);
});

it('A4-D-01 有效授权 + 已发布版本 → desired 含该技能，actual 为空 → drift.missing', function () {
    $binding = a4Binding();
    $version = a4Version(910);
    a4Entitlement($binding, 910, $version->id);

    a4Get($binding)
        ->assertOk()
        ->assertJsonPath('data.desired.0.skill_id', 910)
        ->assertJsonPath('data.desired.0.skill_version_id', (int) $version->id)
        ->assertJsonPath('data.drift.missing.0.skill_id', 910)
        ->assertJsonPath('data.drift.extra', [])
        ->assertJsonPath('data.converge.enabled', false);

    expect(strlen(a4Get($binding)->json('data.generation')))->toBe(32);
});

it('A4-D-02 实际已装同一版本 → 无 drift', function () {
    $binding = a4Binding();
    $version = a4Version(911);
    a4Entitlement($binding, 911, $version->id);
    InstallRecord::create([
        'did'              => $binding->did,
        'skill_version_id' => $version->id,
        'channel'          => 'agent',
        'status'           => 'installed',
        'current_version'  => $version->version,
        'installed_at'     => now(),
    ]);

    a4Get($binding)
        ->assertOk()
        ->assertJsonPath('data.drift.missing', [])
        ->assertJsonPath('data.drift.version_mismatch', [])
        ->assertJsonPath('data.drift.extra', []);
});

it('A4-D-03 实际版本落后 → version_mismatch；实际多出的技能 → extra（不自动卸载）', function () {
    $binding = a4Binding();
    $desiredVersion = a4Version(912);
    $oldVersion = a4Version(912, ['version' => '0.9.0']);
    $extraVersion = a4Version(913);
    a4Entitlement($binding, 912, $desiredVersion->id);

    InstallRecord::create([
        'did' => $binding->did, 'skill_version_id' => $oldVersion->id,
        'channel' => 'agent', 'status' => 'installed', 'current_version' => '0.9.0', 'installed_at' => now(),
    ]);
    InstallRecord::create([
        'did' => $binding->did, 'skill_version_id' => $extraVersion->id,
        'channel' => 'agent', 'status' => 'installed', 'current_version' => $extraVersion->version, 'installed_at' => now(),
    ]);

    $response = a4Get($binding)->assertOk();

    expect($response->json('data.drift.version_mismatch'))->toHaveCount(1)
        ->and($response->json('data.drift.version_mismatch.0.skill_id'))->toBe(912)
        ->and($response->json('data.drift.extra'))->toHaveCount(1)
        ->and($response->json('data.drift.extra.0.skill_id'))->toBe(913)
        // extra 只是报告，绝不在这个只读接口里建卸载任务
        ->and(DeployTask::where('did', $binding->did)->count())->toBe(0);
});

it('A4-D-04 未支付 / 未发布版本的授权不进入 desired', function () {
    $binding = a4Binding();
    $unpaidVersion = a4Version(914);
    a4Entitlement($binding, 914, $unpaidVersion->id, ['order_status' => Order::STATUS_PENDING]);

    $draftVersion = a4Version(915, ['status' => SkillVersion::STATUS_IN_REVIEW]);
    a4Entitlement($binding, 915, $draftVersion->id);

    a4Get($binding)
        ->assertOk()
        ->assertJsonPath('data.desired', []);
});

it('A4-D-05 auto_converge 默认关闭：只报告，不建任务', function () {
    $binding = a4Binding();
    $version = a4Version(916);
    a4Entitlement($binding, 916, $version->id);

    a4Get($binding)->assertOk();

    expect(DeployTask::where('did', $binding->did)->count())->toBe(0);
});

it('A4-D-06 auto_converge 打开：仅对有 agent 通道 + 有包哈希的缺失项建任务，且幂等', function () {
    Storage::fake(SkillAsset::disk());
    config(['deploy_gateways.desired_state.auto_converge' => true]);

    $binding = a4Binding();
    $version = a4Version(917);
    a4Entitlement($binding, 917, $version->id);

    Storage::disk(SkillAsset::disk())->put('a4/package.skill', 'probe-bytes');
    $asset = SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'asset_role'       => SkillAsset::ROLE_SOURCE,
        'asset_key'        => 'a4-package',
        'file_path'        => 'a4/package.skill',
        'file_name'        => 'package.skill',
        'file_size'        => 11,
        'file_hash'        => hash('sha256', 'probe-bytes'),
        'sort_order'       => 1,
    ]);
    expect($asset->fileExists())->toBeTrue();

    $first = a4Get($binding)->assertOk();
    $second = a4Get($binding)->assertOk();

    expect($first->json('data.converge.enabled'))->toBe(true)
        ->and($first->json('data.converge.enqueued'))->toHaveCount(1)
        ->and($second->json('data.converge.enqueued'))->toHaveCount(1)
        ->and(DeployTask::where('did', $binding->did)->count())->toBe(1);
});
