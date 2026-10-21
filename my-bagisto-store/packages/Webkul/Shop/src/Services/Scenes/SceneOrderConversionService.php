<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** request → quote → 资源父行 → 预留 → 订单；唯一约束及父锁保护跨幂等键转换。 */
class SceneOrderConversionService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneProposalService $proposals,
        protected SceneCommercialService $commercial, protected SceneReservationService $reservations, protected SceneOrderWriter $writer) {}

    public function convert(ScenePrincipal $owner, int $id, array $input, string $key, int $channel): array
    {
        $this->flow->enabled('order_conversion_enabled');
        $this->flow->enabled('reservations_enabled');
        if (! $owner->customerId) {
            throw new SceneWorkflowException('SCENE_ORDER_LOGIN_REQUIRED', '请先登录并认领本需求，再确认正式订单。', 401);
        }
        $data = Validator::make($input, ['hold_group' => 'nullable|uuid', 'terms_hash' => 'required|regex:/^[a-f0-9]{64}$/D',
            'content_hash' => 'required|regex:/^[a-f0-9]{64}$/D'])->validate();

        return $this->flow->command($owner->scope(), 'scene.quote.convert:'.$id, $key, $data, function () use ($owner, $id, $data, $channel) {
            $ref = SceneWorkflow::requireRow('scene_quotes', $id, false);
            $request = $owner->request((int) $ref->request_id, true);
            $q = SceneWorkflow::requireRow('scene_quotes', $id);
            if (! $q->customer_visible || ! hash_equals($q->terms_hash, $data['terms_hash']) || ! hash_equals($q->content_hash, $data['content_hash'])) {
                throw new SceneWorkflowException('SCENE_TERMS_CHANGED', '报价与条款版本不符，请刷新后确认。', 409);
            }
            $existing = DB::table('scene_order_links')->where('quote_id', $id)->lockForUpdate()->first();
            if ($existing) {
                return $this->receipt($existing);
            }
            if ($q->state !== 'accepted' || $request->state !== 'accepted') {
                throw new SceneWorkflowException('SCENE_QUOTE_NOT_ACCEPTED', '客户尚未接受本版报价，或需求已变化。', 409);
            }
            $acceptance = DB::table('scene_quote_acceptances')->where('quote_id', $id)->lockForUpdate()->first();
            if (! $acceptance || ! hash_equals($q->terms_hash, $acceptance->terms_hash) || ! hash_equals($q->content_hash, $acceptance->content_hash)) {
                throw new SceneWorkflowException('SCENE_ACCEPTANCE_INTEGRITY', '缺少本版报价与条款的有效客户接受事实。', 409);
            }
            $this->proposals->assertUsable($q, $request, $channel);
            $commercial = $this->commercial->assertConfirmed($q, $request);
            $orders = $this->writer->prepare($q, $request, $channel);
            $hasResources = DB::table('scene_quote_items')->where('quote_id', $id)->get()->contains(function ($line) {
                $s = SceneWorkflow::unpack($line->snapshot);

                return $s['charge_type'] !== 'deposit' && in_array($s['kind'], ['robot', 'operator', 'accessory'], true);
            });
            $resources = $hasResources ? $this->reservations->confirm($q, $request, $data['hold_group'] ?? '') : [];
            $proposal = SceneWorkflow::unpack($q->proposal_snapshot);
            $terms = SceneWorkflow::unpack($q->terms_snapshot);
            $snapshot = ['quote_id' => $id, 'quote_hash' => $q->content_hash, 'terms_hash' => $q->terms_hash, 'terms' => SceneWorkflow::unpack($q->terms_snapshot),
                'proposal' => $proposal, 'schedule' => SceneWorkflow::unpack($q->schedule), 'commercial' => $commercial, 'resources' => $resources,
                'acceptance_template' => $proposal['acceptance'], 'workflow' => $terms['workflow'] ?? null];
            $projectId = DB::table('scene_projects')->insertGetId(['project_no' => (string) Str::uuid(), 'request_id' => $request->id, 'quote_id' => $id,
                'commercial_state' => 'confirmed', 'payment_state' => $commercial['payment_state'], 'snapshot' => SceneWorkflow::json($snapshot), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $order = $this->writer->create($orders['plan']);
            DB::table('scene_order_links')->insert(['quote_id' => $id, 'project_id' => $projectId, 'order_id' => $order->id, 'created_at' => now('UTC')]);
            if (isset($orders['skill'])) {
                $part = $this->writer->create($orders['skill']);
                DB::table('scene_order_parts')->insert(['quote_id' => $id, 'order_id' => $part->id, 'kind' => 'skill', 'created_at' => now('UTC')]);
                // 先提交完整业务，再由既有技能体系处理授权/绑定；不在创建子单时发设备指令。
                $this->flow->record('system', null, 'scene_project', $projectId, 1, 'scene.skill.fulfillment.pending');
            }
            app(SceneProjectService::class)->initializeTasks($projectId, null);
            DB::table('scene_requests')->where('id', $request->id)->update(['state' => 'converted', 'row_version' => $request->row_version + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('customer', $owner->customerId, 'scene_project', $projectId, 1, 'scene.order.converted');

            return $this->receipt(DB::table('scene_order_links')->where('quote_id', $id)->first());
        }, function () use ($owner, $id) {
            $q = SceneWorkflow::requireRow('scene_quotes', $id, false);
            $owner->request((int) $q->request_id);
        });
    }

    public function status(ScenePrincipal $owner, int $id): array
    {
        $q = SceneWorkflow::requireRow('scene_quotes', $id, false);
        $owner->request((int) $q->request_id);
        if (! $q->customer_visible) {
            throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '报价不存在。', 404);
        }
        $link = DB::table('scene_order_links')->where('quote_id', $id)->first();
        $rows = DB::table('scene_reservations')->where('quote_id', $id)->where('state', 'held')->where('hold_expires_at', '>', now('UTC'))->orderBy('resource_id')->get();
        $approved = DB::table('scene_quote_commercial')->where('quote_id', $id)->where('state', 'approved')->get(['kind', 'amount_minor']);

        return ['quote' => $this->proposals->customerDto($q), 'request_id' => (int) $q->request_id,
            'conversion_enabled' => (bool) config('scenes.order_conversion_enabled') && (bool) config('scenes.reservations_enabled'),
            'login_required' => ! $owner->customerId, 'receipt' => $link ? $this->receipt($link) : null,
            'hold_group' => $rows->first()?->hold_group, 'hold_expires_at' => $rows->first()?->hold_expires_at,
            'held_resources' => $rows->map(fn ($r) => ['kind' => SceneWorkflow::unpack($r->resource_snapshot)['kind'] ?? 'resource', 'occupies_from' => $r->occupies_from, 'occupies_until' => $r->occupies_until])->all(),
            'commercial' => ['contract_reviewed' => $approved->contains('kind', 'offline_contract'), 'paid_minor' => (int) $approved->where('kind', 'offline_receipt')->sum('amount_minor'), 'source' => 'independently_reviewed_offline_records']];
    }

    private function receipt(object $link): array
    {
        // RR 中等待另一个转换提交后，旧一致性快照可能还看不到刚创建的项目和订单。
        $current = DB::transactionLevel() > 0;
        $p = SceneWorkflow::requireRow('scene_projects', (int) $link->project_id, $current);
        $order = SceneWorkflow::requireRow('orders', (int) $link->order_id, $current);
        $parts = DB::table('scene_order_parts')->where('quote_id', $link->quote_id);
        $part = ($current ? $parts->lockForUpdate() : $parts)->first();

        return ['id' => (int) $p->id, 'state' => 'confirmed', 'quote_id' => (int) $link->quote_id, 'order_id' => (int) $link->order_id, 'increment_id' => $order->increment_id,
            'skill_order_id' => $part ? (int) $part->order_id : null, 'project_id' => (int) $p->id, 'project_no' => $p->project_no,
            'delivery_status' => 'confirmed', 'commercial_state' => $p->commercial_state, 'payment_state' => $p->payment_state];
    }
}
