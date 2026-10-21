<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SceneSurveyService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneSchemaRegistry $schemas, protected SceneAttachmentService $attachments) {}

    public function record(int $requestId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, ['facts' => 'present|array|max:30', 'verified_keys' => 'required|array|min:1|max:30', 'verified_keys.*' => 'required|string|distinct|max:80',
            'evidence_ids' => 'required|array|min:1|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1', 'note' => 'required|string|min:3|max:10000', 'occurred_at' => 'required|string|max:40'])->validate();
        $data['occurred_at'] = SceneTime::occurredAt($data['occurred_at']);

        return DB::transaction(function () use ($requestId, $expected, $data, $actor) {
            $request = SceneWorkflow::requireRow('scene_requests', $requestId);
            SceneWorkflow::version($request, $expected);
            if (in_array($request->state, ['closed', 'cancelled', 'converted'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '需求已关闭或进入履约，须走变更流程。', 409);
            }
            $revision = DB::table('scene_request_revisions')->where('request_id', $requestId)->where('revision_no', $request->revision_no)->first();
            $schema = SceneWorkflow::unpack($revision->schema_snapshot);
            $observed = $this->schemas->validate($data['facts'], $schema);
            $facts = array_replace(SceneWorkflow::unpack($revision->facts), $observed);
            $provenance = [];
            foreach ($facts as $key => $value) {
                $provenance[$key] = ['source' => 'customer', 'state' => $value === null ? 'unknown' : 'unverified'];
            }
            foreach ($data['verified_keys'] as $key) {
                if (! array_key_exists($key, $observed) || $observed[$key] === null || $observed[$key] === '') {
                    throw new SceneWorkflowException('SCENE_SURVEY_FIELD_UNKNOWN', '只能核实本次明确观察并填写的字段，未知不等于通过。');
                }
                $provenance[$key] = ['source' => 'site_survey', 'state' => 'verified', 'actor_id' => $actor, 'occurred_at' => $data['occurred_at']];
            }
            $evidence = [];
            foreach ($data['evidence_ids'] as $id) {
                $this->attachments->readForAdmin($requestId, (int) $id, $actor);
                $evidence[] = ['attachment_id' => (int) $id, 'sha256' => DB::table('scene_attachments')->where('id', $id)->value('sha256')];
            }
            $hash = SceneWorkflow::hash([$revision->input_hash, $facts, $provenance, $evidence, $data['note'], $data['occurred_at']]);
            $id = DB::table('scene_site_surveys')->insertGetId(['request_id' => $requestId, 'request_revision_no' => $request->revision_no,
                'facts' => SceneWorkflow::json($facts), 'provenance' => SceneWorkflow::json($provenance), 'evidence' => SceneWorkflow::json($evidence),
                'note' => $data['note'], 'input_hash' => $hash, 'occurred_at' => $data['occurred_at'], 'created_by' => $actor, 'created_at' => now('UTC')]);
            DB::table('scene_requests')->where('id', $requestId)->update(['row_version' => $expected + 1, 'state' => 'assessing', 'updated_at' => now('UTC')]);
            app(SceneQuoteLifecycleService::class)->invalidate($requestId, ['draft', 'in_review', 'approved', 'sent', 'returned'], 'site_survey_updated', 'admin', $actor);
            $this->flow->record('admin', $actor, 'scene_request', $requestId, $expected + 1, 'scene.survey.recorded', ['survey_id' => $id]);

            return ['id' => $id, 'request_row_version' => $expected + 1, 'state' => 'recorded', 'input_hash' => $hash];
        }, 3);
    }

    public function assertCurrent(object $survey, object $request): void
    {
        if ((int) $survey->request_id !== (int) $request->id || (int) $survey->request_revision_no !== (int) $request->revision_no) {
            throw new SceneWorkflowException('SCENE_SURVEY_STALE', '现场勘察不属于当前需求修订。', 409);
        }
        $latest = DB::table('scene_site_surveys')->where('request_id', $request->id)->where('request_revision_no', $request->revision_no)->orderByDesc('id')->value('id');
        if ((int) $latest !== (int) $survey->id) {
            throw new SceneWorkflowException('SCENE_SURVEY_STALE', '已有更新勘察，请重新评估。', 409);
        }
        $revision = DB::table('scene_request_revisions')->where('request_id', $request->id)->where('revision_no', $request->revision_no)->first();
        $hash = SceneWorkflow::hash([$revision->input_hash, SceneWorkflow::unpack($survey->facts), SceneWorkflow::unpack($survey->provenance), SceneWorkflow::unpack($survey->evidence), $survey->note, $survey->occurred_at]);
        if (! hash_equals($survey->input_hash, $hash)) {
            throw new SceneWorkflowException('SCENE_SURVEY_STALE', '勘察内容指纹不符，请重新核实。', 409);
        }
        foreach (SceneWorkflow::unpack($survey->evidence) as $proof) {
            $row = DB::table('scene_attachments')->where('id', $proof['attachment_id'])->where('request_id', $request->id)->where('state', 'clean')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))->first();
            if (! $row || ! hash_equals($row->sha256, $proof['sha256'])) {
                throw new SceneWorkflowException('SCENE_SURVEY_EVIDENCE_INVALID', '现场依据已失效，不能沿用原评估。', 409);
            }
            $this->attachments->readForAdmin((int) $request->id, (int) $row->id, (int) $survey->created_by);
        }
    }
}
