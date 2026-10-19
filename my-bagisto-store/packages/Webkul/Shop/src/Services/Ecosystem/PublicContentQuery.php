<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;

/**
 * 公开资讯查询（ADR-0001 G1-D08 / 规划 §6.4 / G1-10）。
 *
 * 可见性：`publish_status=published` + `disposition=normal`
 * + `published_revision_id` 指向**同一文章**的 `review_state=approved` revision。
 *
 * 排序：`first_published_at DESC, id DESC`（id 决胜，保证跨页稳定）。
 * 不做全文搜索 / LIKE / ES —— 搜索留 G4-01。
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

    public function findPublishedBySlug(string $slug): ?ContentPost
    {
        if ($slug === '') {
            return null;
        }

        return $this->publicationBase()
            ->where('content_posts.slug', $slug)
            ->first();
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

        return $query;
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
