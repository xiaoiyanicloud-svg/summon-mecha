<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** 扫描在业务事务外完成；提交不明时保留私有文件，避免误删已提交凭证。 */
class SceneAttachmentUploadService
{
    public function __construct(protected SceneWorkflow $flow, protected ScenePolicyService $policies) {}

    public function store(int $requestId, UploadedFile $file, ?ScenePrincipal $owner, ?int $admin, ?string $key): array
    {
        $this->flow->enabled('requests_enabled');
        $authorize = fn () => $owner ? $owner->request($requestId) : SceneWorkflow::requireRow('scene_requests', $requestId, false);
        $authorize();
        if (preg_match('/\.(?:php\d?|phtml|phar|exe|com|bat|cmd|msi|js|html?|svg|sh|ps1)(?:\.|$)/i', $file->getClientOriginalName())) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_TYPE', '附件名称包含不允许的可执行扩展，请使用原始 PDF 或图片。');
        }
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 8 * 1024 * 1024) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_SIZE', '附件应为 1 字节至 8 MB。');
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true)
            || ($mime !== 'application/pdf' && ! @getimagesize($file->getRealPath()))
            || ($mime === 'application/pdf' && file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-')) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_TYPE', '仅接受内容有效的 PDF、PNG、JPEG。');
        }
        $scanner = config('scenes.attachment_scan_command');
        if (! is_array($scanner) || empty($scanner[0])) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_SCANNER_UNAVAILABLE', '附件扫描服务尚未就绪，请先提交文字需求。', 503);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', (string) $key)) {
            throw new SceneWorkflowException('IDEMPOTENCY_KEY_REQUIRED', '上传须提供有效的幂等键。');
        }
        $principal = $owner ? $owner->scope() : 'admin:'.$admin;
        $operation = 'scene.attachment.upload:'.$requestId;
        $payload = ['sha256' => hash_file('sha256', $file->getRealPath()), 'size' => $file->getSize(), 'mime' => $mime];
        $existing = DB::table('business_idempotency_keys')->where('principal_scope', $principal)->where('operation', $operation)->where('key_hash', hash('sha256', $key))->first();
        if ($existing && $existing->result !== null) {
            if (! hash_equals($existing->request_hash, SceneWorkflow::hash($payload))) {
                throw new SceneWorkflowException('IDEMPOTENCY_CONFLICT', '同一上传标识的文件内容发生变化。', 409);
            }
            $authorize();

            return $this->availability(SceneWorkflow::unpack($existing->result), $requestId);
        }
        $policy = $this->policies->effective('retention');
        if (! $policy) {
            throw new SceneWorkflowException('SCENE_RETENTION_POLICY_REQUIRED', '私有资料保留与删除规则尚未批准，暂不能上传。', 503);
        }
        $storageKey = (string) Str::uuid();
        $dir = storage_path('app/private/scenes');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('私有附件目录不可写。');
        }
        $path = $dir.DIRECTORY_SEPARATOR.$storageKey;
        $file->move($dir, $storageKey);
        @chmod($path, 0600);
        $databaseAttempted = false;
        try {
            $scan = new Process([...$scanner, $path]);
            $scan->setTimeout(30);
            $scan->run();
            if (! $scan->isSuccessful() || ! is_file($path) || ! hash_equals($payload['sha256'], hash_file('sha256', $path))) {
                throw new SceneWorkflowException('SCENE_ATTACHMENT_SCAN_REJECTED', '附件未通过安全扫描，或扫描后内容发生变化。');
            }
            $databaseAttempted = true;
            $result = $this->flow->command($principal, $operation, $key, $payload, function () use ($owner, $admin, $requestId, $storageKey, $payload, $policy) {
                $request = $owner ? $owner->request($requestId, true) : SceneWorkflow::requireRow('scene_requests', $requestId);
                if (! DB::table('scene_policy_versions')->where('id', $policy['id'])->where('state', 'approved')->exists()) {
                    throw new SceneWorkflowException('SCENE_RETENTION_POLICY_REQUIRED', '资料保留规则已撤销，请重新确认。', 409);
                }
                if (DB::table('scene_attachments')->where('request_id', $requestId)->where('state', 'clean')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))->count() >= 30) {
                    throw new SceneWorkflowException('SCENE_ATTACHMENT_LIMIT', '每份需求最多保留 30 份可用附件。');
                }
                $id = DB::table('scene_attachments')->insertGetId(['request_id' => $requestId, 'storage_key' => $storageKey, 'sha256' => $payload['sha256'],
                    'mime' => $payload['mime'], 'size' => $payload['size'], 'state' => 'clean', 'retention_version' => $policy['version'],
                    'expires_at' => now('UTC')->addDays((int) $policy['content']['days']), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                $this->flow->record($owner ? ($owner->customerId ? 'customer' : 'guest') : 'admin', $owner?->customerId ?? $admin, 'scene_request', $requestId, (int) $request->row_version, 'scene.attachment.added', ['attachment_id' => $id]);

                return ['id' => $id, 'mime' => $payload['mime'], 'size' => $payload['size'], 'state' => 'clean'];
            }, $authorize);
            if (! DB::table('scene_attachments')->where('storage_key', $storageKey)->exists() && is_file($path)) {
                unlink($path);
            }

            return $this->availability($result, $requestId);
        } catch (\Throwable $e) {
            $referenced = false;
            if ($databaseAttempted) {
                try {
                    $referenced = DB::table('scene_attachments')->where('storage_key', $storageKey)->exists();
                } catch (\Throwable) {
                    $referenced = true;
                } // 提交状态不明，保留私有孤立文件供恢复核对。
            }
            if (! $referenced && is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
    }

    public function receipt(ScenePrincipal $owner, int $requestId, string $key): array
    {
        $owner->request($requestId);
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new SceneWorkflowException('IDEMPOTENCY_KEY_REQUIRED', '上传查询须提供原幂等键。');
        }
        $row = DB::table('business_idempotency_keys')->where('principal_scope', $owner->scope())->where('operation', 'scene.attachment.upload:'.$requestId)->where('key_hash', hash('sha256', $key))->first();
        if (! $row || $row->result === null) {
            return ['state' => 'not_confirmed', 'retry_with_original_file' => true];
        }

        return $this->availability(SceneWorkflow::unpack($row->result), $requestId);
    }

    private function availability(array $receipt, int $requestId): array
    {
        $row = DB::table('scene_attachments')->where('id', $receipt['id'])->where('request_id', $requestId)->first();
        if (! $row) {
            throw new SceneWorkflowException('SCENE_ATTACHMENT_NOT_FOUND', '上传回执对应的资料不可用。', 404);
        }
        $receipt['availability_state'] = $row->state === 'clean' ? ($row->revoked_at ? 'revoked' : ($row->expires_at <= now('UTC') ? 'expired' : 'available')) : $row->state;

        return $receipt;
    }
}
