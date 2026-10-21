<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;

class SceneSkillOrderGate
{
    public function allows(object $order): bool
    {
        $part = DB::table('scene_order_parts')->where('order_id', $order->id)->lockForUpdate()->first();
        $link = $part ? DB::table('scene_order_links')->where('quote_id', $part->quote_id)->lockForUpdate()->first() : null;
        if (! $link) {
            return false;
        }
        $p = SceneWorkflow::requireRow('scene_projects', (int) $link->project_id);
        $q = SceneWorkflow::requireRow('scene_quotes', (int) $p->quote_id);
        $request = SceneWorkflow::requireRow('scene_requests', (int) $p->request_id);
        if ($p->commercial_state !== 'confirmed' || $p->open_dispute || $q->state !== 'accepted' || (int) $request->customer_id !== (int) $order->customer_id
            || in_array($p->state, ['cancelled', 'paused', 'closed'], true)) {
            return false;
        }
        $records = DB::table('scene_commercial_records')->where('project_id', $p->id)->whereNotNull('reviewed_at')->lockForUpdate()->get();
        if ($records->contains(fn ($r) => in_array($r->kind, ['refund_due', 'refund_receipt'], true))) {
            return false;
        }
        $initial = DB::table('scene_quote_commercial')->where('quote_id', $q->id)->where('state', 'approved')->where('kind', 'offline_receipt')->lockForUpdate()->get();
        $paid = (int) $initial->sum('amount_minor') + (int) $records->where('kind', 'payment_receipt')->sum('amount_minor');
        $due = (int) $q->total_minor + (int) $q->deposit_minor;
        if ($due > 0 && ($p->payment_state !== 'offline_verified' || $paid !== $due)) {
            return false;
        }
        foreach ($order->items as $item) {
            $s = $item->additional['scene'] ?? [];
            if (($s['protocol'] ?? null) !== 'scene-sc3-v1' || ($s['quote_id'] ?? null) !== (int) $q->id || ! hash_equals($q->content_hash, $s['quote_hash'] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
