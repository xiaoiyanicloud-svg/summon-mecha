<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EventRuleVersion;

/**
 * 媒体上传与审核写入口（ADR-0001 §4.9 / G1-D06 / G1-06）。
 *
 * 上传校验顺序（任何一步失败都不落库、不留下半份文件）：
 *   1. 后台账号必须存在；
 *   2. 文件确实可读且字节数 > 0；
 *   3. **真实 MIME** 用 finfo 解码，只接受 image/jpeg、image/png、
 *      image/webp、application/pdf —— 客户端 Content-Type 不算数；
 *   4. 大小：图片 5MB、文档 10MB；
 *   5. 扩展名必须与真实 MIME 匹配（伪装成 .png 的 PHP / SVG 在这里被挡）；
 *   6. 图片再用 getimagesize 解码，类型必须与 finfo 一致，单边 <= 8000px；
 *      PDF 必须带 `%PDF-` 头部；
 *   7. sha256 内容哈希全局唯一；
 *   8. 落私有盘 `ecosystem/media/{sha256 前 2 位}/{sha256}.{ext}`，
 *      路径完全由服务端生成，不接受调用方路径 / 文件名；
 *   9. DB 写入失败时删除刚写入的文件（若该路径没有被别的记录引用）。
 *
 * 审核状态机：
 *   pending -> approved（approve）
 *   pending -> rejected（reject）
 *   pending | approved -> quarantined（quarantine）
 * 已拒绝 / 已隔离的媒体不能原地恢复为 approved；换文件必须新建媒体。
 *
 * 规则文件绑定（attachRuleFile）只改 `event_rule_versions.file_media_id`
 * 并重算规则版本指纹；正文 `body_html` 的净化属于 G1-07，本类不触碰。
 *
 * 本类**不提供任何公开 URL**；公开读取由 MediaAccessService 门禁 + controller
 * 从私有盘流式输出。
 */
class MediaService
{
    public function __construct(
        private readonly ContentFingerprint $fingerprints,
    ) {}
    /**
     * 上传媒体并落为 `status=pending`。
     *
     * @throws MediaValidationException 文件类型 / 大小 / 尺寸 / 哈希不合法
     * @throws InvalidArgumentException 后台账号无效
     */
    public function upload(UploadedFile $file, int $adminId): EcosystemMedia
    {
        $this->assertAdminExists($adminId);
        $this->assertUploadIsReadable($file);

        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw new MediaValidationException('上传文件无法读取。');
        }

        $byteSize = (int) $file->getSize();

        if ($byteSize <= 0) {
            throw new MediaValidationException('上传文件不能为空。');
        }

        $mime = $this->detectMime($realPath);

        if (! in_array($mime, $this->allowedMimes(), true)) {
            throw new MediaValidationException(sprintf(
                '不支持的媒体类型「%s」；只允许 %s。',
                $mime !== '' ? $mime : '(未知)',
                implode(' / ', $this->allowedMimes()),
            ));
        }

        $kind = $this->kindOf($mime);
        $maxBytes = $this->maxBytesFor($kind);

        if ($byteSize > $maxBytes) {
            throw new MediaValidationException(sprintf(
                '文件大小 %d 字节超过 %s 类型上限 %d 字节。',
                $byteSize,
                $kind === 'image' ? '图片' : '文档',
                $maxBytes,
            ));
        }

        $originalName = $this->sanitizeOriginalName($file->getClientOriginalName());
        $extension = mb_strtolower((string) pathinfo($originalName ?? '', PATHINFO_EXTENSION));

        if (! in_array($extension, $this->allowedExtensions($mime), true)) {
            throw new MediaValidationException(sprintf(
                '扩展名「%s」与真实 MIME「%s」不匹配；允许：%s。',
                $extension !== '' ? $extension : '(无)',
                $mime,
                implode(' / ', $this->allowedExtensions($mime)),
            ));
        }

        $width = null;
        $height = null;

        if ($kind === 'image') {
            [$width, $height] = $this->assertImage($realPath, $mime);
        } else {
            $this->assertPdf($realPath);
        }

        $sha256 = hash_file('sha256', $realPath);

        if ($sha256 === false) {
            throw new MediaValidationException('无法计算文件 sha256。');
        }

        if (EcosystemMedia::query()->where('sha256', $sha256)->exists()) {
            throw new MediaValidationException('相同内容的媒体已存在，不得重复上传或原位替换。');
        }

