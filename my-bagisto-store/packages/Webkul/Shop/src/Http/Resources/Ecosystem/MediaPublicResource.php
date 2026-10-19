<?php

namespace Webkul\Shop\Http\Resources\Ecosystem;

use Webkul\Shop\Models\EcosystemMediaRef;

/**
 * 公开媒体 DTO（ADR-0001 G1-D08 / 规划 §6.3 / G1-10）。
 *
 * 只暴露 `id` / `role` / `alt_text` / 受控路由 `url`。
 * **永不返回** `media_id` 以外的存储字段：`disk` / `storage_key` / 路径 /
 * `sha256` / `original_name` 都留在服务端。
 *
 * `url` 是相对路由，不含 revision ID —— 媒体读取由父对象 + 当前 published
 * revision 在服务端解析。
 */
final class MediaPublicResource
{
    /**
     * @param  array<int, EcosystemMediaRef>  $refs
     * @return array<int, array{id: int, role: string, alt_text: string|null, url: string}>
     */
    public static function forEvent(int $eventId, array $refs): array
    {
        return self::build($refs, static fn (int $mediaId): string => sprintf(
            '/api/events/%d/media/%d',
            $eventId,
            $mediaId,
        ));
    }

    /**
     * @param  array<int, EcosystemMediaRef>  $refs
     * @return array<int, array{id: int, role: string, alt_text: string|null, url: string}>
     */
    public static function forContent(string $slug, array $refs): array
    {
        return self::build($refs, static fn (int $mediaId): string => sprintf(
            '/api/content/posts/%s/media/%d',
            rawurlencode($slug),
            $mediaId,
        ));
    }

    /**
     * @param  array<int, EcosystemMediaRef>  $refs
     * @param  callable(int): string  $url
     * @return array<int, array{id: int, role: string, alt_text: string|null, url: string}>
     */
    private static function build(array $refs, callable $url): array
    {
        $media = [];

        foreach ($refs as $ref) {
            $mediaId = (int) $ref->media_id;

            $media[] = [
                'id'       => $mediaId,
                'role'     => (string) $ref->role,
                'alt_text' => $ref->alt_text !== null ? (string) $ref->alt_text : null,
                'url'      => $url($mediaId),
            ];
        }

        return $media;
    }
}
