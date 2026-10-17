<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\AgentChannel;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;
use Webkul\Shop\Services\SkillDelivery\TrialService;

/**
 * S-15 · `delivery_channel` 语义错位的修复取证。
 *
 * 修复前:`TrialService` 把试用授权的 `delivery_channel` 写成 `agent`,但注册表里只有
 * `app` → `SkillDeliveryManager::channel('agent')` 抛 InvalidArgumentException,
 * 被各调用点的 try/catch 记成"交付失败"(正常的试用授权被误报)。
 *
 * 修复后取证:
 *   ① `agent` 通道可解析(`keys()` 含 agent,不再抛);
 *   ② 试用流程产出的授权 → `deliver()` 返回 ok(因为真的有一条下发任务);
 *   ③ **没有下发任务**的 agent 授权 → `deliver()` 必须 fail(不许假装成功);
 *      只有回收任务也不算交付;
 *   ④ `revoke()` 置 revoked,且幂等;
 *   ⑤ `label()` 显示「Agent 下发」,不回退成裸 key。
 */

function s15Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'S15',
        'last_name'   => '客户',
        'email'       => 's15'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 造一个技能商品(含一个 published 版本);$channels 决定它声明了哪些交付通道。 */
function s15Product(array $channels = ['agent', 'manual']): int
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 's15-sku-'.uniqid(),
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

/** 一台**已确权**设备(有 binding)—— 试用要真下发,未确权设备发不出去。 */
function s15PairedDevice(Customer $customer): Device
{
    $sn = 'S15-'.uniqid();

    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => $sn,
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);

    $binding = DeviceBinding::create([
        'did'               => 'did_s15_'.uniqid(),
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

/** 一条 `delivery_channel='agent'` 的授权(不附带任何下发任务)。 */
function s15AgentEntitlement(Customer $customer, int $productId, ?Device $device = null): SkillEntitlement
{
    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => null,
        'order_item_id'    => null,
        'product_id'       => $productId,
        'device_id'        => $device?->id,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'delivery_channel' => SkillAsset::DELIVERY_AGENT,
        'granted_at'       => now(),
    ]);
}

// ==================== ① 注册 ====================

it('S-15 · 注册表能解析 agent 通道，keys() 含 agent（不再抛）', function () {
    $manager = app(SkillDeliveryManager::class);

    expect($manager->keys())->toContain('agent')
        ->and($manager->channel('agent'))->toBeInstanceOf(AgentChannel::class)
        ->and($manager->channel('agent')->key())->toBe('agent');
});

// ==================== ② 试用授权 → deliver ok ====================

it('S-15 · 试用流程产出的授权 delivery_channel=agent，deliver() 返回 ok（有下发任务）', function () {
    $customer = s15Customer();
    $productId = s15Product(['agent', 'manual']);
    $device = s15PairedDevice($customer);

    $result = app(TrialService::class)->start($customer->id, $productId, $device->id);

    expect($result['ok'])->toBeTrue($result['message'] ?? '试用发起失败');

    $entitlement = $result['entitlement']->fresh();

    expect($entitlement->delivery_channel)->toBe(SkillAsset::DELIVERY_AGENT);

    $delivery = app(SkillDeliveryManager::class)
        ->channel($entitlement->delivery_channel)
        ->deliver($entitlement);

    expect($delivery->ok)->toBeTrue($delivery->message)
        ->and($delivery->data['task_no'])->toBe($result['task']->task_no)
        // 建任务后 dispatch() 会立刻推进状态(pending → dispatching / pending_online / failed),
        // 这里只断言它落在一个合法的开放态里,不锁死具体值
        ->and($delivery->data['status'])->toBeIn(['dispatching', 'pending_online', 'failed'])
        // 幂等且不改授权状态:deliver() 只读不写
        ->and($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_ACTIVE);
});

// ==================== ③ 没有下发任务 → fail ====================

it('S-15 · 没有下发任务的 agent 授权 → deliver() 返回 fail（不许假装成功）', function () {
    $customer = s15Customer();
    $productId = s15Product(['agent']);
    $entitlement = s15AgentEntitlement($customer, $productId);

    $delivery = app(SkillDeliveryManager::class)->channel('agent')->deliver($entitlement);

    expect($delivery->ok)->toBeFalse()
        ->and($delivery->message)->toContain('找不到下发任务')
        // 失败分支同样不改授权状态
        ->and($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        ->and($entitlement->fresh()->granted_at)->not->toBeNull();
});

it('S-15 · 只有回收任务的 agent 授权 → 仍判 fail（回收不是交付）', function () {
    $customer = s15Customer();
    $productId = s15Product(['agent']);
    $entitlement = s15AgentEntitlement($customer, $productId);

    DeployTask::create([
        'task_no'        => testUniqueNo('S15'),
        'did'            => 'did_s15_reclaim_'.uniqid(),
        'channel'        => 'agent',
        'skill_id'       => $productId,
        'skill_version_id' => null,
        'entitlement_id' => $entitlement->id,
        'operation'      => DeployTask::OP_RECLAIM,
        'status'         => 'pending',
        'created_by'     => 'system:test',
    ]);

    $delivery = app(SkillDeliveryManager::class)->channel('agent')->deliver($entitlement->fresh());

    expect($delivery->ok)->toBeFalse()
        ->and($delivery->message)->toContain('找不到下发任务');
});

// ==================== ④ revoke ====================

it('S-15 · revoke() 把授权置 revoked，且重复调用不炸', function () {
    $customer = s15Customer();
    $productId = s15Product(['agent']);
    $entitlement = s15AgentEntitlement($customer, $productId);

    $channel = app(SkillDeliveryManager::class)->channel('agent');

    $channel->revoke($entitlement);

    $revoked = $entitlement->fresh();

    expect($revoked->status)->toBe(SkillEntitlement::STATUS_REVOKED)
        ->and($revoked->revoked_at)->not->toBeNull();

    // 幂等:再调一次不炸,状态/撤销时间不变
    $channel->revoke($revoked);

    $again = $revoked->fresh();

    expect($again->status)->toBe(SkillEntitlement::STATUS_REVOKED)
        ->and($again->revoked_at->toDateTimeString())->toBe($revoked->revoked_at->toDateTimeString());

    // revoke 后 cannot deliver
    expect($channel->canDeliver($again))->toBeFalse();
});

// ==================== ⑤ label ====================

it('S-15 · label() 显示「Agent 下发」，不再回退成裸 key', function () {
    $manager = app(SkillDeliveryManager::class);

    expect($manager->label('agent'))->toBe('Agent 下发')
        ->and($manager->label('agent'))->not->toBe('agent');
});
