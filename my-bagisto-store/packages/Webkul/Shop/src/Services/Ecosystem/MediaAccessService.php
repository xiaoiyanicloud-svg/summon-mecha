<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemMediaRef;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 公开媒体读取门禁（ADR-0001 §4.9 / §4.10 / G1-D06 / G1-06）。
 *
 * 公开读取必须**同时**满足：
 *   1. 所属域开关打开（活动媒体看 `ecosystem.events.public_enabled`，
 *      内容媒体看 `ecosystem.content.public_enabled`）；
 *   2. `owner_type` 是 event_revision / content_revision；
 *   3. 引用存在且 `review_status=approved`；
 *   4. 媒体自身 `status=approved`；
 *   5. 父 revision `review_state=approved`；
 *   6. 父 revision 是父对象**当前** `published_revision_id`；
 *   7. 父对象 `publish_status=published` 且 `disposition=normal`；
 *   8. 私有盘上的文件真实存在。
 *
 * 任一条件不满足都抛 `MediaAccessException`，对外统一 404 / `MEDIA_NOT_FOUND`，
 * **不区分原因** —— 不得泄露草稿、待审、暂缓、隔离或已撤稿媒体的存在性。
 *
 * 本方法返回 `EcosystemMedia` 模型供 controller 从私有盘流式输出；
 * controller **不得**把 `storage_key` / `disk` 放进公开 DTO，也不得生成公开 URL。
 */
class MediaAccessService
{
    public function __construct(
        private readonly PublicReadGate $gate,
    ) {}

    /**
     * @throws PublicReadUnavailableException 平台整体停读（503，优先于一切判断）
     * @throws MediaAccessException 任一公开条件不满足
     */
    public function resolvePublic(string $ownerType, int $ownerId, int $mediaId): EcosystemMedia
    {
        // kill switch 必须最先检查：不查业务表、不落到 404。
        // 停读时通过 404 “看起来像不存在”会让调用方误判为内容被删除。
        $this->gate->assertAvailable();

        if ($ownerId <= 0 || $mediaId <= 0
            || ! in_array($ownerType, $this->ownerTypes(), true)) {
            throw MediaAccessException::notFound();
        }

        if (! $this->publicEnabled($ownerType)) {
            throw MediaAccessException::notFound();
        }

        $ref = EcosystemMediaRef::query()
            ->where('media_id', $mediaId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('review_status', 'approved')
            ->first();

        if ($ref === null) {
            throw MediaAccessException::notFound();
        }

        $media = EcosystemMedia::query()->find($mediaId);

        if ($media === null || (string) $media->status !== 'approved') {
            throw MediaAccessException::notFound();
        }

        $revision = $this->findRevision($ownerType, $ownerId);

        if ($revision === null || (string) $revision->review_state !== 'approved') {
            throw MediaAccessException::notFound();
        }

        $parent = $ownerType === 'event_revision'
            ? Event::query()->find($revision->event_id)
            : ContentPost::query()->find($revision->post_id);

        if ($parent === null
            || (int) $parent->published_revision_id !== $ownerId
            || (string) $parent->publish_status !== 'published'
            || (string) $parent->disposition !== 'normal') {
            throw MediaAccessException::notFound();
        }

        if (! Storage::disk((string) $media->disk)->exists((string) $media->storage_key)) {
            // 门禁通过但私有盘缺件：这是运维事实，必须留 error 日志（G1-10 契约）。
            // 日志只写 ID，不写 storage_key / 磁盘路径。
            Log::error('ecosystem.public_media.file_missing', [
                'owner_type' => $ownerType,
                'owner_id'   => $ownerId,
                'media_id'   => $mediaId,
            ]);

            throw MediaAccessException::notFound();
        }

        return $media;
    }

    private function findRevision(string $ownerType, int $ownerId): EventRevision|ContentRevision|null
    {
        return $ownerType === 'event_revision'
            ? EventRevision::query()->find($ownerId)
            : ContentRevision::query()->find($ownerId);
    }

    private function publicEnabled(string $ownerType): bool
    {
        $key = $ownerType === 'event_revision'
            ? 'ecosystem.events.public_enabled'
            : 'ecosystem.content.public_enabled';

        return (bool) config($key, false);
    }

    /**
     * @return array<int, string>
     */
    private function ownerTypes(): array
    {
        return array_values((array) config('ecosystem_media.owner_types', []));
    }
}
