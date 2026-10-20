<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemFavorite;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * G4-02 · 收藏服务。
 *
 * 三条硬约束：
 *   1. 只有**当前公开**的目标才能新增收藏；目标不可见统一 404，不泄露存在性。
 *   2. 读取列表时只返回当前仍公开的目标；已撤稿/下架的目标只计入 `stale_count`，
 *      不返回旧标题、旧摘要或旧 URL。
 *   3. 唯一键 + firstOrCreate 保证重复点击幂等；并发撞唯一键按“已收藏”处理。
 *
 * 收藏是私有状态：列表响应必须 `private, no-store`，不得进入 SSR/OG/sitemap。
 */
class FavoriteService
{
    public const TYPE_EVENT = 'event';

    public const TYPE_CONTENT = 'content';

    public const TYPES = [self::TYPE_EVENT, self::TYPE_CONTENT];

    public function __construct(
        private readonly PublicEventQuery $events,
        private readonly PublicContentQuery $contents,
        private readonly PublicMediaResolver $media,
        private readonly PublicReadGate $gate,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ecosystem_g4.favorites.enabled', false);
    }

    /**
     * 收藏可见性还要叠加公开读取闸与域开关：收藏不能成为绕过停读/域关闭的侧信道。
     */
    public function domainAvailable(string $type): bool
    {
        if (! $this->gate->available()) {
            return false;
        }

        return match ($type) {
            self::TYPE_EVENT => (bool) config('ecosystem.events.public_enabled', false),
            self::TYPE_CONTENT => (bool) config('ecosystem.content.public_enabled', false),
            default => false,
        };
    }

    /**
     * @return array{favorited: bool, created: bool}
     *
     * @throws FavoriteException
     */
    public function add(int $customerId, string $type, string $key): array
    {
        $this->assertEnabled();
        $type = $this->assertType($type);
        $key = $this->normalizeKey($key);

        if (! $this->targetIsPublic($type, $key)) {
            throw FavoriteException::targetNotFound();
        }

        $existing = EcosystemFavorite::query()
            ->where('customer_id', $customerId)
            ->where('target_type', $type)
            ->where('target_key', $key)
            ->first();

        if ($existing !== null) {
            return ['favorited' => true, 'created' => false];
        }

        $limit = max(1, (int) config('ecosystem_g4.favorites.max_per_customer', 500));

        $count = EcosystemFavorite::query()->where('customer_id', $customerId)->count();

        if ($count >= $limit) {
            throw FavoriteException::limitReached($limit);
        }

        try {
            $favorite = EcosystemFavorite::query()->create([
                'customer_id' => $customerId,
                'target_type' => $type,
                'target_key' => $key,
            ]);
        } catch (QueryException $exception) {
            // 并发点击同一目标：唯一键已经存在，按幂等成功处理。
            if ($this->isDuplicateKey($exception)) {
                return ['favorited' => true, 'created' => false];
            }

            throw $exception;
        }

        return ['favorited' => true, 'created' => $favorite->wasRecentlyCreated];
    }

    /**
     * @return array{favorited: bool, removed: bool}
     *
     * @throws FavoriteException
     */
    public function remove(int $customerId, string $type, string $key): array
    {
        $this->assertEnabled();
        $type = $this->assertType($type);
        $key = $this->normalizeKey($key);

        $deleted = EcosystemFavorite::query()
            ->where('customer_id', $customerId)
            ->where('target_type', $type)
            ->where('target_key', $key)
            ->delete();

        return ['favorited' => false, 'removed' => $deleted > 0];
    }

    /**
     * 批量取收藏标记，供公开列表/详情 DTO 使用，避免 N+1。
     *
     * @param  array<int, mixed>  $keys
     * @return array<string, bool>
     */
    public function flagsFor(int $customerId, string $type, array $keys): array
    {
        if (! $this->enabled() || ! in_array($type, self::TYPES, true) || ! $this->domainAvailable($type)) {
            return [];
        }

        $keys = array_values(array_unique(array_filter(
            array_map(static fn (mixed $key): string => trim((string) $key), $keys),
            static fn (string $key): bool => $key !== '',
        )));

        if ($keys === []) {
            return [];
        }

        $flags = [];

        $rows = EcosystemFavorite::query()
            ->where('customer_id', $customerId)
            ->where('target_type', $type)
            ->whereIn('target_key', $keys)
            ->pluck('target_key');

        foreach ($rows as $key) {
            $flags[(string) $key] = true;
        }

        return $flags;
    }

