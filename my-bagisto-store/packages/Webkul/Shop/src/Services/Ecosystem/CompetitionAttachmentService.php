<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionSubmissionAttachment;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-05 · 作品私有附件。
 *
 * 不复用 `ecosystem_media`：作品附件不是公开内容，不需要也不应进入
 * “公开审核后可由 /media 路由读取”的语义。这里独立存储并强制：
 *   · 只信 finfo 真实 MIME，扩展名必须匹配；
 *   · 大小/数量上限来自配置；
 *   · 存储名由服务端生成（entry/version/sha256），不接受客户端路径；
 *   · 下载必须重新核验队伍有效成员身份；
 *   · 已提交版本不可再上传附件。
 */
class CompetitionAttachmentService
{
    public function __construct(
        private readonly OperationLogger $logger,
    ) {}

    public function upload(
        CompetitionTeam $team,
        CompetitionEntry $entry,
        CompetitionSubmissionVersion $version,
        Customer $customer,
        UploadedFile $file,
    ): CompetitionSubmissionAttachment {
        if ((bool) config('ecosystem_competition.write_enabled', false) !== true) {
            throw CompetitionException::disabled();
        }

        if (! $entry->isActive()) {
            throw CompetitionException::entryNotActive();
        }

        if ((string) $version->status !== CompetitionSubmissionVersion::STATUS_DRAFT) {
            throw CompetitionException::submissionImmutable();
        }

        $submission = \Webkul\Shop\Models\CompetitionSubmission::query()->find($version->submission_id);

        if ($submission === null
            || (int) $submission->entry_id !== (int) $entry->getKey()
            || (int) $submission->track_id !== (int) $entry->track_id) {
            throw CompetitionException::attachmentInvalid('作品版本与参赛条目不匹配。');
        }

        if ((int) $version->created_by_customer_id !== (int) $customer->getKey()
            || (int) $team->captain_customer_id !== (int) $customer->getKey()) {
            throw CompetitionException::teamForbidden('只有队长可以上传作品附件。');
        }

        if (! $file->isValid()) {
            throw CompetitionException::attachmentInvalid('上传文件无效或上传失败。');
        }

        $size = (int) $file->getSize();
        $maxBytes = (int) config('ecosystem_competition.attachment.max_bytes', 20971520);

        if ($size <= 0 || $size > $maxBytes) {
            throw CompetitionException::attachmentInvalid(sprintf('附件大小必须在 1~%d 字节之间。', $maxBytes));
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw CompetitionException::attachmentInvalid('附件无法读取。');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = mb_strtolower((string) $finfo->file($realPath));
        $allowed = (array) config('ecosystem_competition.attachment.mimes', []);

        if (! array_key_exists($mime, $allowed)) {
            throw CompetitionException::attachmentInvalid(sprintf('不支持的附件类型「%s」。', $mime));
        }

        $originalName = $this->sanitizeName($file->getClientOriginalName());
        $extension = mb_strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, (array) $allowed[$mime], true)) {
            throw CompetitionException::attachmentInvalid(sprintf(
                '扩展名「%s」与真实 MIME「%s」不匹配。',
                $extension,
                $mime,
            ));
        }

        $sha256 = hash_file('sha256', $realPath);

        if ($sha256 === false) {
            throw CompetitionException::attachmentInvalid('无法计算附件指纹。');
        }

        $count = CompetitionSubmissionAttachment::query()
            ->where('version_id', $version->getKey())
            ->where('status', CompetitionSubmissionAttachment::STATUS_ACTIVE)
            ->count();

        if ($count >= (int) config('ecosystem_competition.attachment.max_per_version', 10)) {
            throw CompetitionException::attachmentInvalid('该版本附件数量已达上限。');
        }

