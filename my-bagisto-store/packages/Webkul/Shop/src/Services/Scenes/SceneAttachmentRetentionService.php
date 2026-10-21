<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 先提交删除意图再删除文件；中断可重入，保留不可逆的引用/哈希/审计。 */
class SceneAttachmentRetentionService
{
    public function __construct(protected SceneWorkflow $flow, protected ScenePolicyService $policies) {}

    public function hold(int $requestId, int $id, array $input, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        $data = Validator::make($input, ['reference' => 'required|string|max:255', 'reason' => 'required|string|min:3|max:5000'])->validate();

        return DB::transaction(function () use ($requestId, $id, $data, $actor) {
            SceneWorkflow::requireRow('scene_requests', $requestId);
            $row = SceneWorkflow::requireRow('scene_attachments', $id);
            if ((int) $row->request_id !== $requestId || in_array($row->state, ['deletion_pending', 'deleted'], true)) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_NOT_FOUND', '资料不存在、已进入删除或不属于本需求。', 404);
            }
            $hold = DB::table('scene_attachment_holds')->insertGetId(['attachment_id' => $id, 'reference' => $data['reference'], 'reason' => $data['reason'], 'created_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_attachment', $id, 1, 'scene.attachment.held', ['hold_id' => $hold]);

            return ['id' => $hold, 'attachment_id' => $id, 'state' => 'held'];
        }, 3);
    }

