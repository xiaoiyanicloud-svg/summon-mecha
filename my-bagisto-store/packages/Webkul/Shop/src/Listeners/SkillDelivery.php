<?php

namespace Webkul\Shop\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductProxy;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\Ops\AdminAlertService;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryCompensator;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;

/**
 * 技能交付监听器 —— 订单落库后自动产生技能授权。
 *
 * 为什么挂在事件上而不是写在 TobOrderController 里:
 *   后台手工建单、将来其它下单源(比如方案打包)就都能覆盖,不用每处都记得调一次。
 *   写法与团队既有套路一致(EventServiceProvider 里已有一堆同类监听)。
 *
 * ⚠⚠ 这个监听器跑在**建单事务内部**,有两个必须守住的边界:
 *
 *   1. **保持既有失败补偿语义。** 本事件仍在建单事务提交前派发；仓储已改用
 *      Laravel 事务，普通异常/Throwable 直接回滚并传播，仅顶层数据库并发错误
 *      最多重试三次。本监听仍按既有约定整体 try/catch + report()，由补偿流程
 *      处理失败，不把它视为已成功交付。
 *
 *   2. **必须幂等。** 数据库并发重试或补偿可能多次调用。靠 skill_entitlements 上
 *      order_item_id 的唯一键兜底(firstOrCreate),以及 DeliveryChannel::deliver()
 *      自身的幂等。
 */
class SkillDelivery
{
    public function __construct(
        protected SkillDeliveryManager $manager,
        protected OperationLogger $oplog,
        protected SkillDeliveryCompensator $compensator,
    ) {}

    /**
     * 订单创建后:为其中的技能条目生成授权并交付。
     *
     * @param  \Webkul\Sales\Contracts\Order  $order
     */
    public function afterOrderCreated($order): void
    {
        try {
            $this->grantForOrder($order);
        } catch (\Throwable $e) {
            // 见类注释第 1 条:这里吞掉异常是刻意的,不是偷懒
            report($e);
        }
    }

