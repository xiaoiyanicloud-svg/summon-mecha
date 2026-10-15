<?php

namespace Webkul\Shop\Services\SkillAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\LifecycleNotifier;

/**
 * 版本发布的**唯一入口** —— 2026-10-16 收敛。
 *
 * 为什么必须唯一：发布有两条通道 ——
 *   ① 运营后台 `Admin\Catalog\SkillController::publish()`
 *   ② internal.key 的 API `API\SkillAdminController::publish()`
 * 两者原先各写一份逻辑，结果**只有后台那份带发布闸门**：没过审的技能可以从 API 通道
 * **静默上架**。而 `Admin\Catalog\SkillController` 的类注释当时写着"两者写的是同一批表和
 * 同一套校验，不存在两套真相" —— 那句话在闸门这件事上是不成立的。文档里的断言不等于代码里的断言。
 *
 * 本类**不提供任何"跳过闸门"的开关**：想发布，就把审核做掉。
 * 新增发布通道时必须调本类，不得复制这段逻辑。
 */
class SkillPublisher
{
    /** 发布成功 */
    public const OK = 'PUBLISHED';

    public const ERR_VERSION_ARCHIVED = 'VERSION_ARCHIVED';
    public const ERR_VERSION_HAS_NO_ASSET = 'VERSION_HAS_NO_ASSET';
    public const ERR_GATE_NOT_PASSED = 'GATE_NOT_PASSED';

    public function __construct(
        protected ReviewGate $gate,
        protected SkillVersionState $states,
        protected ProductRepository $products,
        protected LifecycleNotifier $notifier,
    ) {}

    /**
     * 发布一个版本。
     *
     * 顺序刻意如此：**先闸门，再改状态**。反过来的话，一旦闸门没过，
     * 库里就已经留下一个"看起来发布过"的痕迹。
     *
     * @return array{ok: bool, code: string, message: string, version: SkillVersion, gate: array}
     */
    public function publish(SkillVersion $version): array
    {
        if ($version->status === SkillVersion::STATUS_ARCHIVED) {
            return $this->result(false, self::ERR_VERSION_ARCHIVED, '已归档的版本不能发布。', $version);
        }

        $version->loadMissing('assets');

        if ($version->assets->isEmpty()) {
            return $this->result(false, self::ERR_VERSION_HAS_NO_ASSET, '该版本还没有任何交付物,不能发布。', $version);
        }

        // 发布闸门 —— 未过审一律不放行（平台自营的技能也一样）
        $gate = $this->gate->evaluate($version->product_id, $version->id);

        if (! $gate['ok']) {
            return $this->result(
                false,
                self::ERR_GATE_NOT_PASSED,
                '未通过发布闸门：'.$gate['message'],
                $version,
                $gate
            );
        }

        DB::transaction(function () use ($version) {
            // 归档同商品其它 published 版本 —— 保留**批量** update，刻意不逐条走 transition()：
            // 这是一次性收敛（"每个商品同时只留一个 published"），逐条 load→transition 会
            // 退化成 N 次写库，而它们的迁移规则完全一致（published → archived），无需逐条判定。
            // 它绕过唯一入口，但触发点唯一（只有 SkillPublisher）且值域封闭（是白名单里已有的迁移），
            // 属受控例外；真要统一时，改成先批量取 id 再逐个 transition 即可。
            SkillVersion::query()
                ->where('product_id', $version->product_id)
                ->where('id', '!=', $version->id)
                ->where('status', SkillVersion::STATUS_PUBLISHED)
                ->update(['status' => SkillVersion::STATUS_ARCHIVED]);

            // ⚠ 顺序硬要求：**先闸门、后改状态**。反过来会让"draft + 无审核记录"返回
            //   TRANSITION_INVALID 而不是 GATE_NOT_PASSED，语义就错了（闸门在上面的 gate 块里判）。
            // 状态写入收敛到 SkillVersionState（唯一入口）；先设 released_at，让 transition
            // 的那一次 save() 把 status 与 released_at 一并落库（一次写）。
            $version->released_at = now();

            $transition = $this->states->transition($version, SkillVersion::STATUS_PUBLISHED);

            if (! $transition['ok']) {
                // 不可达：能到这里说明闸门已过，版本必然已被 submitLayer0 落到 in_review。
                // 真出现了就是状态机漏了路径 —— 宁可炸（回滚整个事务）也不谎报"已发布"。
                throw new \RuntimeException(
                    "发布状态迁移失败：版本 #{$version->id} 未能迁到 published"
                    ."（{$transition['code']} {$transition['message']}）"
                );
            }

            // 版本 published ≠ 用户看得见。技能市场搜的是 `product_flat` 里
            // `status=1 && visible_individually=1` 的商品行（见 SkillCatalogController::search）；
            // 而技能商品建出来就是 `status=0`（开发者不能自己上架）。所以**发布必须把商品一并置为可售**，
            // 否则"发布"是个空动作：版本是 published，市场却搜不到。
            //
            // 顺序硬要求：**先版本迁移、再置商品可售**（都在同一事务里，任一步失败整体回滚，
            // 绝不留下"版本 published 但商品禁用"的半状态）。
            $this->makeProductSellable($version);
        });

        // 通知开发者（任务卡 D-4）——**放在事务之外**：事务提交后才是"真的上架了"这个事实，
        // 放事务里会发出"通知已发、事务却回滚"的假消息。通知是副作用，LifecycleNotifier
        // 内部 safe() 吞异常，发不出去也不该让已经成功的发布变成失败。
        $this->notifier->skillPublished((int) $version->product_id, (int) $version->id, (string) $version->version);

        return $this->result(true, self::OK, "版本 {$version->version} 已发布。", $version->fresh('assets'), $gate);
    }

