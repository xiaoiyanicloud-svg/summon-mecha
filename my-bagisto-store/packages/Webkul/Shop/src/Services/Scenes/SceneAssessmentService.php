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
            'survey_id' => 'nullable|integer|min:1',
            'candidates' => 'required|array|min:1|max:50',
            'candidates.*' => 'array:product_id,binding_id,capability,evidence_id',
            'candidates.*.product_id' => 'required|integer|min:1',
            'candidates.*.binding_id' => 'nullable|integer|min:1',
            'candidates.*.capability' => 'required|string|regex:/^[a-z][a-z0-9_.-]{0,79}$/',
            'candidates.*.evidence_id' => 'nullable|integer|min:1',
        ])->validate();
        $keys = array_map(fn ($c) => implode(':', [$c['binding_id'] ?? 0, $c['product_id'], $c['capability']]), $data['candidates']);
        if (count($keys) !== count(array_unique($keys))) {
            throw new SceneWorkflowException('SCENE_CANDIDATE_DUPLICATE', '同一设备和商品的任务候选不能重复。');
        }

        return DB::transaction(function () use ($requestId, $expected, $data, $actor, $channel) {
            $request = SceneWorkflow::requireRow('scene_requests', $requestId);
            SceneWorkflow::version($request, $expected);
            if (in_array($request->state, ['converted', 'closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '当前需求不支持重新评估。', 409);
            }
            if ($request->state === 'accepted' || DB::table('scene_quotes')->where('request_id', $requestId)->where('state', 'accepted')->exists()
                || DB::table('scene_projects')->where('request_id', $requestId)->exists()) {
                throw new SceneWorkflowException('SCENE_CHANGE_REQUIRED', '已有客户接受或履约承诺，重新评估须先处理变更。', 409);
            }
            $revision = DB::table('scene_request_revisions')->where('request_id', $requestId)->where('revision_no', $request->revision_no)->first();
            $customerFacts = SceneWorkflow::unpack($revision->facts);
            $schema = SceneWorkflow::unpack($revision->schema_snapshot);
            $survey = ! empty($data['survey_id']) ? SceneWorkflow::requireRow('scene_site_surveys', (int) $data['survey_id'], false) : null;
            if ($survey) {
                app(SceneSurveyService::class)->assertCurrent($survey, $request);
            }
            $facts = $survey ? SceneWorkflow::unpack($survey->facts) : $customerFacts;
            $checks = [];
            $add = function ($key, $state, $reason) use (&$checks) {
                $checks[] = compact('key', 'state', 'reason');
            };
            $add('site.schema', ($schema['version'] ?? 1) >= 2 ? 'pass' : 'unknown', '旧版现场信息须显式升级补充，不能隐含适用新增专业规则');
            $add('site.survey', $survey ? 'pass' : 'unknown', '正式方案须有当前修订对应的现场勘察及私有依据');
            $checks = array_merge($checks, app(SceneSiteRules::class)->site($schema, $facts, $survey ? SceneWorkflow::unpack($survey->provenance) : []));
            foreach (['city', 'site', 'timezone', 'starts_at', 'ends_at'] as $key) {
                $add('customer_confirmed.'.$key, empty($customerFacts[$key]) ? 'unknown' : (($customerFacts[$key] ?? null) === ($facts[$key] ?? null) ? 'pass' : 'fail'), '地点及服务时段须与客户当前修订一致；不静默覆盖客户确认');
            }
            $qualified = $this->catalog->rentalProductIds($channel, array_column($data['candidates'], 'product_id'), true);
            $snapshot = [];
            usort($data['candidates'], fn ($a, $b) => ($a['binding_id'] ?? 0) <=> ($b['binding_id'] ?? 0));
            foreach ($data['candidates'] as $candidate) {
                $prefix = 'product.'.$candidate['product_id'].'.binding.'.($candidate['binding_id'] ?? 'unknown').'.task.'.$candidate['capability'];
                $add($prefix.'.catalog', in_array((int) $candidate['product_id'], $qualified, true) ? 'pass' : 'fail', '当前渠道租用目录资格');
                $binding = ! empty($candidate['binding_id']) ? DB::table('device_binding')->where('id', $candidate['binding_id'])->lockForUpdate()->first() : null;
                $add($prefix.'.binding', ! $binding ? 'unknown' : ($binding->bind_status === 'bound' ? 'pass' : 'fail'), '绑定记录只引用 device_binding，不在场景域更改设备归属');
                $evidence = ! empty($candidate['evidence_id']) ? DB::table('scene_evidence')->where('id', $candidate['evidence_id'])->lockForUpdate()->first() : null;
                $qualifiedSnapshot = [];
                if ($binding && $evidence) {
                    try {
                        $qualifiedSnapshot = app(SceneQualificationService::class)->assertEvidence($evidence, $binding, $candidate, true);
                        if ($qualifiedSnapshot['skill']) {
                            app(SceneQualificationService::class)->assertSkillSale($qualifiedSnapshot['skill'], $channel);
                        }
                        $add($prefix.'.task_evidence', 'pass', '具体设备、任务及版本指纹已独立复核');
                        $checks = array_merge($checks, app(SceneSiteRules::class)->capability($schema['category'], $facts, $qualifiedSnapshot['constraints'], $prefix));
                    } catch (SceneWorkflowException $e) {
                        $add($prefix.'.task_evidence', 'fail', $e->getMessage());
                    }
                } else {
                    $add($prefix.'.task_evidence', 'unknown', '缺少具体设备或任务证据，不能确认可交付');
                }
                $snapshot[] = $candidate + $qualifiedSnapshot;
            }
            $states = array_column($checks, 'state');
            $state = in_array('fail', $states, true) ? 'infeasible' : (in_array('unknown', $states, true) ? 'needs_manual_review' : 'feasible');
            $id = DB::table('scene_assessments')->insertGetId([
                'request_id' => $requestId, 'request_revision_no' => $request->revision_no, 'input_hash' => $revision->input_hash,
                'state' => $state, 'checks' => SceneWorkflow::json($checks), 'candidate_snapshot' => SceneWorkflow::json($snapshot), 'created_by' => $actor, 'created_at' => now('UTC'),
                'survey_id' => $survey?->id, 'survey_hash' => $survey?->input_hash, 'rule_version' => 'scene-site-v2', 'channel_id' => $channel,
            ]);
            DB::table('scene_requests')->where('id', $requestId)->update(['state' => $state === 'feasible' ? 'feasible' : 'assessing', 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            app(SceneQuoteLifecycleService::class)->invalidate($requestId, ['draft', 'in_review', 'approved', 'sent', 'returned'], 'assessment_updated', 'admin', $actor);
            $this->flow->record('admin', $actor, 'scene_request', $requestId, $expected + 1, 'scene.assessment.created', ['assessment_id' => $id]);

            return ['id' => $id, 'state' => $state, 'checks' => $checks, 'request_row_version' => $expected + 1];
        }, 3);
    }

    public function assertCurrent(object $assessment, object $request, int $channel): void
    {
        $revision = DB::table('scene_request_revisions')->where('request_id', $request->id)->where('revision_no', $request->revision_no)->first();
        $latest = DB::table('scene_assessments')->where('request_id', $request->id)->where('request_revision_no', $request->revision_no)->orderByDesc('id')->value('id');
        if ((int) $latest !== (int) $assessment->id) {
            throw new SceneWorkflowException('SCENE_ASSESSMENT_STALE', '已有更新评估，原方案须重新核实。', 409);
        }
        if ((int) $assessment->channel_id !== $channel || (int) $assessment->request_id !== (int) $request->id || $assessment->state !== 'feasible' || $assessment->rule_version !== 'scene-site-v2' || (int) $assessment->request_revision_no !== (int) $request->revision_no || ! hash_equals($assessment->input_hash, $revision->input_hash)) {
            throw new SceneWorkflowException('SCENE_ASSESSMENT_STALE', '评估不完整或已因需求修订而失效。', 409);
        }
        $survey = SceneWorkflow::requireRow('scene_site_surveys', (int) $assessment->survey_id, false);
        app(SceneSurveyService::class)->assertCurrent($survey, $request);
        if (! hash_equals($assessment->survey_hash, $survey->input_hash)) {
            throw new SceneWorkflowException('SCENE_ASSESSMENT_STALE', '现场勘察已变化。', 409);
        }
        $candidates = SceneWorkflow::unpack($assessment->candidate_snapshot);
        $qualified = $this->catalog->rentalProductIds($channel, array_column($candidates, 'product_id'), true);
        foreach ($candidates as $candidate) {
            $binding = DB::table('device_binding')->where('id', $candidate['binding_id'] ?? 0)->lockForUpdate()->first();
            $e = DB::table('scene_evidence')->where('id', $candidate['evidence_id'] ?? 0)->lockForUpdate()->first();
            if (! $e || ! $binding || ! in_array((int) $candidate['product_id'], $qualified, true)) {
                throw new SceneWorkflowException('SCENE_QUALIFICATION_CHANGED', '商品、绑定或能力证据已失效，需重新评估。', 409);
            }
            $current = app(SceneQualificationService::class)->assertEvidence($e, $binding, $candidate, true);
            if ($current['skill']) {
                app(SceneQualificationService::class)->assertSkillSale($current['skill'], $channel);
            }
            if (! hash_equals($candidate['qualification_hash'] ?? '', $current['qualification_hash'])) {
                throw new SceneWorkflowException('SCENE_QUALIFICATION_CHANGED', '设备或任务依据指纹已经改变。', 409);
            }
        }
    }
}