    public function release(int $requestId, int $id, int $holdId, string $reason, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:5000'])->validate();

        return DB::transaction(function () use ($requestId, $id, $holdId, $actor, $reason) {
            SceneWorkflow::requireRow('scene_requests', $requestId);
            $attachment = SceneWorkflow::requireRow('scene_attachments', $id);
            $hold = SceneWorkflow::requireRow('scene_attachment_holds', $holdId);
            if ((int) $attachment->request_id !== $requestId || (int) $hold->attachment_id !== $id) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '保全记录不属于此资料。', 404);
            }
            if ($hold->released_at || (int) $hold->created_by === $actor) {
                throw new SceneWorkflowException('SCENE_HOLD_RELEASE_CONFLICT', '保全已解除或不能由原申请人自行解除。', 409);
            }
            DB::table('scene_attachment_holds')->where('id', $holdId)->update(['released_by' => $actor, 'released_at' => now('UTC'), 'updated_at' => now('UTC')]);
            // 原申请原因不覆盖，解除依据作为独立活动保留。
            DB::table('scene_activities')->insert(['request_id' => $requestId, 'kind' => 'attachment_hold_released', 'actor_type' => 'admin', 'actor_id' => $actor, 'note' => $reason, 'visibility' => 'internal', 'occurred_at' => now('UTC'), 'created_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_attachment', $id, 2, 'scene.attachment.hold-released', ['hold_id' => $holdId]);

            return ['id' => $holdId, 'state' => 'released'];
        }, 3);
    }

    public function revoke(int $requestId, int $id, string $reason, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:5000'])->validate();

        return DB::transaction(function () use ($requestId, $id, $reason, $actor) {
            SceneWorkflow::requireRow('scene_requests', $requestId);
            $row = SceneWorkflow::requireRow('scene_attachments', $id);
            if ((int) $row->request_id !== $requestId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '资料不属于本需求。', 404);
            }
            if ($row->revoked_at) {
                return ['id' => $id, 'state' => 'revoked'];
            }
            DB::table('scene_attachments')->where('id', $id)->update(['revoked_at' => now('UTC'), 'revocation_reason' => $reason, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_attachment', $id, 1, 'scene.attachment.revoked');

            return ['id' => $id, 'state' => 'revoked'];
        }, 3);
    }

    public function inspect(int $id): array
    {
        $row = SceneWorkflow::requireRow('scene_attachments', $id, false);
        $reason = $this->blockedReason($row);

        return ['id' => $id, 'state' => $row->state, 'eligible' => $reason === null, 'blocked_reason' => $reason];
    }

    public function purge(int $id, string $policyVersion): array
    {
        $this->flow->enabled('operations_enabled');
        $policy = $this->policies->effective('retention');
        if (! $policy || ! hash_equals($policy['version'], $policyVersion)) {
            throw new SceneWorkflowException('SCENE_RETENTION_POLICY_REQUIRED', '须指定当前已批准的资料保留政策版本。', 409);
        }
        $row = DB::transaction(function () use ($id, $policy) {
            $kind = DB::table('scene_policy_kinds')->where('kind', 'retention')->lockForUpdate()->first();
            $approved = DB::table('scene_policy_versions')->where('id', $policy['id'])->lockForUpdate()->first();
            if (! $approved || $approved->state !== 'approved' || (int) $kind?->active_version_id !== $policy['id']) {
                throw new SceneWorkflowException('SCENE_RETENTION_POLICY_REQUIRED', '保留政策已变更或撤销，须重新确认。', 409);
            }
            $ref = SceneWorkflow::requireRow('scene_attachments', $id, false);
            SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
            $row = SceneWorkflow::requireRow('scene_attachments', $id);
            if ($row->state === 'deleted') {
                return $row;
            }
            $blocked = $this->blockedReason($row);
            if ($blocked) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_PRESERVATION_REQUIRED', '资料仍需保留：'.$blocked, 409);
            }
            if ($row->state !== 'deletion_pending') {
                DB::table('scene_attachments')->where('id', $id)->update(['state' => 'deletion_pending', 'updated_at' => now('UTC')]);
                $this->flow->record('system', null, 'scene_attachment', $id, 1, 'scene.attachment.deletion-requested');
            }

            return $row;
        }, 3);
        if ($row->state === 'deleted') {
            return ['id' => $id, 'state' => 'deleted'];
        }
        if (! preg_match('/^[a-f0-9-]{36}$/D', $row->storage_key)) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_PATH_INVALID', '私有资料标识无效，禁止删除。', 409);
        }
        $path = storage_path('app/private/scenes/'.$row->storage_key);
        if (is_file($path)) {
            if (! hash_equals($row->sha256, hash_file('sha256', $path))) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_PRESERVATION_REQUIRED', '文件指纹异常，须保全核查。', 409);
            }
            if (! unlink($path)) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_DELETE_RETRY', '私有文件删除失败，记录保留待重试。', 503);
            }
        }

        // 若文件已删而最终事务失败，下一次重入继续完成，不伪造回滚文件删除。
        return DB::transaction(function () use ($id) {
            $row = SceneWorkflow::requireRow('scene_attachments', $id);
            if ($row->state !== 'deleted') {
                DB::table('scene_attachments')->where('id', $id)->update(['state' => 'deleted', 'deleted_at' => now('UTC'), 'updated_at' => now('UTC')]);
                $this->flow->record('system', null, 'scene_attachment', $id, 2, 'scene.attachment.deleted');
            }

            return ['id' => $id, 'state' => 'deleted'];
        }, 3);
    }

    private function blockedReason(object $row): ?string
    {
        if (! in_array($row->state, ['clean', 'deletion_pending'], true)) {
            return 'state_not_eligible';
        }
        if ($row->expires_at > now('UTC')) {
            return 'retention_not_expired';
        }
        if (DB::table('scene_attachment_holds')->where('attachment_id', $row->id)->whereNull('released_at')->exists()) {
            return 'explicit_hold';
        }
        $request = DB::table('scene_requests')->find($row->request_id);
        if (! $request || ! in_array($request->state, ['closed', 'cancelled'], true)) {
            return 'request_still_active';
        }
        $projects = DB::table('scene_projects')->where('request_id', $row->request_id);
        if ((clone $projects)->where(fn ($q) => $q->whereNotIn('state', ['closed', 'cancelled'])->orWhere('open_dispute', true))->exists()) {
            return 'project_or_dispute_open';
        }
        $ids = (clone $projects)->select('id');
        if (DB::table('scene_service_tickets')->whereIn('project_id', $ids)->where('state', '!=', 'resolved')->exists()) {
            return 'service_ticket_open';
        }
        if (DB::table('scene_offline_acceptances')->whereIn('project_id', (clone $projects)->select('id'))->where('state', 'pending_review')->exists()) {
            return 'offline_confirmation_pending';
        }

        return null;
    }
}
