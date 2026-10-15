<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Deploy\DeployTaskService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * B-3 · 试用 20 分钟闭环（D4 / 计划 §4.7 —— 2026-10-14 勘察结论：整条未落地）。
 *
 * 勘察时这条链只有"地基"：`OP_RECLAIM=6` 在白名单、`DeployTaskService` 处理了它、
 * `trialExpired()` 通知在、`(status,expires_at)` 索引在 —— 但**两端都空**：
 *   · 没有任何东西**发放** `meta.trial` 的授权（前端无按钮、后端无接口）；
 *   · 没有任何东西**扫描到期**并建回收任务。
 * 本文件取证的正是这两端，以及把它们接错时会说谎的地方：
 *   ① 试用授权真的落了 `meta.trial` + `expires_at`，并**直接下发**；
 *   ② 人工通道技能**发不出**试用（D4：20 分钟后收不回来）；
 *   ③ 冷却 / 已买断 / 非本人设备 / 未确权设备 四条闸；
 *   ④ 回收 job 把到期试用推进 `expiring` 并建 `operation=6` 任务；回收成功后落 `expired`。
 */

function b3Customer(): Customer
{
    return Customer::create([
        'first_name'  => '试用',
        'last_name'   => '客户',
        'email'       => 'b3'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 造一个技能商品；$channels 决定它声明了哪些交付通道。 */
function b3Product(array $channels = ['agent', 'manual']): int
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'b3-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('skill_versions')->insert([
        'product_id'        => $productId,
        'version'           => '1.0.0',
        'status'            => 'published',
        'delivery_channels' => json_encode($channels),
        'released_at'       => now(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    return $productId;
}

function b3VersionId(int $productId): int
{
    return (int) DB::table('skill_versions')->where('product_id', $productId)->value('id');
}

/** 一台**已确权**设备（有 binding）—— 试用要真下发，未确权设备发不出去。 */
function b3PairedDevice(Customer $customer, string $sn = null): Device
{
    $sn ??= 'B3-'.uniqid();

    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => $sn,
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);

    $binding = DeviceBinding::create([
        'did'               => 'did_b3_'.uniqid(),
        'sn'                => $sn,
        'source'            => DeviceBinding::SOURCE_AGENT,
        'owner_customer_id' => $customer->id,
        'devices_id'        => $device->id,
        'bind_status'       => DeviceBinding::BIND_STATUS_BOUND,
        'bound_at'          => now(),
        'last_online_at'    => now(),
    ]);

    $device->binding_id = $binding->id;
    $device->save();

    return $device;
}

/** 一条**已到期**的试用授权（回收 job 的靶子）。 */
function b3ExpiredTrial(Customer $customer, int $productId, ?Device $device, ?int $versionId = null): SkillEntitlement
{
    $e = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => null,
        'order_item_id'    => null,
        'product_id'       => $productId,
        'skill_version_id' => $versionId,
        'device_id'        => $device?->id,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'delivery_channel' => 'agent',
        'granted_at'       => now()->subMinutes(25),
        'expires_at'       => now()->subMinutes(5),
        'meta'             => ['trial' => true, 'trial_started_at' => now()->subMinutes(25)->toDateTimeString()],
    ]);

    return $e;
}

// ==================== ① 发放 ====================

it('B-3 · 发起试用 → 落一条 meta.trial 的 20 分钟授权，并直接下发', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent', 'manual']);
    $device = b3PairedDevice($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertStatus(201)
        ->assertJsonPath('data.trial', true)
        ->assertJsonPath('data.delivery_channel', 'agent');

    $e = SkillEntitlement::query()->where('customer_id', $customer->id)->first();

    expect($e)->not->toBeNull('试用没有落授权')
        ->and($e->isTrial())->toBeTrue()
        ->and($e->order_id)->toBeNull()      // 试用没有来源订单
        ->and($e->delivery_channel)->toBe('agent')
        ->and($e->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        // 到期时间 = 现在 + 20 分钟（容 60 秒误差）
        ->and($e->expires_at->diffInSeconds(now()->addMinutes(20)))->toBeLessThan(60)
        ->and($e->isUsable())->toBeTrue();

    // 直接下发：建了一条 operation=1 的下发任务
    $task = DeployTask::query()->where('entitlement_id', $e->id)->first();

    expect($task)->not->toBeNull('试用没有直接走下发')
        ->and($task->operation)->toBe(DeployTask::OP_INSTALL)
        ->and($task->channel)->toBe('agent');
});

it('B-3 · 人工通道技能 → 试用被拒（TRIAL_CHANNEL_UNSUPPORTED），不落授权', function () {
    $customer = b3Customer();
    $productId = b3Product(['manual']);       // 只声明人工通道
    $device = b3PairedDevice($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'TRIAL_CHANNEL_UNSUPPORTED');

    expect(SkillEntitlement::query()->where('customer_id', $customer->id)->count())->toBe(0);
});

it('B-3 · 7 天冷却内重复试用 → TRIAL_LIMIT_REACHED', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);
    $device = b3PairedDevice($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])->assertStatus(201);

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertJsonPath('code', 'TRIAL_LIMIT_REACHED');

    // 只落了一条
    expect(SkillEntitlement::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('B-3 · 已买断该技能 → TRIAL_ALREADY_OWNED（不重复发临时授权）', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);
    $device = b3PairedDevice($customer);

    // 一条带来源订单的买断授权
    SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 990000 + random_int(1, 9999),
        'order_item_id'    => 990000 + random_int(1, 9999),
        'product_id'       => $productId,
        'device_id'        => $device->id,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'delivery_channel' => 'agent',
        'granted_at'       => now(),
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertJsonPath('code', 'TRIAL_ALREADY_OWNED');
});

