<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SceneReservationService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneProposalService $proposals) {}

    public function hold(int $quoteId, array $input, string $key, int $actor, int $channel): array
    {
        $this->flow->enabled('reservations_enabled');
        $data = Validator::make($input, ['resource_ids' => 'required|array|min:1|max:100', 'resource_ids.*' => 'required|integer|distinct|min:1'])->validate();
        sort($data['resource_ids'], SORT_NUMERIC);

        return $this->flow->command('admin:'.$actor, 'scene.reservation.hold:'.$quoteId, $key, $data, function () use ($quoteId, $data, $actor, $channel) {
            $ref = SceneWorkflow::requireRow('scene_quotes', $quoteId, false);
            $request = SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
            $quote = SceneWorkflow::requireRow('scene_quotes', $quoteId);
            if (! in_array($quote->state, ['sent', 'accepted'], true)) {
                throw new SceneWorkflowException('SCENE_QUOTE_UNAVAILABLE', '只有已发送或已接受的报价可预留资源。', 409);
            }
            $this->proposals->assertUsable($quote, $request, $channel);
            $schedule = SceneWorkflow::unpack($quote->schedule);
            if (strtotime($schedule['service_starts_at'].' UTC') <= now('UTC')->timestamp) {
                throw new SceneWorkflowException('SCENE_SCHEDULE_PAST', '服务时间已开始，不能新建预留。', 409);
            }
            $factsRevision = DB::table('scene_request_revisions')->where('request_id', $request->id)->where('revision_no', $request->revision_no)->first();
            $facts = SceneWorkflow::unpack($factsRevision->facts);
            $assessment = SceneWorkflow::requireRow('scene_assessments', (int) $quote->assessment_id);
            $candidateBindings = array_map('intval', array_column(SceneWorkflow::unpack($assessment->candidate_snapshot), 'binding_id'));
            $needs = [];
            foreach (DB::table('scene_quote_items')->where('quote_id', $quoteId)->get() as $line) {
                $s = SceneWorkflow::unpack($line->snapshot);
                if ($s['charge_type'] === 'deposit' || ! in_array($s['kind'], ['robot', 'operator', 'accessory'], true)) {
                    continue;
                }
                $needKey = $s['kind'].':'.($s['product_id'] ?? 0);
                $needs[$needKey] = ($needs[$needKey] ?? 0) + (int) $line->quantity;
            }
            $actual = [];
            $resources = [];
            foreach ($data['resource_ids'] as $resourceId) {
                // 先锁稳定父行，空档查询也遵循同一资源升序；冲突查询使用当前读。
                $resource = SceneWorkflow::requireRow('scene_resources', (int) $resourceId);
                if ($resource->state !== 'ready' || ! $resource->verified_at || ! $resource->verified_by || ! $resource->verification_expires_at || $resource->verification_expires_at < $schedule['occupies_until'] || $resource->city !== ($facts['city'] ?? '') || $resource->timezone !== $schedule['timezone']) {
                    throw new SceneWorkflowException('SCENE_RESOURCE_UNAVAILABLE', '资源未核验、已停用、核验期不足或服务地点不匹配。', 409);
                }
                if ($resource->kind === 'robot' && ! in_array((int) $resource->binding_id, $candidateBindings, true)) {
                    throw new SceneWorkflowException('SCENE_RESOURCE_UNASSESSED', '预留设备未包含在当前任务评估中。', 409);
                }
                $collision = DB::table('scene_reservations')->where('resource_id', $resourceId)
                    ->where('occupies_from', '<', $schedule['occupies_until'])->where('occupies_until', '>', $schedule['occupies_from'])
                    ->where(function ($q) {
                        $q->where('state', 'confirmed')->orWhere(fn ($q) => $q->where('state', 'held')->where('hold_expires_at', '>', now('UTC')));
                    })
                    ->lockForUpdate()->get();
                if ($collision->isNotEmpty()) {
                    throw new SceneWorkflowException('SCENE_RESOURCE_CONFLICT', '资源在含运输与维护缓冲的时段内已有占用。', 409);
                }
                $needKey = $resource->kind.':'.($resource->product_id ?? 0);
                $actual[$needKey] = ($actual[$needKey] ?? 0) + 1;
                $resources[] = $resource;
            }
            ksort($needs);
            ksort($actual);
            if ($needs !== $actual) {
                throw new SceneWorkflowException('SCENE_RESOURCE_BOM_MISMATCH', '资源逐台数量与报价 BOM 不一致。');
            }
            $group = (string) Str::uuid();
            $until = now('UTC')->addMinutes((int) config('scenes.hold_minutes', 20));
            $quoteExpiry = \Illuminate\Support\Carbon::parse($quote->expires_at, 'UTC');
            if ($quoteExpiry < $until) {
                $until = $quoteExpiry;
            }
            foreach ($resources as $resource) {
                DB::table('scene_reservations')->insert([
                    'resource_id'       => $resource->id, 'quote_id' => $quoteId, 'hold_group' => $group, 'state' => 'held',
                    'service_starts_at' => $schedule['service_starts_at'], 'service_ends_at' => $schedule['service_ends_at'],
                    'occupies_from'     => $schedule['occupies_from'], 'occupies_until' => $schedule['occupies_until'],
                    'hold_expires_at'   => $until, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            $this->flow->record('admin', $actor, 'scene_quote', $quoteId, (int) $quote->row_version, 'scene.resources.held');

            return ['hold_group' => $group, 'state' => 'held', 'expires_at' => $until->toIso8601String(), 'delivery_status' => 'awaiting_confirmation'];
        });
    }

    public function pause(int $id, int $expected, string $reason, int $actor): array
    {
        $this->flow->enabled('reservations_enabled');
        if (trim($reason) === '' || strlen($reason) > 5000) {
            throw new SceneWorkflowException('SCENE_REASON_REQUIRED', '请填写资源暂停原因。');
        }

        return DB::transaction(function () use ($id, $expected, $reason, $actor) {
            $r = SceneWorkflow::requireRow('scene_resources', $id);
            SceneWorkflow::version($r, $expected);
            DB::table('scene_resources')->where('id', $id)->update(['state' => 'maintenance', 'state_reason' => $reason, 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            foreach (DB::table('scene_reservations')->where('resource_id', $id)->whereIn('state', ['held', 'confirmed'])->where('occupies_until', '>', now('UTC'))->get() as $reservation) {
                $quote = SceneWorkflow::requireRow('scene_quotes', (int) $reservation->quote_id, false);
                DB::table('scene_operation_todos')->insertOrIgnore([
                    'dedupe_key' => 'resource:'.$id.':reservation:'.$reservation->id.':'.$expected,
                    'request_id' => $quote->request_id, 'kind' => 'resource_impact', 'state' => 'open',
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            $this->flow->record('admin', $actor, 'scene_resource', $id, $expected + 1, 'scene.resource.paused');

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => 'maintenance'];
        }, 3);
    }
}