    /**
     * 把该版本所属的商品置为**可售**（status=1 + visible_individually=1）。
     *
     * 走 Bagisto 既有的商品更新通道（`ProductRepository::update()`）+ 事件三步，
     * **不直写 `product_flat`** —— flat 是派生表，绕过事件会留下不一致；
     * 写法与 `DeveloperSkillService::createSkill()/updateSkill()` 一致。
     *
     * 必须在**版本迁移成功之后**调用（顺序见 publish）。商品不存在时抛异常、回滚整个发布事务，
     * 绝不留下一个"版本已发布、市场里却没有这个商品"的假发布。
     */
    protected function makeProductSellable(SkillVersion $version): void
    {
        $productId = (int) $version->product_id;
        $product = $this->products->find($productId);

        if (! $product) {
            // 现实中版本必挂在商品下；指到不存在的商品 = 数据被破坏。
            throw new \RuntimeException(
                "发布失败：版本 #{$version->id} 指向的商品 #{$productId} 不存在，无法置为可售。"
            );
        }

        // ⚠ 必须带上商品**当前**的分类：`AbstractType::update` 在 `categories` 缺失时会把商品
        //   从所有分类里 sync 掉（见 DeveloperSkillService::updateSkill 的注释）。技能一旦脱离
        //   「技能」分类，市场搜索（按该分类过滤，见 SkillCatalogController::search）就再也搜不到
        //   它 —— 那正是本次要修的病，不能在修它的时候又把它踩回去。
        $categories = $product->categories->pluck('id')->all();

        // 事件三步一步都不能省：`product_flat` 是 `catalog.product.update.after` 里
        // flatIndexer 刷出来的，不派发就只改了 EAV，搜索读的 flat 仍是旧值（status=0）。
        Event::dispatch('catalog.product.update.before', $productId);

        $this->products->update([
            'status'               => 1,
            'visible_individually' => 1,
            'channel'              => core()->getDefaultChannelCode(),
            'locale'               => (string) (optional(core()->getDefaultChannel()->default_locale)->code
                ?: core()->getDefaultChannelCode()),
            'categories'           => $categories,
        ], $productId);

        Event::dispatch('catalog.product.update.after', $product->fresh());
    }

    /**
     * @return array{ok: bool, code: string, message: string, version: SkillVersion, gate: array}
     */
    protected function result(bool $ok, string $code, string $message, SkillVersion $version, ?array $gate = null): array
    {
        return [
            'ok'      => $ok,
            'code'    => $code,
            'message' => $message,
            'version' => $version,
            'gate'    => $gate ?? ['ok' => $ok, 'layers' => [], 'unmet' => [], 'message' => ''],
        ];
    }
}
