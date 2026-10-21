<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SceneAttachmentService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function upload(ScenePrincipal $owner, int $requestId, UploadedFile $file, ?string $key = null): array
    {
        return app(SceneAttachmentUploadService::class)->store($requestId, $file, $owner, null, $key);
    }

    public function uploadForAdmin(int $requestId, UploadedFile $file, int $actor, ?string $key = null): array
    {
        return app(SceneAttachmentUploadService::class)->store($requestId, $file, null, $actor, $key);
    }

    public function read(ScenePrincipal $owner, int $requestId, int $attachmentId): array
    {
        $owner->request($requestId);

        return $this->readPrivate($requestId, $attachmentId);
    }

    public function readForAdmin(int $requestId, int $attachmentId, int $actor): array
    {
        return DB::transaction(function () use ($requestId, $attachmentId, $actor) {
            SceneWorkflow::requireRow('scene_requests', $requestId, false);
            $result = $this->readPrivate($requestId, $attachmentId);
            app(\Webkul\Shop\Services\Ops\OperationLogger::class)->logOrFail('admin', $actor, 'scene.attachment.viewed', 'scene_request', $requestId, ['attachment_id' => $attachmentId]);

            return $result;
        });
    }

    private function readPrivate(int $requestId, int $attachmentId): array
    {
        $row = DB::table('scene_attachments')->where('id', $attachmentId)->where('request_id', $requestId)->where('state', 'clean')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))->first();
        if (! $row || ! preg_match('/^[a-f0-9-]{36}$/D', $row->storage_key)) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_NOT_FOUND', '附件不存在、已到期或无权读取。', 404);
        }
        $path = storage_path('app/private/scenes/'.$row->storage_key);
        if (! is_file($path) || ! hash_equals($row->sha256, hash_file('sha256', $path))) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_UNAVAILABLE', '附件暂不可用，请联系服务人员。', 409);
        }

        return ['path' => $path, 'mime' => $row->mime];
    }

    public function readPreservedForAdmin(int $requestId, int $attachmentId, string $reason, int $actor): array
    {
        \Illuminate\Support\Facades\Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000'])->validate();

        return DB::transaction(function () use ($requestId, $attachmentId, $reason, $actor) {
            SceneWorkflow::requireRow('scene_requests', $requestId);
            $row = DB::table('scene_attachments')->where('id', $attachmentId)->where('request_id', $requestId)->where('state', 'clean')->lockForUpdate()->first();
            if (! $row || ! DB::table('scene_attachment_holds')->where('attachment_id', $attachmentId)->whereNull('released_at')->exists()
                || ! preg_match('/^[a-f0-9-]{36}$/D', $row->storage_key)) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_NOT_FOUND', '无有效保全依据或资料已进入清理。', 404);
            }
            $path = storage_path('app/private/scenes/'.$row->storage_key);
            if (! is_file($path) || ! hash_equals($row->sha256, hash_file('sha256', $path))) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_UNAVAILABLE', '保全原件缺失或指纹异常。', 409);
            }
            app(\Webkul\Shop\Services\Ops\OperationLogger::class)->logOrFail('admin', $actor, 'scene.attachment.preserved-viewed', 'scene_request', $requestId, ['attachment_id' => $attachmentId]);
            DB::table('scene_activities')->insert(['request_id' => $requestId, 'kind' => 'preserved_attachment_viewed', 'actor_type' => 'admin', 'actor_id' => $actor, 'note' => $reason, 'visibility' => 'internal', 'occurred_at' => now('UTC'), 'created_at' => now('UTC')]);

            return ['path' => $path, 'mime' => $row->mime];
        }, 3);
    }
}
