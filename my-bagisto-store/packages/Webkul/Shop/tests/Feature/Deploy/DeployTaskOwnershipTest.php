<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeployTask;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * P3 · 用户侧 show / cancel 的越权（IDOR）。
 *
 * 背景：`DeployController::show()` 与 `cancel()` 此前**按 task_no 直查、不过归属**
 * （同控制器其它接口都走了 resolveOwnedBinding，就这两处漏了），而单号规则
 * `DT+ymdHis+4位随机` 每天只有约 9000 种组合 —— 可枚举，等于把别人的下发任务
 * 状态与取消权开放给任何登录用户。
 *
 * 修法：经 device_binding.owner_customer_id 反查归属，不匹配一律 404
 * （与"任务不存在"同码，避免用状态码差异做枚举信号）。
 */

function ownershipCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Own',
        'last_name'   => 'Test',
        'email'       => 'own'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function ownershipDevice(Customer $customer): DeviceBinding
{
    $sn = 'OWN-'.uniqid();

    return DeviceBinding::create([
        'did'               => 'sn_'.$sn,
        'sn'                => $sn,
        'source'            => DeviceBinding::SOURCE_MANUAL,
        'owner_customer_id' => $customer->id,
        'bind_status'       => 'bound',
        'bound_at'          => now(),
    ]);
}

function ownershipTask(DeviceBinding $binding, string $status = 'pending'): DeployTask
{
    return DeployTask::create([
        'task_no'    => testUniqueNo('DT'),
        'did'        => $binding->did,
        'channel'    => 'agent',
        'skill_id'   => 900000 + random_int(1, 9999),
        'operation'  => DeployTask::OP_INSTALL,
        'status'     => $status,
        'created_by' => 'test',
    ]);
}

it('show：看不到别人的 task_no（404，与"不存在"同码）', function () {
    $me = ownershipCustomer();
    $other = ownershipCustomer();

    $task = ownershipTask(ownershipDevice($other));

    actingAs($me, 'customer');

    getJson(route('shop.api.deploy.show', $task->task_no))->assertStatus(404);
});

it('cancel：取消不了别人的任务（404，且对方任务未被改动）', function () {
    $me = ownershipCustomer();
    $other = ownershipCustomer();

    $task = ownershipTask(ownershipDevice($other));

    actingAs($me, 'customer');

    postJson(route('shop.api.deploy.cancel', $task->task_no))->assertStatus(404);

    expect($task->fresh()->status)->toBe('pending');
});

it('本人任务：show / cancel 正常（归属校验不误伤）', function () {
    $me = ownershipCustomer();
    $binding = ownershipDevice($me);
    $task = ownershipTask($binding);

    actingAs($me, 'customer');

    getJson(route('shop.api.deploy.show', $task->task_no))
        ->assertOk()
        ->assertJsonPath('data.task_no', $task->task_no);

    postJson(route('shop.api.deploy.cancel', $task->task_no))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($task->fresh()->status)->toBe('cancelled');
});