    public function isFavorited(int $customerId, string $type, string $key): bool
    {
        if (! $this->enabled() || ! in_array($type, self::TYPES, true) || ! $this->domainAvailable($type)) {
            return false;
        }

        return EcosystemFavorite::query()
            ->where('customer_id', $customerId)
            ->where('target_type', $type)
            ->where('target_key', $key)
            ->exists();
    }

    /**
     * 我的收藏。
     *
     * 游标按收藏行 id 倒序（最近收藏优先），目标不可见时不占输出位，只累计 stale_count。
     * 为避免大量失效收藏拖慢单次请求，最多扫描 `per_page * 10` 行；仍有剩余时通过
     * next_cursor 继续，不静默丢弃。
     *
     * @return array{items: array<int, array<string, mixed>>, stale_count: int, has_more: bool, next_cursor: string|null}
     *
     * @throws FavoriteException
     */
    public function listFor(int $customerId, string $type, ?string $cursor, int $perPage): array
    {
        $type = $this->assertType($type);

        if (! $this->domainAvailable($type)) {
            return ['items' => [], 'stale_count' => 0, 'has_more' => false, 'next_cursor' => null];
        }

        $perPage = max(1, min(
            max(1, (int) config('ecosystem_g4.favorites.max_per_page', 50)),
            $perPage,
        ));

        $filter = ['type' => $type];
        $lastId = null;

        if ($cursor !== null && trim($cursor) !== '') {
            $position = PublicListCursor::decode($cursor, $filter);
            $lastId = $position['id'];
        }

        $items = [];
        $stale = 0;
        $scanned = 0;
        $scanLimit = $perPage * 10;
        $currentLast = $lastId;

        while (count($items) < $perPage && $scanned < $scanLimit) {
            $limit = max(20, min(200, $perPage * 3));

            $query = EcosystemFavorite::query()
                ->where('customer_id', $customerId)
                ->where('target_type', $type)
                ->orderByDesc('id')
                ->limit($limit);

            if ($currentLast !== null) {
                $query->where('id', '<', $currentLast);
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                break;
            }

            $resolved = $this->resolveRows($type, $rows->pluck('target_key')->all());

            foreach ($rows as $row) {
                if (count($items) >= $perPage) {
                    break 2;
                }

                $scanned++;
                $currentLast = (int) $row->id;
                $key = (string) $row->target_key;

                if (isset($resolved[$key])) {
                    $items[] = $resolved[$key];
                } else {
                    $stale++;
                }
            }

            if ($rows->count() < $limit) {
                break;
            }
        }

        $hasMore = $currentLast !== null
            && EcosystemFavorite::query()
                ->where('customer_id', $customerId)
                ->where('target_type', $type)
                ->where('id', '<', $currentLast)
                ->exists();

        $nextCursor = $hasMore
            ? PublicListCursor::encode(null, (int) $currentLast, $filter)
            : null;

        return [
            'items'       => $items,
            'stale_count' => $stale,
            'has_more'    => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<string, array<string, mixed>>
     */
    private function resolveRows(string $type, array $keys): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');
        $iso = static fn (mixed $value): ?string => $value instanceof Carbon
            ? $value->copy()->timezone($timezone)->toIso8601String()
            : null;

        if ($type === self::TYPE_EVENT) {
            $ids = array_map(static fn (mixed $key): int => (int) $key, $keys);
            $events = $this->events->publishedByIds($ids);
            $revisions = $events
                ->map(static fn (Event $event) => $event->publishedRevision)
                ->filter(static fn (mixed $revision): bool => $revision instanceof EventRevision)
                ->values();

            $refsByRevision = $this->media->approvedRefsByRevision(
                'event_revision',
                $revisions->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            );

            $result = [];

            foreach ($events as $event) {
                $revision = $event->publishedRevision;

                if (! $revision instanceof EventRevision) {
                    continue;
                }

                $cover = $this->coverUrl(
                    $refsByRevision[(int) $revision->id] ?? [],
                    static fn (int $mediaId): string => sprintf('/api/events/%d/media/%d', (int) $event->id, $mediaId),
                );

                $result[(string) $event->id] = [
                    'type'         => self::TYPE_EVENT,
                    'key'          => (string) $event->id,
                    'id'           => (int) $event->id,
                    'title'        => (string) $revision->name,
                    'summary'      => $revision->summary !== null ? (string) $revision->summary : null,
                    'starts_at'    => $iso($revision->starts_at),
                    'ends_at'      => $iso($revision->ends_at),
                    'city'         => $revision->city !== null ? (string) $revision->city : null,
                    'published_at' => $iso($event->published_at),
                    'cover'        => $cover,
                ];
            }

            return $result;
        }

        $posts = $this->contents->publishedBySlugs($keys);
        $revisions = $posts
            ->map(static fn (ContentPost $post) => $post->publishedRevision)
            ->filter(static fn (mixed $revision): bool => $revision instanceof ContentRevision)
            ->values();

        $refsByRevision = $this->media->approvedRefsByRevision(
            'content_revision',
            $revisions->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );

        $result = [];

        foreach ($posts as $post) {
            $revision = $post->publishedRevision;

            if (! $revision instanceof ContentRevision) {
                continue;
            }

            $cover = $this->coverUrl(
                $refsByRevision[(int) $revision->id] ?? [],
                static fn (int $mediaId): string => sprintf(
                    '/api/content/posts/%s/media/%d',
                    rawurlencode((string) $post->slug),
                    $mediaId,
                ),
            );

            $result[(string) $post->slug] = [
                'type'         => self::TYPE_CONTENT,
                'key'          => (string) $post->slug,
                'slug'         => (string) $post->slug,
                'title'        => (string) $revision->title,
                'summary'      => $revision->summary !== null ? (string) $revision->summary : null,
                'published_at' => $iso($post->published_at),
                'cover'        => $cover,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, \Webkul\Shop\Models\EcosystemMediaRef>  $refs
     * @param  callable(int): string  $url
     */
    private function coverUrl(array $refs, callable $url): ?string
    {
        if ($refs === []) {
            return null;
        }

        $cover = null;

        foreach ($refs as $ref) {
            if ((string) $ref->role === 'cover') {
                $cover = $ref;
                break;
            }
        }

        $cover ??= $refs[0];

        return $url((int) $cover->media_id);
    }

    private function targetIsPublic(string $type, string $key): bool
    {
        if (! $this->domainAvailable($type)) {
            return false;
        }

        return match ($type) {
            self::TYPE_EVENT => ctype_digit($key) && $this->events->findPublished((int) $key) !== null,
            self::TYPE_CONTENT => $this->contents->findPublishedBySlug($key) !== null,
            default => false,
        };
    }

    /**
     * @throws FavoriteException
     */
    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw FavoriteException::disabled();
        }
    }

    /**
     * @throws FavoriteException
     */
    private function assertType(string $type): string
    {
        $type = trim($type);

        if (! in_array($type, self::TYPES, true)) {
            throw FavoriteException::typeInvalid();
        }

        return $type;
    }

    /**
     * @throws FavoriteException
     */
    private function normalizeKey(string $key): string
    {
        $key = trim($key);

        if ($key === '' || mb_strlen($key) > 191 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw FavoriteException::targetNotFound();
        }

        return $key;
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        // MySQL 1062 / SQLite 19（测试环境可能切换驱动）；按 SQLSTATE 判断更稳。
        return (string) ($exception->errorInfo[1] ?? '') === '1062'
            || str_contains((string) $exception->getMessage(), 'Duplicate entry');
    }
}
