<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;

/** 普通订单入口读取的最小场景上下文；调用方先验证订单所有权。 */
class SceneLinkedOrderService
{
    public function context(int $orderId): ?array
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (! $schema->hasTable('scene_order_links')) {
            return null;
        }
        $link = DB::table('scene_order_links')->where('order_id', $orderId)->first();
        if (! $link && $schema->hasTable('scene_order_parts')) {
            $part = DB::table('scene_order_parts')->where('order_id', $orderId)->first();
            $link = $part ? DB::table('scene_order_links')->where('quote_id', $part->quote_id)->first() : null;
        }
        if (! $link) {
            $marked = DB::table('order_items')->where('order_id', $orderId)->where('additional->scene->protocol', 'scene-sc3-v1')->exists();

            return $marked ? ['project_id' => null, 'state' => 'requires_review', 'payment_state' => 'unconfirmed', 'online_payment' => false] : null;
        }
        $p = SceneWorkflow::requireRow('scene_projects', (int) $link->project_id, false);
        $r = SceneWorkflow::requireRow('scene_requests', (int) $p->request_id, false);

        return ['project_id' => (int) $p->id, 'request_id' => (int) $p->request_id, 'quote_id' => (int) $p->quote_id,
            'scene_id' => $r->scene_id ? (int) $r->scene_id : null, 'state' => $p->state,
            'commercial_state' => $p->commercial_state, 'payment_state' => $p->payment_state, 'online_payment' => false];
    }
}
