<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

class SceneSkillAfterSaleService
{
    /** 必须是本客户、本项目技能子单、冻结版本对应的有效正式授权。 */
    public function assertEligible(ScenePrincipal $owner, object $project, ?int $itemId, ?int $entitlementId): array
    {
        $invalid = fn () => new SceneWorkflowException('SCENE_SKILL_ENTITLEMENT_INVALID', '未找到与本项目、当前客户及冻结版本一致的有效技能购买授权。', 422);
        if (! $owner->customerId || (! $itemId && ! $entitlementId)) {
            throw $invalid();
        }
        $query = DB::table('skill_entitlements')->where('customer_id', $owner->customerId);
        $ref = $entitlementId ? $query->where('id', $entitlementId)->first() : $query->where('order_item_id', $itemId)->first();
        if (! $ref || ! $ref->order_id || ! $ref->order_item_id || ($itemId && (int) $ref->order_item_id !== $itemId)) {
            throw $invalid();
        }
        // 与 D10 的退款恢复一致，先订单和明细，再锁授权，避免锁顺序反转。
        $order = DB::table('orders')->where('id', $ref->order_id)->lockForUpdate()->first();
        $item = DB::table('order_items')->where('id', $ref->order_item_id)->where('order_id', $ref->order_id)->lockForUpdate()->first();
        $entitlement = SkillEntitlement::query()->whereKey($ref->id)->where('customer_id', $owner->customerId)->lockForUpdate()->first();
        if (! $order || ! $item || ! $entitlement || (int) $order->customer_id !== $owner->customerId
            || (int) $entitlement->order_id !== (int) $order->id || (int) $entitlement->order_item_id !== (int) $item->id
            || $item->type !== 'downloadable' || (int) $item->product_id !== (int) $entitlement->product_id
            || ! $entitlement->isUsable() || ! in_array($entitlement->status, ['pending_bind', 'granted', 'active'], true)
            || ! DB::table('scene_order_parts')->where('quote_id', $project->quote_id)->where('order_id', $order->id)->where('kind', 'skill')->exists()) {
            throw $invalid();
        }
        $snapshot = SceneWorkflow::unpack($project->snapshot);
        $additional = SceneWorkflow::unpack($item->additional);
        $scene = $additional['scene'] ?? [];
        $version = DB::table('skill_versions')->where('id', $entitlement->skill_version_id)->where('product_id', $entitlement->product_id)->first();
        $component = collect($snapshot['proposal']['components'] ?? [])->first(fn ($c) => ($c['kind'] ?? null) === 'skill' && ($c['selected'] ?? false)
            && (int) ($c['product_id'] ?? 0) === (int) $entitlement->product_id && (int) ($c['skill_version_id'] ?? 0) === (int) $entitlement->skill_version_id);
        if (! $version || ! $component || ! DB::table('products')->where('id', $entitlement->product_id)->where('type', 'downloadable')->exists()
            || ($scene['protocol'] ?? null) !== 'scene-sc3-v1' || (int) ($scene['quote_id'] ?? 0) !== (int) $project->quote_id
            || (int) ($scene['request_id'] ?? 0) !== (int) $project->request_id
            || empty($snapshot['quote_hash']) || ! hash_equals($snapshot['quote_hash'], $scene['quote_hash'] ?? '')
            || (int) ($additional['skill']['version_id'] ?? 0) !== (int) $entitlement->skill_version_id
            || (! in_array($order->status, ['processing', 'completed'], true) && ! ($order->status === 'pending' && $project->payment_state === 'offline_verified'))) {
            throw $invalid();
        }
        $policy = AfterSaleService::policyFor($entitlement);
        if (! $policy['ok']) {
            throw new SceneWorkflowException('SCENE_SKILL_REFUND_POLICY', $policy['message'].'技术问题可通过普通服务售后联系人工支持。', 422);
        }

        return ['order_item_id' => (int) $item->id, 'entitlement_id' => (int) $entitlement->id];
    }
}
