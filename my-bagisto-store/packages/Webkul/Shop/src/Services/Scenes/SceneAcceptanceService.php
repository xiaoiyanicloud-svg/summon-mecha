<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 在线确认与线下凭证共用逐项判定；线下录入在独立复核前不产生验收事实。 */
class SceneAcceptanceService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneAttachmentService $attachments) {}

    public function online(ScenePrincipal $owner, int $projectId, int $expected, string $key, array $input): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = $this->validateItems($input);

        return $this->flow->command($owner->scope(), 'scene.project.accept:'.$projectId, $key, [$expected, $data], function () use ($owner, $projectId, $expected, $data) {
            $ref = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $ref->request_id, true);
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            $items = $this->prepareItems($project, $data['items']);
            $occurred = now('UTC')->format('Y-m-d H:i:s');
            $this->assertConfirmationTime($project, $occurred);

            return $this->append($project, $items, 'customer_online', $owner->scope(), $occurred, $owner->customerId ? 'customer' : 'guest', $owner->customerId);
        }, function () use ($owner, $projectId) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $project->request_id);
        });
    }

    public function recordOffline(int $projectId, int $expected, string $key, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        // HTML 表单无法发送空数组；线下原件必填，逐项附加附件允许为空。
        if (isset($input['items']) && is_array($input['items'])) {
            $input['items'] = array_map(fn ($item) => is_array($item) ? $item + ['evidence_ids' => []] : $item, $input['items']);
        }
        $data = Validator::make($input, [
            'signatory_name' => 'required|string|max:100', 'authority_basis' => 'required|string|min:3|max:2000',
            'medium'         => 'required|in:signed_document,email,in_person_record', 'authority_confirmed' => 'required|accepted',
            'occurred_at'    => 'required|string|max:40', 'evidence_ids' => 'required|array|min:1|max:30',
            'evidence_ids.*' => 'required|integer|distinct|min:1',
        ])->validate() + $this->validateItems($input);
        $occurred = SceneTime::occurredAt($data['occurred_at']);

        return $this->flow->command('admin:'.$actor, 'scene.project.offline-record:'.$projectId, $key, [$expected, $data], function () use ($projectId, $expected, $data, $occurred, $actor) {
            $project = $this->lockProject($projectId);
            SceneWorkflow::version($project, $expected);
            $items = $this->prepareItems($project, $data['items']);
            $this->assertConfirmationTime($project, $occurred);
            if (DB::table('scene_offline_acceptances')->where('project_id', $projectId)->where('state', 'pending_review')->exists()) {
                throw new SceneWorkflowException('SCENE_OFFLINE_REVIEW_PENDING', '本项目已有待复核线下确认，请先处理原记录。', 409);
            }
            $evidence = $this->evidence((int) $project->request_id, $data['evidence_ids']);
            $this->verifyFiles($project, $evidence, $items, $actor);
            $attestation = array_intersect_key($data, array_flip(['signatory_name', 'authority_basis', 'medium', 'authority_confirmed']));
            $template = $this->template($project);
            $hash = SceneWorkflow::hash([$template, $items, $evidence, $attestation, $occurred]);
            $id = DB::table('scene_offline_acceptances')->insertGetId([
                'project_id'            => $projectId, 'project_version' => $expected + 1, 'template_hash' => SceneWorkflow::hash($template),
                'content_hash'          => $hash, 'items' => SceneWorkflow::json($items), 'evidence' => SceneWorkflow::json($evidence),
                'encrypted_attestation' => Crypt::encryptString(SceneWorkflow::json($attestation)), 'occurred_at' => $occurred,
                'recorded_by'           => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            DB::table('scene_projects')->where('id', $projectId)->update(['row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'offline-acceptance:'.$id, 'request_id' => $project->request_id,
                'kind'                                                       => 'offline_acceptance_review', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.project.offline-acceptance.recorded', ['confirmation_id' => $id]);

            return ['id' => $id, 'state' => 'pending_review', 'row_version' => 1, 'project_row_version' => $expected + 1, 'effective' => false];
        });
    }

    public function reviewOffline(int $projectId, int $id, int $expected, int $recordVersion, string $key, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, ['decision' => 'required|in:approve,reject', 'note' => 'required|string|min:3|max:2000'])->validate();

        return $this->flow->command('admin:'.$actor, 'scene.project.offline-review:'.$id, $key, [$projectId, $expected, $recordVersion, $data], function () use ($projectId, $id, $expected, $recordVersion, $data, $actor) {
            $project = $this->lockProject($projectId);
            SceneWorkflow::version($project, $expected);
            $record = SceneWorkflow::requireRow('scene_offline_acceptances', $id);
            if ((int) $record->project_id !== $projectId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '确认记录不存在。', 404);
            }
            SceneWorkflow::version($record, $recordVersion);
            if ($record->state !== 'pending_review' || (int) $record->recorded_by === $actor) {
                throw new SceneWorkflowException('SCENE_OFFLINE_REVIEW_CONFLICT', '记录已处理或不能由录入人自行复核。', 409);
            }
            if ($data['decision'] === 'approve') {
                if ((int) $record->project_version !== $expected || ! hash_equals($record->template_hash, SceneWorkflow::hash($this->template($project)))) {
                    throw new SceneWorkflowException('SCENE_OFFLINE_ACCEPTANCE_STALE', '项目在代录后已变化，请退回并按最新事实重新录入。', 409);
                }
                $items = SceneWorkflow::unpack($record->items);
                $evidence = SceneWorkflow::unpack($record->evidence);
                $attestation = SceneWorkflow::unpack(Crypt::decryptString($record->encrypted_attestation));
                if (! hash_equals($record->content_hash, SceneWorkflow::hash([$this->template($project), $items, $evidence, $attestation, $record->occurred_at]))) {
                    throw new SceneWorkflowException('SCENE_OFFLINE_EVIDENCE_CHANGED', '线下确认内容指纹发生变化，不能复核通过。', 409);
                }
                $this->assertConfirmationTime($project, $record->occurred_at);
                $this->verifyFiles($project, $evidence, $items, $actor);
                $result = $this->append($project, $items, 'customer_offline_recorded', 'admin:'.$record->recorded_by, $record->occurred_at, 'admin', $actor, $id);
            } else {
                // 项目已变化时仍可退回旧凭证；退回不覆盖任何已发生验收。
                DB::table('scene_projects')->where('id', $projectId)->update(['row_version' => $expected + 1, 'updated_at' => now('UTC')]);
                $result = ['id' => $projectId, 'state' => $project->state, 'row_version' => $expected + 1];
            }
            $state = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('scene_offline_acceptances')->where('id', $id)->update(['state' => $state, 'row_version' => $recordVersion + 1,
                'reviewed_by'                                                         => $actor, 'reviewed_at' => now('UTC'), 'review_note' => $data['note'], 'updated_at' => now('UTC')]);
            DB::table('scene_operation_todos')->where('dedupe_key', 'offline-acceptance:'.$id)->where('state', 'open')->update([
                'state' => 'resolved', 'resolution_code' => 'offline_confirmation_'.$state, 'updated_at' => now('UTC'),
            ]);
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.project.offline-acceptance.reviewed', ['confirmation_id' => $id]);

            return ['id' => $id, 'state' => $state, 'row_version' => $recordVersion + 1, 'project_state' => $result['state'], 'project_row_version' => $expected + 1, 'effective' => $state === 'approved'];
        });
    }

    private function lockProject(int $id): object
    {
        $ref = SceneWorkflow::requireRow('scene_projects', $id, false);
        SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);

        return SceneWorkflow::requireRow('scene_projects', $id);
    }

    private function validateItems(array $input): array
    {
        return Validator::make($input, [
            'items'                  => 'required|array|min:1|max:50', 'items.*' => 'array:key,result,note,evidence_ids',
            'items.*.key'            => 'required|string|max:80|distinct', 'items.*.result' => 'required|in:pass,fail',
            'items.*.note'           => 'nullable|string|max:5000', 'items.*.evidence_ids' => 'present|array|max:30',
            'items.*.evidence_ids.*' => 'required|integer|distinct|min:1',
        ])->validate();
    }

    private function template(object $project): array
    {
        $template = SceneWorkflow::unpack($project->snapshot)['acceptance_template'] ?? [];
        if (! $template || count(array_unique(array_column($template, 'key'))) !== count($template)) {
            throw new SceneWorkflowException('SCENE_ACCEPTANCE_TEMPLATE_MISSING', '项目未冻结有效验收标准，不能通过验收。', 409);
        }

        return $template;
    }

    private function assertAwaiting(object $project): void
    {
        if (! in_array($project->state, ['awaiting_acceptance', 'rectification'], true)) {
            throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目尚未进入客户验收。', 409);
        }
    }

    private function assertConfirmationTime(object $project, string $occurred): void
    {
        if (DB::table('scene_project_tasks')->where('project_id', $project->id)->where('state', 'done')
            ->where(fn ($q) => $q->whereNull('occurred_at')->orWhere('occurred_at', '>', $occurred))->exists()) {
            throw new SceneWorkflowException('SCENE_ACCEPTANCE_TIME_CONFLICT', '客户确认早于已记录交付任务，或交付任务实际时间未核实。', 409);
        }
    }

    private function prepareItems(object $project, array $items): array
    {
        $this->assertAwaiting($project);
        $defined = array_column($this->template($project), null, 'key');
        foreach ($items as &$item) {
            if (! isset($defined[$item['key']])) {
                throw new SceneWorkflowException('SCENE_ACCEPTANCE_ITEM_UNKNOWN', '验收项不属于冻结标准。');
            }
            $item['evidence'] = $this->evidence((int) $project->request_id, $item['evidence_ids']);
            unset($item['evidence_ids']);
        }

        return $items;
    }

    private function evidence(int $requestId, array $ids): array
    {
        $rows = DB::table('scene_attachments')->where('request_id', $requestId)->whereIn('id', $ids)->where('state', 'clean')->where('expires_at', '>', now('UTC'))->get(['id', 'sha256']);
        if ($rows->count() !== count($ids)) {
            throw new SceneWorkflowException('SCENE_EVIDENCE_INVALID', '证据附件未扫描通过、已过期或不属于本项目需求。');
        }

        return $rows->map(fn ($row) => ['attachment_id' => (int) $row->id, 'sha256' => $row->sha256])->all();
    }

    private function verifyFiles(object $project, array $evidence, array $items, int $actor): void
    {
        $all = $evidence;
        foreach ($items as $item) {
            $all = array_merge($all, $item['evidence']);
        }
        foreach (array_column($all, null, 'attachment_id') as $id => $snapshot) {
            $current = $this->evidence((int) $project->request_id, [(int) $id]);
            if (! hash_equals($snapshot['sha256'], $current[0]['sha256'])) {
                throw new SceneWorkflowException('SCENE_OFFLINE_EVIDENCE_CHANGED', '凭证附件指纹已变化，请重新核实。', 409);
            }
            // 同时核验私有文件实际存在且内容吻合；仅读 clean 数据行不足以代表凭证可用。
            $this->attachments->readForAdmin((int) $project->request_id, (int) $id, $actor);
        }
    }

    private function append(object $project, array $items, string $method, string $scope, string $occurred, string $actorType, ?int $actor, ?int $offlineId = null): array
    {
        $this->assertAwaiting($project);
        $defined = array_column($this->template($project), null, 'key');
        foreach ($items as $item) {
            $last = DB::table('scene_acceptance_records')->where('project_id', $project->id)->where('item_key', $item['key'])->orderByDesc('attempt')->lockForUpdate()->first();
            if ($last && (($last->occurred_at && $last->occurred_at > $occurred) || (! $last->occurred_at && $offlineId))) {
                throw new SceneWorkflowException('SCENE_ACCEPTANCE_TIME_CONFLICT', '本次确认早于已有验收，或历史实际时间未核实，不能覆盖最新结果。', 409);
            }
            $attempt = $last ? (int) $last->attempt + 1 : 1;
            DB::table('scene_acceptance_records')->insert(['project_id' => $project->id, 'item_key' => $item['key'], 'attempt' => $attempt,
                'result'                                                => $item['result'], 'method' => $method, 'principal_scope' => $scope, 'occurred_at' => $occurred, 'offline_acceptance_id' => $offlineId,
                'note'                                                  => $item['note'] ?? null, 'evidence' => SceneWorkflow::json($item['evidence']), 'created_at' => now('UTC')]);
            if ($item['result'] === 'fail') {
                DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'rectify:'.$project->id.':'.$item['key'].':'.$attempt,
                    'request_id'                                                 => $project->request_id, 'kind' => 'acceptance_rectification', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            } else {
                $keys = DB::table('scene_acceptance_records')->where('project_id', $project->id)->where('item_key', $item['key'])->where('result', 'fail')->pluck('attempt')
                    ->map(fn ($number) => 'rectify:'.$project->id.':'.$item['key'].':'.$number)->all();
                DB::table('scene_operation_todos')->whereIn('dedupe_key', $keys)->where('state', 'open')->update([
                    'state' => 'resolved', 'resolution_code' => $offlineId ? 'offline_retest_verified' : 'customer_retest_passed', 'updated_at' => now('UTC'),
                ]);
            }
        }
        $passed = true;
        $failed = false;
        foreach ($defined as $key => $definition) {
            $last = DB::table('scene_acceptance_records')->where('project_id', $project->id)->where('item_key', $key)->orderByDesc('attempt')->first();
            $failed = $failed || $last?->result === 'fail';
            if (($definition['required'] ?? true) && $last?->result !== 'pass') {
                $passed = false;
            }
        }
        $state = $failed ? 'rectification' : ($passed ? 'accepted' : 'awaiting_acceptance');
        $version = (int) $project->row_version + 1;
        DB::table('scene_projects')->where('id', $project->id)->update(['state' => $state, 'row_version' => $version, 'updated_at' => now('UTC')]);
        $this->flow->record($actorType, $actor, 'scene_project', (int) $project->id, $version, 'scene.project.acceptance.recorded', $offlineId ? ['confirmation_id' => $offlineId] : []);

        return ['id' => (int) $project->id, 'row_version' => $version, 'state' => $state];
    }
}
