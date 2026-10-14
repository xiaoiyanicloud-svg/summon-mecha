<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\SkillBlockedException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * P1 · 熔断按钮按下去要真的下闸（D9 kill switch 的硬缺口）。
 *
 * 背景：`KillSwitchService::isBlocked()` 此前**全仓零调用** —— 熔断记录能写进
 * `skill_blocklist`，但 `DeployTaskService::create()` 从不查它，新下发照建不误。
 * 也就是说「一键禁用」只是往库里写一行，没有任何实际闸门。
 *
 * 本文件锁两件事：
 *   1. 命中熔断 → 建任务被拒（HTTP 409，且库里不落任何 deploy_task）；
 *   2. 补救动作（卸载 / 回滚 / 回收）**不被**熔断挡住 —— 否则熔断后无法批量卸载。
 */

function ksCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'KS',
        'last_name'   => 'Test',
        'email'       => 'ks'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 造一台属于该用户的"已确权"设备（devices + binding 双向对上）。 */
function ksOwnedDevice(Customer $customer): DeviceBinding
{
    $sn = 'KS-'.uniqid();

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

it('熔断生效后：POST /api/deploy/tasks 被拒（409），且不落任何任务', function () {
    $customer = ksCustomer();
    $binding = ksOwnedDevice($customer);

    $skillId = 900000 + random_int(1, 9999);

    SkillBlocklist::create([
        'skill_id' => $skillId,
        'reason'   => 'pest:killswitch',
        'severity' => 'high',
        'action'   => SkillBlocklist::ACTION_BLOCK_ALL,
        'status'   => SkillBlocklist::STATUS_ACTIVE,
        'created_by' => 'pest',
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.deploy.store'), [
        'device_id' => $binding->devices_id,
        'skill_id'  => $skillId,
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'SKILL_BLOCKED');

    // 闸门在插库之前 —— 库里绝不能有一条 pending 的脏任务
    expect(DeployTask::where('skill_id', $skillId)->exists())->toBeFalse();
});

it('未熔断时：同样的请求正常建任务（闸门不误伤）', function () {
    $customer = ksCustomer();
    $binding = ksOwnedDevice($customer);

    $skillId = 900000 + random_int(1, 9999);

    actingAs($customer, 'customer');

    postJson(route('shop.api.deploy.store'), [
        'device_id' => $binding->devices_id,
        'skill_id'  => $skillId,
    ])->assertStatus(201);

    expect(DeployTask::where('skill_id', $skillId)->exists())->toBeTrue();
});

it('熔断只拦新下发：卸载 / 回滚 / 回收任务仍可建（补救动作放行）', function () {
    $skillId = 900000 + random_int(1, 9999);

    SkillBlocklist::create([
        'skill_id' => $skillId,
        'reason'   => 'pest:killswitch',
        'severity' => 'high',
        'action'   => SkillBlocklist::ACTION_FORCE_UNINSTALL,
        'status'   => SkillBlocklist::STATUS_ACTIVE,
        'created_by' => 'pest',
    ]);

    $service = app(DeployTaskService::class);

    // 安装：被拒
    expect(fn () => $service->create(
        did: 'sn_remediation_'.uniqid(),
        skillId: $skillId,
        skillVersionId: null,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
    ))->toThrow(SkillBlockedException::class);

    // 卸载（熔断的补救动作）：放行
    $task = $service->create(
        did: 'sn_remediation_'.uniqid(),
        skillId: $skillId,
        skillVersionId: null,
        entitlementId: null,
        operation: DeployTask::OP_UNINSTALL,
        createdBy: 'killswitch',
    );

    expect($task->skill_id)->toBe($skillId);
});
