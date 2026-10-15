<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\DeployTaskService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/**
 * B-4 · 设备解绑必须落到**真相源** `device_binding`（D1）。
 *
 * 缺口的原貌：`DeviceController::destroy()` 只置 `devices.bind_status=revoked`
 * （登记视图），**从不改 `device_binding.bind_status`**。而下发链路只认 binding
 * （`DeployTaskService::dispatch()`）→ "界面已解绑、平台仍认为设备已确权"。
 *
 * 本文件钉死两件事：
 *   1. 解绑后 `device_binding` 同步为 `unbound`（并记 `unbound_at`）；
 *   2. 下发链路按 `bound` 判定 —— 解绑后的设备建任务直接 `NOT_BOUND`。
 *
 * ⚠ 边界：解绑**不回收授权**（`skill_entitlements` 仍指原设备，回收走订单取消那条路）。
 *   本文件不触碰该边界，只验证"真相源同步 + 下发认它"。
 */

function b4Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Unbind',
        'last_name'   => 'B4',
        'email'       => 'b4'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 用真实登记接口造一台"已确权"的设备，返回 [deviceId, binding]。 */
function b4RegisterBoundDevice(Customer $customer, string $sn): array
{
    $res = postJson(route('shop.api.devices.store'), ['sn' => $sn])->assertStatus(201);

    $deviceId = $res->json('data.id');
    $binding = DeviceBinding::where('devices_id', $deviceId)->firstOrFail();

    expect($binding->bind_status)->toBe(DeviceBinding::BIND_STATUS_BOUND);

    return [$deviceId, $binding];
}

it('B-4 · 解绑同步真相源：device_binding 置 unbound 并记解绑时间', function () {
    $customer = b4Customer();
    actingAs($customer, 'customer');

    [$deviceId, $binding] = b4RegisterBoundDevice($customer, 'B4-'.uniqid());

    deleteJson(route('shop.api.devices.destroy', $deviceId))->assertOk();

    $binding->refresh();

    // ① 真相源被同步
    expect($binding->bind_status)->toBe(DeviceBinding::BIND_STATUS_UNBOUND)
        ->and($binding->unbound_at)->not->toBeNull();

    // ② 登记视图的老行为照旧（regression：不能为了改真相源而把 devices 那句丢了）
    expect(Device::find($deviceId)->bind_status)->toBe(Device::STATUS_REVOKED);

    // ③ 归属保留（谁曾经绑过这台机器，是追溯凭据，解绑不该抹掉）
    expect((int) $binding->owner_customer_id)->toBe($customer->id);
});

it('B-4 · 下发链路认 binding：解绑后的设备建任务直接 NOT_BOUND', function () {
    $customer = b4Customer();
    actingAs($customer, 'customer');

    [$deviceId, $binding] = b4RegisterBoundDevice($customer, 'B4D-'.uniqid());

    // 解绑前：设备在线与否都无所谓，链路只放行"未解绑"的设备
    deleteJson(route('shop.api.devices.destroy', $deviceId))->assertOk();

    $task = app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: 930000 + random_int(1, 9999),
        skillVersionId: null,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
        createdBy: 'test',
    );

    expect($task->status)->toBe('failed')
        ->and($task->error_code)->toBe(DeployTask::ERR_NOT_BOUND);
});

it('B-4 · 解绑幂等：重复解绑不报错，状态仍是 unbound', function () {
    $customer = b4Customer();
    actingAs($customer, 'customer');

    [$deviceId, $binding] = b4RegisterBoundDevice($customer, 'B4I-'.uniqid());

    deleteJson(route('shop.api.devices.destroy', $deviceId))->assertOk();
    deleteJson(route('shop.api.devices.destroy', $deviceId))->assertOk();

    expect($binding->fresh()->bind_status)->toBe(DeviceBinding::BIND_STATUS_UNBOUND);
});
