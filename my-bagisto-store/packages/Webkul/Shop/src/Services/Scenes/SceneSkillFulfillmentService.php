<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\SkillEntitlement;

class SceneSkillFulfillmentService
{
    /** 转换已提交后的独立投递，既有技能授权/失败补偿继续作为事实源。 */
    public function fulfill(int $projectId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('技能授权须由转换提交后的独立投递事务管理。');
        }
        $p = SceneWorkflow::requireRow('scene_projects', $projectId);
        $part = DB::table('scene_order_parts')->where('quote_id', $p->quote_id)->first();
        if (! $part) {
            return;
        }
        $order = Order::with('items')->findOrFail($part->order_id);
        if (! app(SceneSkillOrderGate::class)->allows($order)) {
            DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'skill-funds:'.$projectId, 'request_id' => $p->request_id,
                'kind' => 'skill_payment_pending', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return;
        }
        if (in_array($order->status, ['canceled', 'closed', 'fraud'], true)) {
            return;
        }
        app(\Webkul\Shop\Listeners\SkillDelivery::class)->afterOrderCreated($order);
        foreach ($order->items as $item) {
            if (! SkillEntitlement::where('order_item_id', $item->id)->where('customer_id', $order->customer_id)
                ->where('skill_version_id', $item->additional['skill']['version_id'] ?? 0)->exists()) {
                throw new SceneWorkflowException('SCENE_SKILL_FULFILLMENT_PENDING', '技能授权尚未建立，请按既有技能失败补偿及熔断规则处理。', 409);
            }
        }
        DB::table('scene_operation_todos')->where('dedupe_key', 'skill-funds:'.$projectId)->update(['state' => 'resolved', 'resolution_code' => 'payment_verified', 'updated_at' => now('UTC')]);
    }
}
