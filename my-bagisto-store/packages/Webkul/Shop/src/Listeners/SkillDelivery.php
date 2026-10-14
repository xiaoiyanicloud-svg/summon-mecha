<?php

namespace Webkul\Shop\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductProxy;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
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
    public function __construct(protected SkillDeliveryManager $manager) {}

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
        $result = $this->manager
            ->channel($entitlement->delivery_channel)
            ->deliver($entitlement, ['device_id' => $deviceId]);

        // 交付结果留痕,方便排查"为什么这单没交付"
        $meta = is_array($entitlement->meta) ? $entitlement->meta : [];
        $meta['delivery'] = $result->toArray();
        $entitlement->meta = $meta;
        $entitlement->save();
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
            return [];
        }

        return DB::table('product_categories')
            ->where('category_id', $categoryId)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