        // 同版本同内容重复上传：返回既有记录，不重复占位。
        $existing = CompetitionSubmissionAttachment::query()
            ->where('version_id', $version->getKey())
            ->where('sha256', $sha256)
            ->where('status', CompetitionSubmissionAttachment::STATUS_ACTIVE)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $disk = (string) config('ecosystem_competition.attachment.disk', 'local');
        $directory = trim((string) config('ecosystem_competition.attachment.directory', 'ecosystem/competition-submissions'), '/');
        $storageKey = sprintf(
            '%s/%d/%d/%s.%s',
            $directory,
            (int) $entry->getKey(),
            (int) $version->getKey(),
            $sha256,
            $extension,
        );

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw CompetitionException::attachmentInvalid('附件无法读取。');
        }

        try {
            $stored = Storage::disk($disk)->put($storageKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw CompetitionException::attachmentInvalid('附件写入私有存储失败。');
        }

        try {
            return DB::transaction(function () use (
                $entry,
                $version,
                $customer,
                $originalName,
                $storageKey,
                $disk,
                $mime,
                $size,
                $sha256,
            ): CompetitionSubmissionAttachment {
                $attachment = new CompetitionSubmissionAttachment();
                $attachment->fill([
                    'version_id'             => (int) $version->getKey(),
                    'entry_id'               => (int) $entry->getKey(),
                    'original_name'          => $originalName,
                    'storage_key'            => $storageKey,
                    'mime'                   => $mime,
                    'byte_size'              => $size,
                    'sha256'                 => $sha256,
                    'created_by_customer_id' => (int) $customer->getKey(),
                    'status'                 => CompetitionSubmissionAttachment::STATUS_ACTIVE,
                ]);
                $attachment->save();

                $this->logger->logOrFail(
                    'customer',
                    (int) $customer->getKey(),
                    'competition.submission.attachment_uploaded',
                    'competition_submission_attachment',
                    (int) $attachment->getKey(),
                    [
                        'entry_id'   => (int) $entry->getKey(),
                        'version_id' => (int) $version->getKey(),
                        'mime'       => $mime,
                        'byte_size'  => $size,
                        'sha256'     => $sha256,
                    ],
                );

                return $attachment->refresh();
            });
        } catch (\Throwable $exception) {
            // DB 失败时清理刚写入的文件；同路径若已被引用则保留。
            if (! CompetitionSubmissionAttachment::query()->where('storage_key', $storageKey)->exists()) {
                Storage::disk($disk)->delete($storageKey);
            }

            throw $exception;
        }
    }

    /**
     * @return array{stream: resource, mime: string, name: string, size: int}
     */
    public function openForMember(Customer $customer, int $attachmentId): array
    {
        $attachment = CompetitionSubmissionAttachment::query()
            ->whereKey($attachmentId)
            ->where('status', CompetitionSubmissionAttachment::STATUS_ACTIVE)
            ->first();

        if ($attachment === null) {
            throw CompetitionException::attachmentNotFound();
        }

        $entry = CompetitionEntry::query()->find($attachment->entry_id);

        if ($entry === null) {
            throw CompetitionException::attachmentNotFound();
        }

        $isMember = CompetitionTeamMember::query()
            ->where('team_id', $entry->team_id)
            ->where('customer_id', (int) $customer->getKey())
            ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
            ->exists();

        if (! $isMember) {
            // 跨队访问与不存在统一表现，避免探测附件。
            throw CompetitionException::attachmentNotFound();
        }

        $disk = (string) config('ecosystem_competition.attachment.disk', 'local');
        $storage = Storage::disk($disk);

        if (! $storage->exists($attachment->storage_key)) {
            throw CompetitionException::attachmentNotFound('附件文件缺失，请联系运营。');
        }

        $stream = $storage->readStream($attachment->storage_key);

        if (! is_resource($stream)) {
            throw CompetitionException::attachmentNotFound('附件读取失败。');
        }

        return [
            'stream' => $stream,
            'mime'   => (string) $attachment->mime,
            'name'   => (string) $attachment->original_name,
            'size'   => (int) $attachment->byte_size,
        ];
    }

    private function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $name) ?? 'attachment';
        $name = trim($name, " .\t\n\r\0\x0B");

        if ($name === '' || mb_strlen($name) > 160) {
            $name = mb_substr($name !== '' ? $name : 'attachment', 0, 160);
        }

        return $name;
    }
}
