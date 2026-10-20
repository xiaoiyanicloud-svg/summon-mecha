<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SceneAssessmentService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneCatalogService $catalog) {}

    public function assess(int $requestId, int $expected, array $input, int $actor, int $channel): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, [
            'candidates'               => 'required|array|min:1|max:50',
            'candidates.*'             => 'array:product_id,binding_id,capability,evidence_id',
            'candidates.*.product_id'  => 'required|integer|min:1',
            'candidates.*.binding_id'  => 'nullable|integer|distinct|min:1',
            'candidates.*.capability'  => 'required|string|regex:/^[a-z][a-z0-9_.-]{0,79}$/',
            'candidates.*.evidence_id' => 'nullable|integer|min:1',
        ])->validate();

        return DB::transaction(function () use ($requestId, $expected, $data, $actor, $channel) {
            $request = SceneWorkflow::requireRow('scene_requests', $requestId);
            SceneWorkflow::version($request, $expected);
            if (in_array($request->state, ['converted', 'closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '当前需求不支持重新评估。', 409);
            }
            $revision = DB::table('scene_request_revisions')->where('request_id', $requestId)->where('revision_no', $request->revision_no)->first();
            $facts = SceneWorkflow::unpack($revision->facts);
            $checks = [];
            $add = function ($key, $state, $reason) use (&$checks) {
                $checks[] = compact('key', 'state', 'reason');
            };
            foreach (['city', 'site', 'timezone', 'starts_at', 'ends_at'] as $key) {
                $add('site.'.$key, ! empty($facts[$key]) ? 'pass' : 'unknown', ! empty($facts[$key]) ? '已提供，现场真实性仍以勘察证据为准' : '客户尚未补充');
            }
            if (! empty($facts['starts_at']) && ! empty($facts['ends_at']) && ! empty($facts['timezone'])) {
                try {
                    SceneTime::schedule($facts + ['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
                    $add('site.schedule', 'pass', '服务地时间及区间有效');
                } catch (SceneWorkflowException $e) {
                    $add('site.schedule', 'fail', $e->getMessage());
                }
            }
            foreach (['indoor', 'floor_level', 'power', 'safety_contact'] as $key) {
                $value = $facts[$key] ?? null;
                $state = $value === null ? 'unknown' : (in_array($value, [true, 1, '1'], true) ? 'pass' : 'fail');
                $add('site.'.$key, $state, $state === 'pass' ? '客户提供条件；需在现场任务复核' : '当前人工交付样板要求此条件，未知不得承诺');
            }
            $qualified = $this->catalog->rentalProductIds($channel, array_column($data['candidates'], 'product_id'));
            $snapshot = [];
            foreach ($data['candidates'] as $candidate) {
                $prefix = 'product.'.$candidate['product_id'];
                $add($prefix.'.catalog', in_array((int) $candidate['product_id'], $qualified, true) ? 'pass' : 'fail', '当前渠道租用目录资格');
                $binding = ! empty($candidate['binding_id']) ? DB::table('device_binding')->where('id', $candidate['binding_id'])->first() : null;
                $add($prefix.'.binding', ! $binding ? 'unknown' : ($binding->bind_status === 'bound' ? 'pass' : 'fail'), '绑定记录只引用 device_binding，不在场景域更改设备归属');
                $evidence = ! empty($candidate['evidence_id']) ? DB::table('scene_evidence')->where('id', $candidate['evidence_id'])->first() : null;
                $valid = $evidence && ! $evidence->revoked_at && $evidence->expires_at > now('UTC')
                    && (int) $evidence->product_id === (int) $candidate['product_id']
                    && $evidence->capability === $candidate['capability'] && $evidence->level === 'L1'
                    && $binding && (int) $evidence->binding_id === (int) $binding->id;
                $add($prefix.'.task_evidence', $valid ? 'pass' : ($evidence ? 'fail' : 'unknown'), '须匹配具体设备、商品及任务能力的有效 L1 人工核验记录');
                $snapshot[] = $candidate + ['evidence_hash' => $valid ? $evidence->artifact_hash : null, 'evidence_expires_at' => $valid ? $evidence->expires_at : null];
            }
            $states = array_column($checks, 'state');
            $state = in_array('fail', $states, true) ? 'infeasible' : (in_array('unknown', $states, true) ? 'needs_manual_review' : 'feasible');
            $id = DB::table('scene_assessments')->insertGetId([
                'request_id' => $requestId, 'request_revision_no' => $request->revision_no, 'input_hash' => $revision->input_hash,
                'state'      => $state, 'checks' => SceneWorkflow::json($checks), 'candidate_snapshot' => SceneWorkflow::json($snapshot), 'created_by' => $actor, 'created_at' => now('UTC'),
            ]);
            DB::table('scene_requests')->where('id', $requestId)->update(['state' => $state === 'feasible' ? 'feasible' : 'assessing', 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_request', $requestId, $expected + 1, 'scene.assessment.created', ['assessment_id' => $id]);

            return ['id' => $id, 'state' => $state, 'checks' => $checks, 'request_row_version' => $expected + 1];
        }, 3);
    }

    public function assertCurrent(object $assessment, object $request, int $channel): void
    {
        $revision = DB::table('scene_request_revisions')->where('request_id', $request->id)->where('revision_no', $request->revision_no)->first();
        if ($assessment->state !== 'feasible' || (int) $assessment->request_revision_no !== (int) $request->revision_no || ! hash_equals($assessment->input_hash, $revision->input_hash)) {
            throw new SceneWorkflowException('SCENE_ASSESSMENT_STALE', '评估不完整或已因需求修订而失效。', 409);
        }
        foreach (SceneWorkflow::unpack($assessment->candidate_snapshot) as $candidate) {
            $e = DB::table('scene_evidence')->where('id', $candidate['evidence_id'] ?? 0)->first();
            $binding = DB::table('device_binding')->where('id', $candidate['binding_id'] ?? 0)->first();
            if (! $e || $e->revoked_at || $e->expires_at <= now('UTC') || ! hash_equals($e->artifact_hash, $candidate['evidence_hash'] ?? '') || ! $binding || $binding->bind_status !== 'bound'
                || ! in_array((int) $candidate['product_id'], $this->catalog->rentalProductIds($channel, [$candidate['product_id']]), true)) {
                throw new SceneWorkflowException('SCENE_QUALIFICATION_CHANGED', '商品、绑定或能力证据已失效，需重新评估。', 409);
            }
        }
    }
}
