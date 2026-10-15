<?php

namespace Webkul\Shop\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductProxy;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Ops\OperationLogger;
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
 *   1. **绝不能抛异常。** OrderRepository::createOrderIfNotThenRetry() 在 DB::commit()
 *      *之前* dispatch 本事件,而它的 catch (\Exception) 会回滚并**重试建单最多 2 次**
 *      (OrderRepository.php:83 vs :102)。监听器抛异常 → 用户看到重复建单尝试。
 *      所以两个入口方法都整体 try/catch + report()。
 *
 *   2. **必须幂等。** 上面那个重试会让本方法被调用多次。靠 skill_entitlements 上
 *      order_item_id 的唯一键兜底(firstOrCreate),以及 DeliveryChannel::deliver()
 *      自身的幂等。
 */
class SkillDelivery
{
    public function __construct(
        protected SkillDeliveryManager $manager,
        protected OperationLogger $oplog,
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

        foreach ($order->items as $item) {
            if (! in_array((int) $item->product_id, $skillProductIds, true)) {
                continue;
            }

            $this->grantForItem($order, $item);
        }
    }

    /**
     * 为一个技能条目建立授权并交付。幂等:重复调用不会产生第二行。
     */
    protected function grantForItem($order, $item): void
    {
        // 技能交易强制登录(已与业务确认)。真的拿到游客单就跳过并留痕,
        // 不要硬塞一个 customer_id=0 的脏授权。
        if (! $order->customer_id) {
            report(new \RuntimeException(
                "技能条目 [{$item->id}] 属于无 customer_id 的订单 [{$order->id}],已跳过授权。"
            ));

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
                'customer_id'       => $order->customer_id,
                'order_id'          => $order->id,
                'product_id'        => $item->product_id,
                'skill_version_id'  => $version?->id,
                'device_id'         => $deviceId,
                'seller_id'         => $product?->seller_id,
                'status'            => SkillEntitlement::STATUS_PENDING_BIND,
                'license_key'       => SkillEntitlement::newLicenseKey(),
                'delivery_channel'  => (string) config('skill_delivery.default'),
                'max_activations'   => $version?->max_activations,
                'expires_at'        => $version?->valid_days
                    ? now()->addDays($version->valid_days)
                    : null,
            ]
        );

        // 已存在说明这次是重试/重复触发 —— 仍然调一次 deliver()(它幂等),
        // 但不要重复生成 license_key。
        $created = $entitlement->wasRecentlyCreated;
        $meta = is_array($entitlement->meta) ? $entitlement->meta : [];

        try {
            $result = $this->manager
                ->channel($entitlement->delivery_channel)
                ->deliver($entitlement, ['device_id' => $deviceId]);

            // 交付结果留痕,方便排查"为什么这单没交付"
            $meta['delivery'] = $result->toArray();
            $meta['delivery_failed'] = null;
        } catch (\Throwable $e) {
            // D3 · 交付失败不能只留在日志里。授权行已经落库(order_item_id 唯一键),
            // 但状态停在未交付。把失败痕迹写进 meta,让"按订单补发"(redeliver)有据可查;
            // 再把异常抛回 afterOrderCreated 的 report() 触发告警。
            // 吞异常仍是刻意的(见类注释第 1 条:不能让建单失败),这里是"吞掉但留痕"。
            $meta['delivery_failed'] = [
                'at'      => now()->toDateTimeString(),
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ];

            $entitlement->meta = $meta;
            $entitlement->save();

            throw $e;
        }

        $entitlement->meta = $meta;
        $entitlement->save();

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
            ]
        );
    }

    /**
     * D3 · 按订单补发 —— 给"订单已成功但交付失败"的单子一个可执行入口。
     *
     * 依赖 grantForItem 的幂等性:已交付的条目不会重复,失败的条目会被补上。
     * 返回该订单当前拥有的授权数,便于调用方核对。
     */
    public function redeliver(int $orderId): int
    {
        $order = OrderProxy::modelClass()::find($orderId);

        if (! $order) {
            return 0;
        }

        $this->grantForOrder($order);

        return SkillEntitlement::where('order_id', $orderId)->count();
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
