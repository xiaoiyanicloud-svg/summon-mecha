<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SceneReservationService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneProposalService $proposals, protected SceneResourceService $resources) {}

    public function hold(int $quoteId, array $input, string $key, int $actor, int $channel): array
    {
        $this->flow->enabled('reservations_enabled');
        $data = Validator::make($input, ['resource_ids' => 'required|array|min:1|max:100', 'resource_ids.*' => 'required|integer|distinct|min:1'])->validate();
        $data['resource_ids'] = array_map('intval', $data['resource_ids']);
        sort($data['resource_ids'], SORT_NUMERIC);

        return $this->flow->command('admin:'.$actor, 'scene.reservation.hold:'.$quoteId, $key, $data, function () use ($quoteId, $data, $actor, $channel) {
            $ref = SceneWorkflow::requireRow('scene_quotes', $quoteId, false);
            $request = SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
            $quote = SceneWorkflow::requireRow('scene_quotes', $quoteId);
            if (! in_array($quote->state, ['sent', 'accepted'], true) || $request->state === 'converted') {
                throw new SceneWorkflowException('SCENE_QUOTE_UNAVAILABLE', '只有未转换的已发送或已接受报价可预留资源。', 409);
            }
            $this->proposals->assertUsable($quote, $request, $channel);
            $schedule = SceneWorkflow::unpack($quote->schedule);
            $this->assertFuture($quote, $schedule);
            if (DB::table('scene_reservations')->where('quote_id', $quoteId)->where('state', 'held')->where('hold_expires_at', '>', now('UTC'))->lockForUpdate()->first()) {
                throw new SceneWorkflowException('SCENE_ACTIVE_HOLD_EXISTS', '此报价已有有效预留，请使用原回执或先释放原组。', 409);
            }
            $selected = $this->lockAndCheck($quote, $request, $data['resource_ids']);
            $group = (string) Str::uuid();
            $until = now('UTC')->addMinutes(max(1, min(120, (int) config('scenes.hold_minutes', 20))));
            $limit = $quote->state === 'accepted' ? ($quote->confirmation_deadline ?: $schedule['service_starts_at']) : $quote->expires_at;
            $until = $until->min(\Illuminate\Support\Carbon::parse($limit, 'UTC'));
            foreach ($selected as [$resource, $interval]) {
                DB::table('scene_reservations')->insert([
                    'resource_id' => $resource->id, 'quote_id' => $quoteId, 'hold_group' => $group, 'state' => 'held',
                    'service_starts_at' => $schedule['service_starts_at'], 'service_ends_at' => $schedule['service_ends_at'],
                    'occupies_from' => $interval['occupies_from'], 'occupies_until' => $interval['occupies_until'],
                    'resource_snapshot' => SceneWorkflow::json($this->resources->snapshot($resource)),
                    'hold_expires_at' => $until, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            $this->flow->record('admin', $actor, 'scene_quote', $quoteId, (int) $quote->row_version, 'scene.resources.held');

            return ['hold_group' => $group, 'state' => 'held', 'expires_at' => $until->toIso8601String(), 'delivery_status' => 'awaiting_confirmation'];
        });
    }

    /** 调用方持有 request/quote 锁；资源父行按 ID 升序，预留记录使用当前读。 */
    public function confirm(object $quote, object $request, string $group): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('资源确认必须在建单事务内。');
        }
        $this->assertFuture($quote, SceneWorkflow::unpack($quote->schedule));
        $refs = DB::table('scene_reservations')->where('quote_id', $quote->id)->where('hold_group', $group)->orderBy('resource_id')->get();
        if ($refs->isEmpty()) {
            throw new SceneWorkflowException('SCENE_HOLD_REQUIRED', '请由交付人员先确认完整资源预留组。', 409);
        }
        $selected = $this->lockAndCheck($quote, $request, $refs->pluck('resource_id')->map(fn ($id) => (int) $id)->all(), $group);
        $rows = DB::table('scene_reservations')->where('quote_id', $quote->id)->where('hold_group', $group)->orderBy('resource_id')->lockForUpdate()->get();
        foreach ($rows as $index => $row) {
            [$resource, $interval] = $selected[$index];
            if ($row->state !== 'held' || ! $row->hold_expires_at || $row->hold_expires_at <= now('UTC')) {
                throw new SceneWorkflowException('SCENE_HOLD_EXPIRED', '预留已到期或释放，请重新核验完整资源后预留。', 409);
            }
            if (! $row->resource_snapshot || ! hash_equals(SceneWorkflow::hash(SceneWorkflow::unpack($row->resource_snapshot)), SceneWorkflow::hash($this->resources->snapshot($resource)))
                || $row->occupies_from !== $interval['occupies_from'] || $row->occupies_until !== $interval['occupies_until']) {
                throw new SceneWorkflowException('SCENE_RESOURCE_CHANGED', '预留后资源核验发生变化，请重新预留。', 409);
            }
        }
        DB::table('scene_reservations')->whereIn('id', $rows->pluck('id'))->update(['state' => 'confirmed', 'updated_at' => now('UTC')]);

        return $rows->map(fn ($r) => ['reservation_id' => (int) $r->id, 'resource' => SceneWorkflow::unpack($r->resource_snapshot), 'occupies_from' => $r->occupies_from, 'occupies_until' => $r->occupies_until])->all();
    }

    public function release(int $quoteId, string $group, string $key, int $actor): array
    {
        $this->flow->enabled('reservations_enabled');
        Validator::make(['hold_group' => $group], ['hold_group' => 'required|uuid'])->validate();

        return $this->flow->command('admin:'.$actor, 'scene.reservation.release:'.$quoteId, $key, [$group], function () use ($quoteId, $group, $actor) {
            $q = SceneWorkflow::requireRow('scene_quotes', $quoteId, false);
            SceneWorkflow::requireRow('scene_requests', (int) $q->request_id);
            SceneWorkflow::requireRow('scene_quotes', $quoteId);
            $refs = DB::table('scene_reservations')->where('quote_id', $quoteId)->where('hold_group', $group)->orderBy('resource_id')->get();
            if ($refs->isEmpty()) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '预留组不存在。', 404);
            }
            foreach ($refs as $r) {
                SceneWorkflow::requireRow('scene_resources', (int) $r->resource_id);
            }
            $rows = DB::table('scene_reservations')->whereIn('id', $refs->pluck('id'))->lockForUpdate()->get();
            if ($rows->contains('state', 'confirmed')) {
                throw new SceneWorkflowException('SCENE_CHANGE_REQUIRED', '已确认履约资源须走项目变更，不能释放临时预留。', 409);
            }
            DB::table('scene_reservations')->whereIn('id', $refs->pluck('id'))->where('state', 'held')->update(['state' => 'released', 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_quote', $quoteId, (int) $q->row_version, 'scene.resources.released');

            return ['hold_group' => $group, 'state' => 'released'];
        });
    }

    public function expire(): int
    {
        $count = 0;
        $ids = DB::table('scene_reservations')->where('state', 'held')->where('hold_expires_at', '<=', now('UTC'))->distinct()->orderBy('resource_id')->pluck('resource_id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $r = SceneWorkflow::requireRow('scene_resources', (int) $id);
                $n = DB::table('scene_reservations')->where('resource_id', $id)->where('state', 'held')->where('hold_expires_at', '<=', now('UTC'))->update(['state' => 'expired', 'updated_at' => now('UTC')]);
                if ($n) {
                    $this->flow->record('system', null, 'scene_resource', (int) $id, (int) $r->row_version, 'scene.resources.expired');
                }

                return $n;
            }, 3);
        }

        return $count;
    }

    private function assertFuture(object $quote, array $schedule): void
    {
        if ($schedule['service_starts_at'] <= now('UTC')->format('Y-m-d H:i:s') || ($quote->confirmation_deadline && $quote->confirmation_deadline <= now('UTC'))) {
            throw new SceneWorkflowException('SCENE_CONFIRMATION_EXPIRED', '服务已开始或确认期限已过，请人工协商新版安排。', 409);
        }
    }

    private function lockAndCheck(object $quote, object $request, array $ids, ?string $excludeGroup = null): array
    {
        sort($ids, SORT_NUMERIC);
        $schedule = SceneWorkflow::unpack($quote->schedule);
        $revision = DB::table('scene_request_revisions')->where('request_id', $request->id)->where('revision_no', $request->revision_no)->first();
        $facts = SceneWorkflow::unpack($revision->facts);
        $assessment = SceneWorkflow::requireRow('scene_assessments', (int) $quote->assessment_id);
        $bindings = array_map('intval', array_column(SceneWorkflow::unpack($assessment->candidate_snapshot), 'binding_id'));
        foreach (SceneWorkflow::unpack($assessment->candidate_snapshot) as $candidate) {
            if (($candidate['evidence_expires_at'] ?? '') < $schedule['occupies_until']) {
                throw new SceneWorkflowException('SCENE_EVIDENCE_SERVICE_EXPIRY', '任务能力依据有效期不足以覆盖完整履约占用时段。', 409);
            }
        }
        $needs = [];
        foreach (DB::table('scene_quote_items')->where('quote_id', $quote->id)->orderBy('line_no')->get() as $line) {
            $s = SceneWorkflow::unpack($line->snapshot);
            if ($s['charge_type'] !== 'deposit' && in_array($s['kind'], ['robot', 'operator', 'accessory'], true)) {
                $key = $s['kind'].':'.($s['product_id'] ?: 0);
                $needs[$key] = ($needs[$key] ?? 0) + (int) $line->quantity;
            }
        }
        $selected = [];
        $actual = [];
        // 先锁全部父行，再查询任意预留；统一升序避免多资源反向锁序。
        $parents = array_map(fn ($id) => SceneWorkflow::requireRow('scene_resources', (int) $id), $ids);
        foreach ($parents as $r) {
            $interval = $this->resources->interval($r, $schedule, $facts['city'] ?? '');
            if (in_array($r->kind, ['robot', 'accessory'], true) && DB::table('scene_reservations as reservation')
                ->join('scene_order_links as link', 'link.quote_id', '=', 'reservation.quote_id')
                ->join('scene_projects as project', 'project.id', '=', 'link.project_id')
                ->where('reservation.resource_id', $r->id)->where('reservation.state', 'confirmed')->where('reservation.occupies_until', '<=', now('UTC'))
                ->where('project.returned', false)->whereNotIn('project.state', ['cancelled'])->lockForUpdate()->first(['reservation.id'])) {
                throw new SceneWorkflowException('SCENE_RESOURCE_RETURN_PENDING', '资源已超过原返还时限且未核实返库，须先处理晚归与后续档期影响。', 409);
            }
            if ($r->kind === 'robot' && ! in_array((int) $r->binding_id, $bindings, true)) {
                throw new SceneWorkflowException('SCENE_RESOURCE_UNASSESSED', '预留设备未包含在当前任务评估中。', 409);
            }
            $collision = DB::table('scene_reservations')->where('resource_id', $r->id)
                ->where('occupies_from', '<', $interval['occupies_until'])->where('occupies_until', '>', $interval['occupies_from'])
                ->where(fn ($q) => $q->where('state', 'confirmed')->orWhere(fn ($q) => $q->where('state', 'held')->where('hold_expires_at', '>', now('UTC'))));
            if ($excludeGroup) {
                $collision->where(fn ($q) => $q->where('quote_id', '!=', $quote->id)->orWhere('hold_group', '!=', $excludeGroup));
            }
            if ($collision->lockForUpdate()->first()) {
                throw new SceneWorkflowException('SCENE_RESOURCE_CONFLICT', '资源在含行程、休息和维护缓冲的时段内已有占用。', 409);
            }
            $key = $r->kind.':'.($r->product_id ?: 0);
            $actual[$key] = ($actual[$key] ?? 0) + 1;
            $selected[] = [$r, $interval];
        }
        ksort($needs);
        ksort($actual);
        if ($needs !== $actual) {
            throw new SceneWorkflowException('SCENE_RESOURCE_BOM_MISMATCH', '资源逐台数量与报价 BOM 不一致。');
        }

        return $selected;
    }

    public function pause(int $id, int $expected, string $reason, int $actor): array
    {
        $this->flow->enabled('reservations_enabled');
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:5000'])->validate();

        return DB::transaction(function () use ($id, $expected, $reason, $actor) {
            $r = SceneWorkflow::requireRow('scene_resources', $id);
            SceneWorkflow::version($r, $expected);
            DB::table('scene_resources')->where('id', $id)->update(['state' => 'maintenance', 'state_reason' => $reason, 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            foreach (DB::table('scene_reservations')->where('resource_id', $id)->where('occupies_until', '>', now('UTC'))
                ->where(fn ($q) => $q->where('state', 'confirmed')->orWhere(fn ($q) => $q->where('state', 'held')->where('hold_expires_at', '>', now('UTC'))))->lockForUpdate()->get() as $reservation) {
                $quote = SceneWorkflow::requireRow('scene_quotes', (int) $reservation->quote_id, false);
                DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'resource:'.$id.':reservation:'.$reservation->id.':'.$expected,
                    'request_id' => $quote->request_id, 'kind' => 'resource_impact', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }
            $this->flow->record('admin', $actor, 'scene_resource', $id, $expected + 1, 'scene.resource.paused');

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => 'maintenance'];
        }, 3);
    }
}
