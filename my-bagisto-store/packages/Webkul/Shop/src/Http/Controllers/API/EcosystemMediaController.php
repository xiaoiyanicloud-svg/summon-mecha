<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Services\Ecosystem\MediaAccessException;
use Webkul\Shop\Services\Ecosystem\MediaAccessService;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;

/**
 * 公开媒体流式输出（ADR-0001 G1-D08 / 规划 §6.5 / §8.8 / G1-10）。
 *
 * 路由不带 revision ID：
 *   GET /api/events/{id}/media/{mediaId}
 *   GET /api/content/posts/{slug}/media/{mediaId}
 *
 * 必须先解析父对象**当前** published revision，再交给 `MediaAccessService`
 * 做完整门禁（域开关 + 父对象 published/normal + revision approved 且为当前
 * 公开版本 + 引用 approved + 媒体 approved + 文件存在）。任一不满足统一 404。
 *
 * 输出只从私有盘流式读取：
 *   · `Content-Type` 取数据库真实 MIME；
 *   · `Content-Length` 取实际文件大小；
 *   · `Cache-Control: private, no-store`（G1 不做 CDN / 长缓存）；
 *   · `X-Content-Type-Options: nosniff`；
 *   · 不在响应头 / 响应体暴露 `storage_key` / `disk` / 磁盘路径 / revision ID。
 */
class EcosystemMediaController extends APIController
{
    public function __construct(
        private readonly PublicReadGate $gate,
        private readonly MediaAccessService $access,
    ) {}

    public function event(int $id, int $mediaId): StreamedResponse|JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! $this->publicEnabled('event_revision')) {
            return $this->mediaNotFound();
        }

        $event = Event::query()->whereKey($id)->first();

        if (! $event
            || (string) $event->publish_status !== 'published'
            || (string) $event->disposition !== 'normal'
            || $event->published_revision_id === null) {
            return $this->mediaNotFound();
        }

        return $this->stream(
            'event_revision',
            (int) $event->published_revision_id,
            $mediaId,
        );
    }

    public function content(string $slug, int $mediaId): StreamedResponse|JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! $this->publicEnabled('content_revision')) {
            return $this->mediaNotFound();
        }

        $post = ContentPost::query()->where('slug', $slug)->first();

        if (! $post
            || (string) $post->publish_status !== 'published'
            || (string) $post->disposition !== 'normal'
            || $post->published_revision_id === null) {
            return $this->mediaNotFound();
        }

        return $this->stream(
            'content_revision',
            (int) $post->published_revision_id,
            $mediaId,
        );
    }

    private function stream(string $ownerType, int $revisionId, int $mediaId): StreamedResponse|JsonResponse
    {
        try {
            $media = $this->access->resolvePublic($ownerType, $revisionId, $mediaId);
        } catch (PublicReadUnavailableException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        } catch (MediaAccessException $exception) {
            return $this->fail($exception->errorCode, $exception->getMessage(), 404);
        }

        $disk = (string) $media->disk;
        $key = (string) $media->storage_key;

        try {
            $size = Storage::disk($disk)->size($key);
        } catch (\Throwable $exception) {
            Log::error('ecosystem.public_media.file_missing', [
                'owner_type' => $ownerType,
                'owner_id'   => $revisionId,
                'media_id'   => (int) $media->id,
            ]);

            return $this->mediaNotFound();
        }

        return response()->stream(
            function () use ($disk, $key): void {
                $stream = Storage::disk($disk)->readStream($key);

                if (! is_resource($stream)) {
                    return;
                }

                fpassthru($stream);
                fclose($stream);
            },
            200,
            $this->headers($media, (int) $size),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(EcosystemMedia $media, int $size): array
    {
        $mime = (string) $media->mime;

        // 图片 inline；PDF 用 attachment，避免浏览器内联打开不可信文档。
        $disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';

        return [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) $size,
            'Content-Disposition'    => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
            'X-Robots-Tag'           => 'noindex',
        ];
    }

    private function publicReadUnavailable(): ?JsonResponse
    {
        try {
            $this->gate->assertAvailable();
        } catch (PublicReadUnavailableException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return null;
    }

    private function publicEnabled(string $ownerType): bool
    {
        $key = $ownerType === 'event_revision'
            ? 'ecosystem.events.public_enabled'
            : 'ecosystem.content.public_enabled';

        return (bool) config($key, false);
    }

    private function mediaNotFound(): JsonResponse
    {
        // 统一 404：不区分未发布 / 暂缓 / 撤稿 / 未审核 / 文件缺失，避免探测存在性。
        return $this->fail(ApiErrorCode::MEDIA_NOT_FOUND, '媒体不存在或不可访问。', 404);
    }
}