    /**
     * 订单取消后:撤销已产生的授权(对称操作)。
     *
     * @param  \Webkul\Sales\Contracts\Order  $order
     */
    public function afterOrderCanceled($order): void
    {
        try {
            foreach (SkillEntitlement::where('order_id', $order->id)->get() as $entitlement) {
                $this->manager->channel($entitlement->delivery_channel)->revoke($entitlement);

                // M8 · 撤销留痕(与发放对称)。
                $this->oplog->log(
                    OperationLog::ACTOR_SYSTEM,
                    null,
                    'entitlement.revoke',
                    'skill_entitlement',
                    $entitlement->id,
                    ['order_id' => $order->id, 'reason' => 'order_canceled']
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 找出订单里的技能条目并逐条建立授权。
     */
    protected function grantForOrder($order): void
    {
        $skillProductIds = $this->skillProductIds();

        if (empty($skillProductIds)) {
            return;
        }

        // A parent order transaction can retain more than one product lock.
        // Consistent product order prevents opposite multi-item lock ordering.
        foreach ($order->items->sortBy('product_id') as $item) {
            if (! in_array((int) $item->product_id, $skillProductIds, true)) {
                continue;
            }

            try {
                $this->grantForItem($order, $item);
            } catch (\Throwable $e) {
                report($e);
                $this->alertFailure((int) $order->id, (int) $item->id, 'ENTITLEMENT_CREATION_FAILED');
            }
        }
    }

    /**
     * 为一个技能条目建立授权并交付。幂等:重复调用不会产生第二行。
     */
    protected function grantForItem($order, $item): void
    {
        DB::transaction(function () use ($order, $item) {
            $lockedOrder = OrderProxy::modelClass()::whereKey($order->id)->lockForUpdate()->first();
            if (! $lockedOrder || in_array($lockedOrder->status, ['canceled', 'closed', 'fraud'], true)) {
                return;
            }
            $lockedItem = $lockedOrder->items()->whereKey($item->id)->lockForUpdate()->first();
            if (! $lockedItem) {
                return;
            }
            // Shares a real product-row mutex with kill-switch engage/lift.
            $product = DB::table('products')->where('id', $lockedItem->product_id)->lockForUpdate()->first();
            if (! $product) {
                $this->alertFailure((int) $order->id, (int) $item->id, 'PRODUCT_MISSING');

                return;
            }
            $this->grantForItemLocked($lockedOrder, $lockedItem);
        });
    }

    protected function grantForItemLocked($order, $item): void
    {
        // 技能交易强制登录(已与业务确认)。真的拿到游客单就跳过并留痕,
        // 不要硬塞一个 customer_id=0 的脏授权。
        if (! $order->customer_id) {
            report(new \RuntimeException(
                "技能条目 [{$item->id}] 属于无 customer_id 的订单 [{$order->id}],已跳过授权。"
            ));

            return;
        }

        // S-8 · fail-closed 兜底:该技能若已被熔断,就**不建授权**。
        //
        //   为什么在这里还要判一次:下单入口(TobOrderController)已在下单口拦了一道,但
        //   **下单源可能不止一条**(后台手工建单、将来其它下单源)—— 那些路径绕过那个闸。
        //   授权是"谁拥有什么技能"的唯一事实源(见 SkillEntitlement 类注释),在它落库**之前**
        //   再判一次,才能保证"已熔断技能不产生新授权"这条不变式。
        //
        //   ⚠ 这里**不能抛异常**(与 customer_id 缺失那条一样):本监听器跑在建单事务内,
        //     抛异常会触发 OrderRepository 的建单重试(见类注释 #1)。所以"拒绝"的表现形式是:
        //     跳过发放 + 明确留痕(report 触发告警 + operation_log),而不是只静默 return。
        if (app(KillSwitchService::class)->isBlockedFor((int) $item->product_id, 'sale')) {
            report(new \RuntimeException(
                "技能条目 [{$item->id}] 属于已熔断技能 [{$item->product_id}],已拒绝发放授权(fail-closed)。"
            ));

            $this->oplog->log(
                OperationLog::ACTOR_SYSTEM,
                null,
                'entitlement.grant_blocked',
                'skill',
                (int) $item->product_id,
                [
                    'order_id'      => $order->id,
                    'order_item_id' => $item->id,
                    'product_id'    => $item->product_id,
                    'customer_id'   => $order->customer_id,
                    'reason'        => 'skill_blocked',
                ]
            );

            return;
        }

        $additional = is_array($item->additional) ? $item->additional : [];
        $deviceId = $additional['skill']['device_id'] ?? null;

        // 下单时锁定当前已发布的版本(没有发布版本则为 NULL = 跟随最新)
        $version = SkillVersion::query()
            ->where('product_id', $item->product_id)
            ->where('status', SkillVersion::STATUS_PUBLISHED)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();

        $product = ProductProxy::modelClass()::find($item->product_id);

        $entitlement = SkillEntitlement::firstOrCreate(
            ['order_item_id' => $item->id],
            [
                'customer_id'      => $order->customer_id,
                'order_id'         => $order->id,
                'product_id'       => $item->product_id,
                'skill_version_id' => $version?->id,
                'device_id'        => $deviceId,
                'seller_id'        => $product?->seller_id,
                'status'           => SkillEntitlement::STATUS_PENDING_BIND,
                'license_key'      => SkillEntitlement::newLicenseKey(),
                'delivery_channel' => (string) config('skill_delivery.default'),
                'max_activations'  => $version?->max_activations,
                'expires_at'       => $version?->valid_days
                    ? now()->addDays($version->valid_days)
                    : null,
            ]
        );

        $created = $entitlement->wasRecentlyCreated;
        $entitlement = SkillEntitlement::whereKey($entitlement->id)->lockForUpdate()->firstOrFail();
        $meta = (array) $entitlement->meta;

        // Payment/event replay is not another initial attempt. Existing device,
        // version, license and retry budget remain authoritative.
        if ($entitlement->granted_at || $entitlement->status === SkillEntitlement::STATUS_ACTIVE
            || ($meta['delivery']['ok'] ?? null) === true
            || $this->compensator->hasFailureState($entitlement)) {
            return;
        }

        try {
            $result = $this->manager->channel($entitlement->delivery_channel)
                ->deliver($entitlement, ['device_id' => $entitlement->device_id]);
            $entitlement = SkillEntitlement::whereKey($entitlement->id)->lockForUpdate()->firstOrFail();
            $meta = (array) $entitlement->meta;
            $meta['delivery'] = $result->toArray();
            if ($result->ok) {
                $meta['delivery_failed'] = null;
            } else {
                // Business rejection is observable, but never a technical retry.
                $meta['delivery_failed'] ??= [
                    'at'        => now()->toDateTimeString(),
                    'code'      => $result->code,
                    'message'   => $result->message,
                    'retryable' => false,
                ];
                $this->alertFailure((int) $order->id, (int) $item->id, $result->code);
            }
            $entitlement->meta = $meta;
            $entitlement->save();
        } catch (\Throwable $e) {
            $this->compensator->recordFailure((int) $entitlement->id, $e);
            report($e);
            $this->alertFailure((int) $order->id, (int) $item->id, 'DELIVERY_EXCEPTION');

            return;
        }

        // M8 · 授权发放留痕。log()(非 logOrFail):本方法跑在建单事务内,
        // 审计是旁路,绝不能因审计写不进去而拖垮建单(见类注释第 1 条)。
        $this->oplog->log(
            OperationLog::ACTOR_SYSTEM,
            null,
            'entitlement.grant',
            'skill_entitlement',
            $entitlement->id,
            [
                'order_id'         => $order->id,
                'order_item_id'    => $item->id,
                'product_id'       => $item->product_id,
                'customer_id'      => $order->customer_id,
                'delivery_channel' => $entitlement->delivery_channel,
                'created'          => $created,
                'delivery_ok'      => $result->ok,
                'delivery_code'    => $result->code,
            ]
        );
    }

    /**
     * @deprecated Inventory-only compatibility method. Recovery must use the
     * guarded skills:redeliver command, never replay initial order delivery.
     */
    public function redeliver(int $orderId): int
    {
        return SkillEntitlement::where('order_id', $orderId)->count();
    }

    protected function alertFailure(int $orderId, int $itemId, string $reason): void
    {
        try {
            app(AdminAlertService::class)->alert(
                'skill_delivery_attention',
                '技能交付需核查',
                "订单 {$orderId}，条目 {$itemId}，原因 {$reason}。请先运行 skills:redeliver {$orderId} 检查。",
                "skill-delivery:{$orderId}:{$itemId}:{$reason}",
                ['order_id' => $orderId, 'order_item_id' => $itemId, 'reason_code' => $reason],
                AdminAlertService::LEVEL_WARN,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * skill 分类下的全部商品 id。
     *
     * 用分类而不是 order_type 判断:后台手工建的技能单不会有 order_type,
     * 按分类判断更稳(与 CatalogController 的取分类方式一致)。
     *
     * @return array<int, int>
     */
    protected function skillProductIds(): array
    {
        $categoryId = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'skill'))
            ->value('id');

        if (! $categoryId) {
            // D6:分类 slug='skill' 查不到 = 配置漂移,不是正常状态 —— 会导致**全站停止发授权**。
            // 此前静默 return [],订单照常成功但一张授权都不落,没有任何痕迹。
            \Illuminate\Support\Facades\Log::warning(
                'SkillDelivery: 技能分类 slug=\'skill\' 不存在,授权发放已停止(配置漂移)。'
            );

            $this->alertFailure(0, 0, 'SKILL_CATEGORY_MISSING');

            return [];
        }

        $productIds = DB::table('product_categories')
            ->where('category_id', $categoryId)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($productIds)) {
            \Illuminate\Support\Facades\Log::warning(
                "SkillDelivery: 技能分类(id={$categoryId})下没有任何商品,授权发放将不会命中。"
            );
        }

        return $productIds;
    }
}