        $disk = $this->disk();
        $storageKey = sprintf(
            'ecosystem/media/%s/%s.%s',
            substr($sha256, 0, 2),
            $sha256,
            $this->canonicalExtension($mime),
        );

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new MediaValidationException('上传文件无法读取。');
        }

        try {
            $stored = Storage::disk($disk)->put($storageKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw new MediaValidationException('媒体写入私有存储失败。');
        }

        try {
            return EcosystemMedia::create([
                'disk'          => $disk,
                'storage_key'   => $storageKey,
                'original_name' => $originalName,
                'mime'          => $mime,
                'byte_size'     => $byteSize,
                'width'         => $width,
                'height'        => $height,
                'sha256'        => $sha256,
                'status'        => 'pending',
                'uploaded_by'   => $adminId,
            ]);
        } catch (\Throwable $exception) {
            // DB 失败时不留孤儿文件；但内容寻址路径可能已被并发写入的
            // 同哈希记录引用，删除前必须确认没有别的记录指向它。
            $this->deleteStoredFileIfOrphan($disk, $storageKey);

            if ($this->isDuplicateHash($exception)) {
                throw new MediaValidationException('相同内容的媒体已存在，不得重复上传或原位替换。');
            }

            throw $exception;
        }
    }

    /**
     * pending -> approved。
     *
     * @throws MediaTransitionException 当前状态不允许批准
     */
    public function approve(EcosystemMedia $media, int $adminId): EcosystemMedia
    {
        return $this->transition($media, $adminId, 'approved', ['pending']);
    }

    /**
     * pending -> rejected。
     *
     * @throws MediaTransitionException 当前状态不允许拒绝
     */
    public function reject(EcosystemMedia $media, int $adminId): EcosystemMedia
    {
        return $this->transition($media, $adminId, 'rejected', ['pending']);
    }

    /**
     * pending | approved -> quarantined（紧急隔离；已隔离不能原地恢复）。
     *
     * @throws MediaTransitionException 当前状态不允许隔离
     */
    public function quarantine(EcosystemMedia $media, int $adminId): EcosystemMedia
    {
        return $this->transition($media, $adminId, 'quarantined', ['pending', 'approved']);
    }

    /**
     * 把已批准媒体绑定为规则版本的规则文件（ADR §4.5）。
     *
     * 约束：
     *   · 规则版本必须仍 `status=draft`（已批准 / 已替代 / 已撤回的版本不可换文件）；
     *   · 媒体必须 `status=approved`，且后台账号存在；
     *   · 只写 `file_media_id` 并重算规则版本指纹，**不触碰 `body_html`**
     *     （富文本净化属于 G1-07，媒体绑定不得顺带改写正文）。
     *
     * @throws RevisionImmutableException 规则版本已离开 draft
     * @throws MediaValidationException 媒体不存在或未批准
     * @throws InvalidArgumentException 后台账号 / 规则版本记录无效
     */
    public function attachRuleFile(EventRuleVersion $rule, int $mediaId, int $adminId): EventRuleVersion
    {
        $this->assertAdminExists($adminId);

        if ($rule->getKey() === null) {
            throw new InvalidArgumentException('绑定规则文件前规则版本必须已保存。');
        }

        if ($mediaId <= 0) {
            throw new MediaValidationException('要绑定的媒体 ID 必须是正整数。');
        }

        return DB::transaction(function () use ($rule, $mediaId): EventRuleVersion {
            // 规则版本 -> 媒体，锁顺序固定，避免与其它媒体操作交叉死锁。
            $locked = EventRuleVersion::query()
                ->whereKey($rule->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new InvalidArgumentException(sprintf(
                    '规则版本 %s 不存在，无法绑定规则文件。',
                    (string) $rule->getKey(),
                ));
            }

            if ((string) $locked->status !== 'draft') {
                throw new RevisionImmutableException(sprintf(
                    '规则版本 %s 当前 status=%s，只有 draft 可以绑定规则文件；请创建 version_no + 1。',
                    (string) $locked->getKey(),
                    (string) $locked->status,
                ));
            }

            $media = EcosystemMedia::query()
                ->whereKey($mediaId)
                ->lockForUpdate()
                ->first();

            if ($media === null) {
                throw new MediaValidationException(sprintf('媒体 %d 不存在。', $mediaId));
            }

            if ((string) $media->status !== 'approved') {
                throw new MediaValidationException(sprintf(
                    '媒体 %d 当前 status=%s，只有 approved 媒体可以绑定为规则文件。',
                    $mediaId,
                    (string) $media->status,
                ));
            }

            $locked->file_media_id = $mediaId;
            $locked->content_fingerprint = $this->fingerprints->ruleVersion($locked);
            $locked->save();

            return $locked;
        });
    }

    /**
     * @param  array<int, string>  $allowedFrom
     *
     * @throws MediaTransitionException
     */
    private function transition(
        EcosystemMedia $media,
        int $adminId,
        string $to,
        array $allowedFrom,
    ): EcosystemMedia {
        $this->assertAdminExists($adminId);

        if ($media->getKey() === null) {
            throw new InvalidArgumentException('审核媒体前记录必须已保存。');
        }

        return DB::transaction(function () use ($media, $to, $allowedFrom): EcosystemMedia {
            $locked = EcosystemMedia::query()
                ->whereKey($media->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new MediaTransitionException(sprintf('媒体 %s 不存在。', (string) $media->getKey()));
            }

            $current = (string) $locked->status;

            if (! in_array($current, $allowedFrom, true)) {
                throw new MediaTransitionException(sprintf(
                    '媒体 %s 当前 status=%s，不能转为 %s。',
                    (string) $locked->getKey(),
                    $current,
                    $to,
                ));
            }

            $locked->status = $to;
            $locked->save();

            return $locked;
        });
    }

    private function assertAdminExists(int $adminId): void
    {
        if ($adminId <= 0 || ! DB::table('admins')->where('id', $adminId)->exists()) {
            throw new InvalidArgumentException(sprintf('媒体操作需要存在的后台账号，admin_id=%d 无效。', $adminId));
        }
    }

    private function assertUploadIsReadable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new MediaValidationException('上传文件无效或上传失败。');
        }
    }

    private function detectMime(string $realPath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($realPath);

        return is_string($mime) ? mb_strtolower($mime) : '';
    }

    /**
     * 图片必须能被 getimagesize 解码，且真实类型与 finfo 一致。
     *
     * @return array{0: int, 1: int} width / height
     */
    private function assertImage(string $realPath, string $mime): array
    {
        $info = @getimagesize($realPath);

        if ($info === false || ! isset($info[0], $info[1], $info[2])) {
            throw new MediaValidationException('图片无法解码，文件已损坏或不是真实图片。');
        }

        $expectedType = [
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png'  => IMAGETYPE_PNG,
            'image/webp' => IMAGETYPE_WEBP,
        ][$mime] ?? null;

        if ($expectedType === null || (int) $info[2] !== $expectedType) {
            throw new MediaValidationException(sprintf(
                '图片真实类型与 MIME「%s」不一致。',
                $mime,
            ));
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $maxDimension = (int) config('ecosystem_media.max_dimension', 8000);

        if ($width < 1 || $height < 1) {
            throw new MediaValidationException('图片尺寸无效。');
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            throw new MediaValidationException(sprintf(
                '图片尺寸 %dx%d 超过单边上限 %dpx。',
                $width,
                $height,
                $maxDimension,
            ));
        }

        return [$width, $height];
    }

    /**
     * PDF 必须带 `%PDF-` 头部；扩展名正确但内容不是 PDF 的文件在这里被挡。
     */
    private function assertPdf(string $realPath): void
    {
        $handle = fopen($realPath, 'rb');

        if ($handle === false) {
            throw new MediaValidationException('PDF 无法读取。');
        }

        try {
            $header = fread($handle, 5);
        } finally {
            fclose($handle);
        }

        if ($header !== '%PDF-') {
            throw new MediaValidationException('文件缺少 PDF 头部（%PDF-），不是有效 PDF。');
        }
    }

    /**
     * 原始文件名净化：去掉目录（含 Windows 反斜杠）、控制字符，trim，
     * 保留可读的展示名；空名 / `.` / `..` 返回 NULL。
     */
    private function sanitizeOriginalName(?string $name): ?string
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        // 去掉 NUL / 换行 / 其他控制字符，避免日志与响应头注入。
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        return $name;
    }

    private function disk(): string
    {
        return (string) config('ecosystem_media.disk', 'local');
    }

    /**
     * @return array<int, string>
     */
    private function allowedMimes(): array
    {
        return array_values((array) config('ecosystem_media.allowed_mimes', []));
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(string $mime): array
    {
        return array_values((array) config('ecosystem_media.extensions.'.$mime, []));
    }

    private function canonicalExtension(string $mime): string
    {
        return $this->allowedExtensions($mime)[0] ?? 'bin';
    }

    private function kindOf(string $mime): string
    {
        return (string) config('ecosystem_media.kinds.'.$mime, 'document');
    }

    private function maxBytesFor(string $kind): int
    {
        return (int) ($kind === 'image'
            ? config('ecosystem_media.images.max_bytes', 5 * 1024 * 1024)
            : config('ecosystem_media.docs.max_bytes', 10 * 1024 * 1024));
    }

    private function deleteStoredFileIfOrphan(string $disk, string $storageKey): void
    {
        if (EcosystemMedia::query()->where('storage_key', $storageKey)->exists()) {
            return;
        }

        Storage::disk($disk)->delete($storageKey);
    }

    private function isDuplicateHash(\Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'uk_ecosystem_media_sha256')
            || str_contains($message, 'uk_ecosystem_media_storage_key')
            || str_contains($message, 'Duplicate entry');
    }
}