it('B-3 · 设备不属于本人 → 404 DEVICE_NOT_FOUND', function () {
    $customer = b3Customer();
    $other = b3Customer();
    $productId = b3Product(['agent']);
    $device = b3PairedDevice($other);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertStatus(404)
        ->assertJsonPath('code', 'DEVICE_NOT_FOUND');
});

it('B-3 · 设备未确权（只有自报登记）→ DEVICE_BINDING_MISSING', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);
    // 只登记，不建 binding
    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => 'B3-NOBIND-'.uniqid(),
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertJsonPath('code', 'DEVICE_BINDING_MISSING')
        ->assertJsonPath('success', false);
});

// ==================== ② 回收 ====================

it('B-3 · 回收 job：到期试用 → expiring + 一条 operation=6 回收任务', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);
    $device = b3PairedDevice($customer);
    $e = b3ExpiredTrial($customer, $productId, $device, b3VersionId($productId));

    Artisan::call('ops:reclaim-expired-trials');

    expect($e->fresh()->status)->toBe(SkillEntitlement::STATUS_EXPIRING);

    $task = DeployTask::query()->where('entitlement_id', $e->id)->first();

    expect($task)->not->toBeNull('没有建回收任务')
        ->and($task->operation)->toBe(DeployTask::OP_RECLAIM);
});

it('B-3 · 回收成功（设备回报）→ 授权落 expired', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);
    $device = b3PairedDevice($customer);
    $e = b3ExpiredTrial($customer, $productId, $device, b3VersionId($productId));

    Artisan::call('ops:reclaim-expired-trials');

    $task = DeployTask::query()->where('entitlement_id', $e->id)->firstOrFail();

    app(DeployTaskService::class)->onEvent($task->task_no, 'done');

    expect($e->fresh()->status)->toBe(SkillEntitlement::STATUS_EXPIRED)
        ->and($e->fresh()->isUsable())->toBeFalse();
});

it('B-3 · 从未装到设备（无 device）→ 无需回收，直接终态 expired', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);
    $e = b3ExpiredTrial($customer, $productId, null);

    Artisan::call('ops:reclaim-expired-trials');

    expect($e->fresh()->status)->toBe(SkillEntitlement::STATUS_EXPIRED);
});

it('B-3 · 回收 job 只碰试用：到期的**买断**授权（带订单号）原样不动', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);

    $buyout = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 991000 + random_int(1, 9999),
        'order_item_id'    => 991000 + random_int(1, 9999),
        'product_id'       => $productId,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'delivery_channel' => 'agent',
        'granted_at'       => now()->subDays(400),
        'expires_at'       => now()->subDay(),
    ]);

    Artisan::call('ops:reclaim-expired-trials');

    expect($buyout->fresh()->status)->toBe(SkillEntitlement::STATUS_ACTIVE);
});

it('B-3 · dry-run 只统计、不写库', function () {
    $customer = b3Customer();
    $productId = b3Product(['agent']);
    $device = b3PairedDevice($customer);
    $e = b3ExpiredTrial($customer, $productId, $device, b3VersionId($productId));

    Artisan::call('ops:reclaim-expired-trials', ['--dry-run' => true]);

    expect($e->fresh()->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        ->and(DeployTask::query()->where('entitlement_id', $e->id)->count())->toBe(0);
});
