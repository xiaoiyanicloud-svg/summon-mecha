<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Models\EcosystemMediaRef;

/**
 * 公开媒体列表解析（ADR-0001 G1-D08 / 规划 §6.3 / G1-10）。
 *
 * 公开 DTO 的 `media[]` 只来自**当前 published revision** 的：
 *   · `ecosystem_media_refs.review_status = approved`；
 *   · `ecosystem_media.status = approved`。
 *
 * 文件是否存在由 `MediaAccessService` 在实际读取时校验；本类只负责
 * 列表口径，不打开私有盘、不返回 `storage_key` / `disk` / 路径。
 */
class PublicMediaResolver
{
    /**
     * @param  array<int, int|string>  $revisionIds
     * @return array<int, array<int, EcosystemMediaRef>>  revision_id => refs（按 sort_order, id 升序）
     */
    public function approvedRefsByRevision(string $ownerType, array $revisionIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $revisionIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $refs = EcosystemMediaRef::query()
            ->join('ecosystem_media', 'ecosystem_media.id', '=', 'ecosystem_media_refs.media_id')
            ->where('ecosystem_media_refs.owner_type', $ownerType)
            ->whereIn('ecosystem_media_refs.owner_id', $ids)
            ->where('ecosystem_media_refs.review_status', 'approved')
            ->where('ecosystem_media.status', 'approved')
            ->orderBy('ecosystem_media_refs.owner_id')
            ->orderBy('ecosystem_media_refs.sort_order')
            ->orderBy('ecosystem_media_refs.id')
            ->select('ecosystem_media_refs.*')
            ->get();

        $grouped = [];

        foreach ($refs as $ref) {
            $grouped[(int) $ref->owner_id][] = $ref;
        }

        return $grouped;
    }
}
