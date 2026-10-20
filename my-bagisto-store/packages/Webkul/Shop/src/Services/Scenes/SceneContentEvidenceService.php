<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SceneContentEvidenceService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function create(int $sceneId, array $input, int $actor): array
    {
        $this->flow->enabled('publication_v2');
        $data = Validator::make($input, [
            'kind' => 'required|in:case,metric', 'title' => 'required|string|max:255', 'public_summary' => 'required|string|max:5000',
            'scope' => 'required|string|min:3|max:10000', 'source_reference' => 'required|string|max:2000',
            'source_hash' => 'required|regex:/^[a-f0-9]{64}$/D', 'authorization_reference' => 'required|string|max:255',
            'sample_size' => 'required_if:kind,metric|nullable|integer|min:1|max:1000000000',
            'metric_value' => 'required_if:kind,metric|nullable|regex:/^-?\d{1,12}(?:\.\d{1,4})?$/D',
            'metric_unit' => 'required_if:kind,metric|nullable|string|max:80',
            'observed_from' => 'required|string|max:40', 'observed_until' => 'required|string|max:40',
            'expires_at' => 'required|date|after:now',
        ])->validate();
        $data['observed_from'] = SceneTime::occurredAt($data['observed_from']);
        $data['observed_until'] = SceneTime::occurredAt($data['observed_until']);
        $data['expires_at'] = SceneWorkflow::utc($data['expires_at']);
        if ($data['observed_until'] < $data['observed_from']) {
            throw new SceneWorkflowException('SCENE_EVIDENCE_PERIOD_INVALID', '观察结束时间不能早于开始时间。');
        }

        return DB::transaction(function () use ($sceneId, $data, $actor) {
            SceneWorkflow::requireRow('scenes', $sceneId);
            $id = DB::table('scene_content_evidence')->insertGetId($data + ['scene_id' => $sceneId, 'created_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_content_evidence', $id, 1, 'scene.content-evidence.created');

            return ['id' => $id, 'row_version' => 1, 'state' => 'draft'];
        }, 3);
    }

    public function decide(int $id, int $expected, string $action, string $reason, int $actor): array
    {
        $this->flow->enabled('publication_v2');
        Validator::make(['reason' => $reason, 'action' => $action], ['reason' => 'required|string|min:3|max:2000', 'action' => 'required|in:approve,revoke'])->validate();

        return DB::transaction(function () use ($id, $expected, $action, $reason, $actor) {
            $ref = SceneWorkflow::requireRow('scene_content_evidence', $id, false);
            SceneWorkflow::requireRow('scenes', (int) $ref->scene_id);
            $row = SceneWorkflow::requireRow('scene_content_evidence', $id);
            SceneWorkflow::version($row, $expected);
            if ($action === 'approve') {
                if ($row->state !== 'draft' || $row->expires_at <= now('UTC')) {
                    throw new SceneWorkflowException('SCENE_EVIDENCE_UNAVAILABLE', '依据已处理或已到期，不能批准。', 409);
                }
                if ((int) $row->created_by === $actor) {
                    throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '依据录入人不能自行批准。', 403);
                }
                $patch = ['state' => 'approved', 'approved_by' => $actor, 'approved_at' => now('UTC'), 'review_note' => $reason];
            } else {
                if ($row->state === 'revoked') {
                    throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '此依据已经撤销。', 409);
                }
                $patch = ['state' => 'revoked', 'revoked_at' => now('UTC'), 'revocation_reason' => $reason];
            }
            DB::table('scene_content_evidence')->where('id', $id)->update($patch + ['row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_content_evidence', $id, $expected + 1, 'scene.content-evidence.'.$action);

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => $patch['state']];
        }, 3);
    }

    public function freeze(int $sceneId, array $ids): array
    {
        $result = [];
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $row = SceneWorkflow::requireRow('scene_content_evidence', (int) $id);
            if ((int) $row->scene_id !== $sceneId || ! $this->available($row)) {
                throw new SceneWorkflowException('SCENE_CONTENT_EVIDENCE_INVALID', '案例或指标依据未独立批准、已撤销、已到期或不属于此场景。', 409);
            }
            $result[] = ['id' => (int) $row->id, 'source_hash' => $row->source_hash, 'public' => $this->publicDto($row)];
        }

        return $result;
    }

    /** 撤销/到期立即影响读取，不依赖缓存扫描或旧事件投递顺序。 */
    public function visible(array $references): array
    {
        $out = [];
        foreach ($references as $reference) {
            $row = DB::table('scene_content_evidence')->where('id', $reference['id'])->first();
            if ($row && $this->available($row) && hash_equals($row->source_hash, $reference['source_hash'])) {
                $out[] = $reference['public'];
            }
        }

        return $out;
    }

    private function available(object $row): bool
    {
        return $row->state === 'approved' && $row->approved_at && ! $row->revoked_at && $row->expires_at > now('UTC');
    }

    private function publicDto(object $row): array
    {
        return ['id' => (int) $row->id, 'kind' => $row->kind, 'title' => $row->title, 'summary' => $row->public_summary, 'applicability' => $row->scope,
            'sample_size' => $row->sample_size === null ? null : (int) $row->sample_size, 'value' => $row->metric_value, 'unit' => $row->metric_unit,
            'observed_from' => $row->observed_from, 'observed_until' => $row->observed_until, 'expires_at' => $row->expires_at,
            'source_type' => 'authorized_reviewed_record', 'not_a_performance_guarantee' => true];
    }
}
