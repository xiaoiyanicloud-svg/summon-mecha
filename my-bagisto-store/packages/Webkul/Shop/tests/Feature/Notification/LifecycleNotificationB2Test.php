<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

/**
 * B-2 · 通知中心的生产者（四类业务事件）。
 *
 * 背景：通知中心读写端早就通了，但 `NotificationService::send()` 此前**只有
 * AdminAlertService 一个调用方**（发给 admin）→ `notification` 表 0 行 →
 * 用户的「消息通知」页永远空白。
 *
 * 本文件钉死四类用户侧事件的站内信生产者：
 *   审核结果（售后通过/驳回）、退款到账、下发完成、试用到期。
 * 另验一条边界：**找不到归属就不发**（不臆造收件人）。
 */

function b2Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Notify',
        'last_name'   => 'B2',
        'email'       => 'b2'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 客户 + 订单 + 条目 + 授权 + 售后单。返回 [afterSaleId, entitlementId]。 */
function b2AfterSaleScenario(float $total = 600.00, string $afterSaleStatus = AfterSale::STATUS_APPLIED): array
{
    $customer = b2Customer();

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'B2'.uniqid(), 'customer_id' => $customer->id,
        'status' => 'processing', 'grand_total' => $total, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $itemId = DB::table('order_items')->insertGetId([
        'order_id' => $orderId, 'product_id' => 920000 + random_int(1, 9999),
        'type' => 'simple', 'name' => '技能包', 'sku' => 'B2SKU'.uniqid(),
        'qty_ordered' => 1, 'price' => $total, 'total' => $total,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $entitlement = SkillEntitlement::create([
        'customer_id' => $customer->id, 'order_id' => $orderId, 'order_item_id' => $itemId,
        'product_id' => 920000 + random_int(1, 9999), 'status' => SkillEntitlement::STATUS_ACTIVE,
        'delivery_channel' => 'app', 'license_key' => SkillEntitlement::newLicenseKey(), 'granted_at' => now(),
    ]);

    $afterSale = AfterSale::create([
        'order_id' => $orderId, 'order_item_id' => $itemId, 'entitlement_id' => $entitlement->id,
        'customer_id' => $customer->id, 'type' => AfterSale::TYPE_REFUND,
        'policy_basis' => 'not_installed', 'status' => $afterSaleStatus,
    ]);

    return [$afterSale->id, $entitlement->id, $customer->id];
}

function b2Binding(?int $customerId = null): DeviceBinding
{
    return DeviceBinding::create([
        'did'               => 'did_b2_'.uniqid(),
        'sn'                => 'B2-'.uniqid(),
        'source'            => DeviceBinding::SOURCE_MANUAL,
        'owner_customer_id' => $customerId,
        'bind_status'       => DeviceBinding::BIND_STATUS_BOUND,
        'bound_at'          => now(),
        'last_online_at'    => now()->subHours(1), // 离线 → 建任务后停 pending_online，等 onEvent 推进
    ]);
}

function b2Notifications(int $customerId, string $type): int
{
    return Notification::query()
        ->where('recipient_type', 'customer')
        ->where('recipient_id', $customerId)
        ->where('type', $type)
        ->count();
}

it('B-2 · 售后批准 → 审核结果 + 退款到账两条站内信', function () {
    [$afterSaleId, , $customerId] = b2AfterSaleScenario(600.00);

    app(AfterSaleService::class)->approve($afterSaleId, 1);

    expect(b2Notifications($customerId, 'after_sale.approved'))->toBe(1)
        ->and(b2Notifications($customerId, 'refund.settled'))->toBe(1);

    // 站内信通道（不发 subscribe_msg —— 那是 A-3）
    expect(Notification::where('recipient_id', $customerId)->pluck('channel')->unique()->all())
        ->toBe([Notification::CHANNEL_INAPP]);
});

it('B-2 · 售后驳回 → 审核结果（驳回）一条站内信', function () {
    [$afterSaleId, , $customerId] = b2AfterSaleScenario(400.00, AfterSale::STATUS_REVIEWING);

    app(AfterSaleService::class)->reject($afterSaleId, 1, '已激活');

    expect(b2Notifications($customerId, 'after_sale.rejected'))->toBe(1)
        // 驳回不涉及打款 → 不该有"到账"通知
        ->and(b2Notifications($customerId, 'refund.settled'))->toBe(0);
});

it('B-2 · 下发完成（安装成功，非空跑）→ 给授权归属客户发站内信', function () {
    [$afterSaleId, $entitlementId, $customerId] = b2AfterSaleScenario();
    // 借这条授权当"某个客户的技能"（不关心售后单，只取归属）
    $binding = b2Binding($customerId);

    $service = app(DeployTaskService::class);

    $task = $service->create(
        did: $binding->did, skillId: 920000 + random_int(1, 9999), skillVersionId: null,
        entitlementId: $entitlementId, operation: DeployTask::OP_INSTALL, createdBy: 'test',
    );

    // 设备回报 done → success（非空跑）
    $service->onEvent($task->task_no, 'done');

    expect($task->fresh()->status)->toBe('success')
        ->and(b2Notifications($customerId, 'delivery.completed'))->toBe(1);
});

it('B-2 · 试用到期（回收任务完成）→ 给客户发站内信', function () {
    [, $entitlementId, $customerId] = b2AfterSaleScenario();
    $binding = b2Binding($customerId);

    $service = app(DeployTaskService::class);

    $task = $service->create(
        did: $binding->did, skillId: 920000 + random_int(1, 9999), skillVersionId: null,
        entitlementId: $entitlementId, operation: DeployTask::OP_RECLAIM, createdBy: 'test',
    );

    $service->onEvent($task->task_no, 'done');

    expect(b2Notifications($customerId, 'trial.expired'))->toBe(1);
});

it('B-2 · 找不到归属（未确权设备）→ 不臆造收件人，不发通知', function () {
    $binding = b2Binding(null); // owner 为空

    $service = app(DeployTaskService::class);

    $task = $service->create(
        did: $binding->did, skillId: 920000 + random_int(1, 9999), skillVersionId: null,
        entitlementId: null, operation: DeployTask::OP_INSTALL, createdBy: 'test',
    );

    $service->onEvent($task->task_no, 'done');

    expect($task->fresh()->status)->toBe('success')
        ->and(Notification::where('type', 'delivery.completed')->count())->toBe(0);
});
