<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 公开活动查询（ADR-0001 G1-D08 / 规划 §6.4 / G1-10）。
 *
 * 可见性只有一条真相链：
 *   `publish_status=published` + `disposition=normal`
 *   + `published_revision_id` 指向**同一活动**的 `review_state=approved` revision。
 *
 * 公开读取**只认** `published_revision_id`，绝不回落到 working / approved 候选，
 * 也不读取旧 `events.detail` / `hot` / `teams` / `sort`。
 *
 * 排序：`published_at DESC, id DESC`（id 决胜，保证跨页稳定）。
 * 待定时间（`starts_at IS NULL`）不影响排序主键，不会造成跨页漂移。
 */
class PublicEventQuery
{
    public const PARTICIPATION_MODES = ['offline', 'online', 'hybrid'];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return $this->applyFilters($this->visibleBase(), $filters)
            ->orderByDesc('events.published_at')
            ->orderByDesc('events.id')
            ->paginate($perPage, ['events.*'], 'page', max(1, $page));
    }

    /**
     * 详情可见对象；不含 sanitizer 版本判断（由 controller fail-closed 并记录 error）。
     */
    public function findPublished(int $id): ?Event
    {
        if ($id <= 0) {
            return null;
        }

        return $this->publicationBase()
            ->where('events.id', $id)
            ->first();
    }

    /**
     * 已撤稿且**曾经发布过**的对象（410）。从未发布过的 retracted 仍按 404 处理。
     */
    public function findRetracted(int $id): ?Event
    {
        if ($id <= 0) {
            return null;
        }

        return Event::query()
            ->whereKey($id)
            ->where('disposition', 'retracted')
            ->where(function (Builder $query): void {
                $query->whereNotNull('published_at')
                    ->orWhereNotNull('first_published_at');
            })
            ->first();
    }

    /**
     * 正文是否可按当前净化版本输出（ADR G1-D07 / 规划 §6.3）。
     *
     * 无正文时不涉及旧 HTML，允许通过；有正文时必须精确匹配当前 sanitizer_version。
     */
    public function bodyIsRenderable(EventRevision $revision): bool
    {
        if ($revision->detail_html === null || trim((string) $revision->detail_html) === '') {
            return true;
        }

        return (string) $revision->sanitizer_version === $this->sanitizerVersion();
    }

    /**
     * 列表中被 fail-closed 排除的「有正文但净化版本过期」行数，仅用于 error 日志。
     *
     * @param  array<string, mixed>  $filters
     */
    public function countIncompatibleSanitizer(array $filters): int
    {
        return $this->applyFilters($this->publicationBase(), $filters)
            ->whereNotNull('public_revision.detail_html')
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
                $query->whereNull('public_revision.detail_html')
                    ->orWhere('public_revision.sanitizer_version', $this->sanitizerVersion());
            });
    }

    private function publicationBase(): Builder
    {
        return Event::query()
            ->join('event_revisions as public_revision', 'public_revision.id', '=', 'events.published_revision_id')
            ->whereColumn('public_revision.event_id', 'events.id')
            ->where('events.publish_status', 'published')
            ->where('events.disposition', 'normal')
            ->whereNotNull('events.published_revision_id')
            ->where('public_revision.review_state', 'approved')
            ->with('publishedRevision')
            ->select('events.*');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (isset($filters['kind'])) {
            $query->where('events.kind', (string) $filters['kind']);
        }

        if (isset($filters['participation_mode'])) {
            $query->where('public_revision.participation_mode', (string) $filters['participation_mode']);
        }

        if (isset($filters['city'])) {
            $query->where('public_revision.city', (string) $filters['city']);
        }

        if (isset($filters['hosting_status'])) {
            $query->where('events.hosting_status', (string) $filters['hosting_status']);
        }

        if (isset($filters['starts_from'])) {
            $query->where('public_revision.starts_at', '>=', $this->utc($filters['starts_from']));
        }

        if (isset($filters['starts_to'])) {
            $query->where('public_revision.starts_at', '<=', $this->utc($filters['starts_to']));
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
