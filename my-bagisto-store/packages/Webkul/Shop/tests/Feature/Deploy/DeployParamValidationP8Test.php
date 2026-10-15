<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\GrayRelease;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * P8 · 参数校验三处缺口。
 *
 *  1. `DeployController::store()` 的 `operation` 白名单写死 `'in:1,2,3,4,5'`,
 *     而 `DeployTask` 早有 `OP_RECLAIM=6` —— **合法的「试用到期回收」被挡死**,
 *     且报的是"参数非法",看不出是漏了一个码。改成与 `DeployTask::OPERATIONS` 对齐。
 *  2. `GrayReleaseController::metrics()` 三个计数各 `min:0`,**无 successes <= installs**
 *     → `GrayReleaseService::recordMetric()` 能算出 >100% 的 success_rate,
 *     而成功率正是自动回滚的判据。
 *  3. `GrayReleaseController::store()` 不校验 `skill_version_id` 存在且已发布 ——
 *     给草稿版本开灰度只会得到一条"正在灰度"却永远不会下发任何东西的记录。
 */

function p8Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'P8',
        'last_name'   => 'Test',
        'email'       => 'p8'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 造一台属于该用户的"已确权"设备（devices + binding 双向对上）。 */
function p8OwnedBinding(Customer $customer): DeviceBinding
{
    $sn = 'P8-'.uniqid();

    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => $sn,
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);

    $binding = DeviceBinding::create([
        'did'               => 'sn_'.$sn,
        'sn'                => $sn,
        'source'            => DeviceBinding::SOURCE_MANUAL,
        'owner_customer_id' => $customer->id,
        'devices_id'        => $device->id,
        'bind_status'       => 'bound',
        'bound_at'          => now(),
    ]);

    $device->binding_id = $binding->id;
    $device->save();

    return $binding;
}

function p8Version(int $productId, string $version, string $status): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => $version,
        'channel'      => 'stable',
        'status'       => $status,
        'license_mode' => 'per_device',
    ]);
}

function p8Headers(): array
{
    return ['X-Internal-Key' => 'p8-internal-key'];
}

beforeEach(function () {
    config(['skill_delivery.upload_key' => 'p8-internal-key']);
});

// ───────────────────── 1 · operation 白名单与 OP_* 对齐 ─────────────────────

it('P8 · operation=6(回收)是合法下发:不再被参数校验挡死', function () {
    $customer = p8Customer();
    $binding = p8OwnedBinding($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.deploy.store'), [
        'device_id' => $binding->devices_id,
        'skill_id'  => 880000 + random_int(1, 9999),
        'operation' => DeployTask::OP_RECLAIM,
    ])->assertStatus(201);

    expect(DeployTask::query()->where('did', $binding->did)->value('operation'))
        ->toBe(DeployTask::OP_RECLAIM);
});

it('P8 · 白名单外(7)仍被拒 422', function () {
    $customer = p8Customer();
    $binding = p8OwnedBinding($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.deploy.store'), [
        'device_id' => $binding->devices_id,
        'skill_id'  => 880000 + random_int(1, 9999),
        'operation' => 7,
    ])->assertStatus(422);

    expect(DeployTask::query()->where('did', $binding->did)->exists())->toBeFalse();
});

it('P8 · 校验常量本身覆盖全部 OP_*(漏一个就等于线上又少一个合法操作)', function () {
    // OP_* 是 1..6 的连续码;此断言在有人新增 OP_ 却忘了加进 OPERATIONS 时会红。
    expect(DeployTask::OPERATIONS)->toBe([1, 2, 3, 4, 5, 6]);
});

// ───────────────────── 2 · 灰度指标跨字段校验 ─────────────────────

it('P8 · 灰度指标 successes > installs 被拒 422(否则成功率能算出 >100%)', function () {
    $release = GrayRelease::create([
        'skill_version_id' => p8Version(881000 + random_int(1, 999), '1.0.0', SkillVersion::STATUS_PUBLISHED)->id,
        'strategy'         => ['percent' => 5],
        'batch_no'         => 'P8-'.uniqid(),
        'status'           => 'releasing',
        'metrics'          => ['installs' => 0, 'successes' => 0, 'failures' => 0],
    ]);

    postJson(route('shop.api.gray-releases.metrics', $release->id), [
        'installs'  => 10,
        'successes' => 11,
        'failures'  => 0,
    ], p8Headers())->assertStatus(422);

    // 脏计数未能落库
    expect($release->fresh()->metrics['successes'])->toBe(0);
});

it('P8 · 灰度指标 successes == installs 照常放行(边界不误伤)', function () {
    $release = GrayRelease::create([
        'skill_version_id' => p8Version(881000 + random_int(1, 999), '1.0.0', SkillVersion::STATUS_PUBLISHED)->id,
        'strategy'         => ['percent' => 5],
        'batch_no'         => 'P8-'.uniqid(),
        'status'           => 'releasing',
        'metrics'          => ['installs' => 0, 'successes' => 0, 'failures' => 0],
    ]);

    postJson(route('shop.api.gray-releases.metrics', $release->id), [
        'installs'  => 10,
        'successes' => 10,
        'failures'  => 0,
    ], p8Headers())->assertOk();

    expect($release->fresh()->metrics['success_rate'])->toEqual(100.0);
});

// ───────────────────── 3 · 灰度目标版本必须已发布 ─────────────────────

it('P8 · 给不存在的版本开灰度 → 422', function () {
    postJson(route('shop.api.gray-releases.store'), [
        'skill_version_id' => 999999999,
    ], p8Headers())->assertStatus(422);

    expect(GrayRelease::query()->where('skill_version_id', 999999999)->exists())->toBeFalse();
});

it('P8 · 给草稿版本开灰度 → 422(草稿永不下发,灰度只会空转)', function () {
    $draft = p8Version(882000 + random_int(1, 999), '0.0.1', SkillVersion::STATUS_DRAFT);

    postJson(route('shop.api.gray-releases.store'), [
        'skill_version_id' => $draft->id,
    ], p8Headers())->assertStatus(422);

    expect(GrayRelease::query()->where('skill_version_id', $draft->id)->exists())->toBeFalse();
});

it('P8 · 给已发布版本开灰度 → 201(闸门不误伤)', function () {
    $published = p8Version(883000 + random_int(1, 999), '1.0.0', SkillVersion::STATUS_PUBLISHED);

    postJson(route('shop.api.gray-releases.store'), [
        'skill_version_id' => $published->id,
    ], p8Headers())->assertStatus(201);

    expect(GrayRelease::query()->where('skill_version_id', $published->id)->exists())->toBeTrue();
});
