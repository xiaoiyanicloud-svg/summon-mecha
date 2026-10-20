<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;

/**
 * 公开资讯查询（ADR-0001 G1-D08 / 规划 §6.4 / G1-10；G4-01 扩展）。
 *
 * 可见性：`publish_status=published` + `disposition=normal`
 * + `published_revision_id` 指向**同一文章**的 `review_state=approved` revision。
 *
 * 排序：`first_published_at DESC, id DESC`（id 决胜，保证跨页稳定）。
 * G4-01：`q` 只搜索标题/摘要（LIKE 转义）；新增游标分页；不引入 ES。
 */
class PublicContentQuery
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return $this->applyFilters($this->visibleBase(), $filters)
            ->orderByDesc('content_posts.first_published_at')
            ->orderByDesc('content_posts.id')
            ->paginate($perPage, ['content_posts.*'], 'page', max(1, $page));
    }

    /**
     * G4-01 游标分页。
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: Collection<int, ContentPost>, has_more: bool, next_cursor: string|null}
     *
     * @throws PublicCursorException
     */
    public function cursorPaginate(array $filters, ?string $cursor, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $hashFilters = self::filterHashInput($filters);

        $query = $this->applyFilters($this->visibleBase(), $filters);

        if ($cursor !== null && trim($cursor) !== '') {
            $position = PublicListCursor::decode($cursor, $hashFilters);
            $this->applyCursor($query, $position['sort'], $position['id']);
        }

        $rows = $query
            ->orderByDesc('content_posts.first_published_at')
            ->orderByDesc('content_posts.id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $items = $rows->slice(0, $perPage)->values();

        $nextCursor = null;

        if ($hasMore && $items->isNotEmpty()) {
            /** @var ContentPost $last */
            $last = $items->last();
            $nextCursor = PublicListCursor::encode(
                $this->sortValue($last->first_published_at),
                (int) $last->getKey(),
                $hashFilters,
            );
        }

        return [
            'items'       => $items,
            'has_more'    => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Sitemap 专用：只取公开文章的 slug 与首次公开时间。
     *
     * @return Collection<int, ContentPost>
     */
    public function sitemapRows(int $limit): Collection
    {
        return $this->visibleBase()
            ->orderByDesc('content_posts.first_published_at')
            ->orderByDesc('content_posts.id')
            ->limit(max(1, $limit))
            ->get(['content_posts.slug', 'content_posts.published_at', 'content_posts.first_published_at']);
    }

    public function findPublishedBySlug(string $slug): ?ContentPost
    {
        if ($slug === '') {
            return null;
        }

        return $this->publicationBase()
            ->where('content_posts.slug', $slug)
            ->first();
    }

    public function findPublishedById(int $id): ?ContentPost
    {
        if ($id <= 0) {
            return null;
        }

        return $this->publicationBase()
            ->where('content_posts.id', $id)
            ->first();
    }

    /**
     * 批量读取当前公开文章（G4-02 收藏列表 / G4-03 关联解析）。
     *
     * @param  array<int, mixed>  $slugs
     * @return Collection<int, ContentPost>
     */
    public function publishedBySlugs(array $slugs): Collection
    {
        $slugs = array_values(array_unique(array_filter(
            array_map(static fn (mixed $slug): string => trim((string) $slug), $slugs),
            static fn (string $slug): bool => $slug !== '',
        )));

        if ($slugs === []) {
            return new Collection();
        }

        return $this->visibleBase()->whereIn('content_posts.slug', $slugs)->get();
    }

    /**
     * 已撤稿且曾经发布过的文章（410）。从未发布过的 retracted 仍按 404 处理。
     */
    public function findRetractedBySlug(string $slug): ?ContentPost
    {
        if ($slug === '') {
            return null;
        }

        return ContentPost::query()
            ->where('slug', $slug)
            ->where('disposition', 'retracted')
            ->where(function (Builder $query): void {
                $query->whereNotNull('published_at')
                    ->orWhereNotNull('first_published_at');
            })
            ->first();
    }

    public function bodyIsRenderable(ContentRevision $revision): bool
    {
        if ($revision->body_html === null || trim((string) $revision->body_html) === '') {
            return true;
        }

        return (string) $revision->sanitizer_version === $this->sanitizerVersion();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function countIncompatibleSanitizer(array $filters): int
    {
        return $this->applyFilters($this->publicationBase(), $filters)
            ->whereNotNull('public_revision.body_html')
            ->where(function (Builder $query): void {
                $query->whereNull('public_revision.sanitizer_version')
                    ->orWhere('public_revision.sanitizer_version', '!=', $this->sanitizerVersion());
            })
            ->count();
    }

    private function visibleBase(): Builder
    {
        return $this->publicationBase()
            ->where(function (Builder $query): void {
                $query->whereNull('public_revision.body_html')
                    ->orWhere('public_revision.sanitizer_version', $this->sanitizerVersion());
            });
    }

    private function publicationBase(): Builder
    {
        return ContentPost::query()
            ->join('content_revisions as public_revision', 'public_revision.id', '=', 'content_posts.published_revision_id')
            ->whereColumn('public_revision.post_id', 'content_posts.id')
            ->where('content_posts.publish_status', 'published')
            ->where('content_posts.disposition', 'normal')
            ->whereNotNull('content_posts.published_revision_id')
            ->where('public_revision.review_state', 'approved')
            ->with([
                'publishedRevision',
                'publishedRevision.sources' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ])
            ->select('content_posts.*');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (isset($filters['type'])) {
            $query->where('content_posts.type', (string) $filters['type']);
        }

        if (isset($filters['category'])) {
            $query->where('content_posts.category', (string) $filters['category']);
        }

        if (isset($filters['published_from'])) {
            $query->where('content_posts.first_published_at', '>=', $this->utc($filters['published_from']));
        }

        if (isset($filters['published_to'])) {
            $query->where('content_posts.first_published_at', '<=', $this->utc($filters['published_to']));
        }

        if (isset($filters['q'])) {
            $keyword = trim((string) $filters['q']);

            if ($keyword !== '') {
                $like = '%'.addcslashes($keyword, '\\%_').'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('public_revision.title', 'like', $like)
                        ->orWhere('public_revision.summary', 'like', $like);
                });
            }
        }

        return $query;
    }

    /**
     * 无时间排序值的行在 MySQL DESC 中排在最后；游标要显式区分“时间值”和 NULL。
     */
    private function applyCursor(Builder $query, ?string $sort, int $id): void
    {
        if ($sort === null) {
            $query->whereNull('content_posts.first_published_at')
                ->where('content_posts.id', '<', $id);

            return;
        }

        $query->where(function (Builder $inner) use ($sort, $id): void {
            $inner->where('content_posts.first_published_at', '<', $sort)
                ->orWhere(function (Builder $sameTime) use ($sort, $id): void {
                    $sameTime->where('content_posts.first_published_at', $sort)
                        ->where('content_posts.id', '<', $id);
                })
                ->orWhereNull('content_posts.first_published_at');
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function filterHashInput(array $filters): array
    {
        unset($filters['page'], $filters['per_page'], $filters['cursor'], $filters['mode']);

        return $filters;
    }

    private function sortValue(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->copy()->utc()->format('Y-m-d H:i:s') : null;
    }

    private function utc(mixed $value): string
    {
        return Carbon::parse((string) $value)->utc()->format('Y-m-d H:i:s');
    }

    private function sanitizerVersion(): string
    {
        return (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1');
    }
}
