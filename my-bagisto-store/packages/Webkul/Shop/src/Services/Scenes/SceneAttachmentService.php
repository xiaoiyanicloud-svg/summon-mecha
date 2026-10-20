<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SceneAttachmentService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function upload(ScenePrincipal $owner, int $requestId, UploadedFile $file): array
    {
        return $this->store($requestId, $file, $owner, null);
    }

    public function uploadForAdmin(int $requestId, UploadedFile $file, int $actor): array
    {
        return $this->store($requestId, $file, null, $actor);
    }

    private function store(int $requestId, UploadedFile $file, ?ScenePrincipal $owner, ?int $admin): array
    {
        $this->flow->enabled('requests_enabled');
        $owner ? $owner->request($requestId) : SceneWorkflow::requireRow('scene_requests', $requestId, false);
        if (! $file->isValid() || $file->getSize() > 8 * 1024 * 1024 || $file->getSize() < 1) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_SIZE', '附件应为 1 字节至 8 MB。');
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_TYPE', '仅接受 PDF、PNG、JPEG 现场资料。');
        }
        if ($mime !== 'application/pdf' && ! @getimagesize($file->getRealPath())) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_TYPE', '图片文件内容无效。');
        }
        if ($mime === 'application/pdf' && file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_TYPE', 'PDF 文件内容无效。');
        }
        $scanner = config('scenes.attachment_scan_command');
        if (! is_array($scanner) || empty($scanner[0])) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_SCANNER_UNAVAILABLE', '附件扫描服务尚未就绪，请先提交文字需求。', 503);
        }
        $key = (string) Str::uuid();
        $dir = storage_path('app/private/scenes');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('私有附件目录不可写。');
        }
        $path = $dir.DIRECTORY_SEPARATOR.$key;
        $file->move($dir, $key);
        try {
            @chmod($path, 0600);
            $scan = new Process([...$scanner, $path]);
            $scan->setTimeout(30);
            $scan->run();
            if (! $scan->isSuccessful()) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_SCAN_REJECTED', '附件未通过安全扫描，请检查文件后重试。');
            }

            return DB::transaction(function () use ($owner, $admin, $requestId, $path, $key, $mime) {
                $request = $owner ? $owner->request($requestId, true) : SceneWorkflow::requireRow('scene_requests', $requestId);
                if (DB::table('scene_attachments')->where('request_id', $requestId)->where('state', 'clean')->count() >= 30) {
                    throw new SceneWorkflowException('SCENE_ATTACHMENT_LIMIT', '每份需求最多保留 30 份附件。');
                }
                $id = DB::table('scene_attachments')->insertGetId([
                    'request_id' => $requestId, 'storage_key' => $key, 'sha256' => hash_file('sha256', $path), 'mime' => $mime,
                    'size'       => filesize($path), 'state' => 'clean', 'expires_at' => now('UTC')->addDays(180), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
                $this->flow->record($owner ? ($owner->customerId ? 'customer' : 'guest') : 'admin', $owner?->customerId ?? $admin, 'scene_request', $requestId, $request->row_version, 'scene.attachment.added', ['attachment_id' => $id]);

                return ['id' => $id, 'mime' => $mime, 'size' => filesize($path), 'state' => 'clean'];
            });
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
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
        $row = DB::table('scene_attachments')->where('id', $attachmentId)->where('request_id', $requestId)->where('state', 'clean')->where('expires_at', '>', now('UTC'))->first();
        if (! $row || ! preg_match('/^[a-f0-9-]{36}$/D', $row->storage_key)) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_NOT_FOUND', '附件不存在、已到期或无权读取。', 404);
        }
        $path = storage_path('app/private/scenes/'.$row->storage_key);
        if (! is_file($path) || ! hash_equals($row->sha256, hash_file('sha256', $path))) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_UNAVAILABLE', '附件暂不可用，请联系服务人员。', 409);
        }

        return ['path' => $path, 'mime' => $row->mime];
    }
}
