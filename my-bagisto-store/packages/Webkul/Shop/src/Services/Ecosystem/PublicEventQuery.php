<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 公开活动查询（ADR-0001 G1-D08 / 规划 §6.4 / G1-10；G4-01 扩展）。
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
 *
 * G4-01：
 *   · `q` 关键词只搜索 revision 的标题/摘要/城市/地点，LIKE 通配符转义；
 *   · `registration_state` 以 `event_registration_scopes.default` 为事实源；
 *   · 新增游标分页，page 模式保持原有合同。
 */
class PublicEventQuery
{
    public const PARTICIPATION_MODES = ['offline', 'online', 'hybrid'];

    /**
     * G4-01 报名状态筛选取值。语义见 G4-implementation-plan.md §3.1。
     */
    public const REGISTRATION_STATES = [
        'open',
        'upcoming',
        'full',
        'closed',
        'ended',
        'canceled',
        'external',
        'unavailable',
    ];

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
     * G4-01 游标分页。
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: Collection<int, Event>, has_more: bool, next_cursor: string|null}
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
            ->orderByDesc('events.published_at')
            ->orderByDesc('events.id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $items = $rows->slice(0, $perPage)->values();

        $nextCursor = null;

        if ($hasMore && $items->isNotEmpty()) {
            /** @var Event $last */
            $last = $items->last();
            $nextCursor = PublicListCursor::encode(
                $this->sortValue($last->published_at),
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
     * Sitemap 专用：只取公开活动的 id 与公开时间。
     *
     * 与列表共用 visibleBase，确保草稿/撤稿/净化版本过期的条目不会进入 sitemap。
     *
     * @return Collection<int, Event>
     */
    public function sitemapRows(int $limit): Collection
    {
        return $this->visibleBase()
            ->orderByDesc('events.published_at')
            ->orderByDesc('events.id')
            ->limit(max(1, $limit))
            ->get(['events.id', 'events.published_at']);
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

    public function findPublishedById(int $id): ?Event
    {
        return $this->findPublished($id);
    }

    /**
     * 批量读取当前公开活动（G4-02 收藏列表 / G4-03 关联解析）。
     *
     * 使用与列表相同的 visibleBase，确保 sanitizer 过期的正文不会被带出。
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, Event>
     */
    public function publishedByIds(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return new Collection();
        }

        return $this->visibleBase()->whereIn('events.id', $ids)->get();
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

        if (isset($filters['q'])) {
            $this->applyKeyword($query, (string) $filters['q']);
        }

        if (isset($filters['registration_state'])) {
            $this->applyRegistrationState($query, (string) $filters['registration_state']);
        }

        return $query;
    }

    /**
     * G4-01 关键词搜索。LIKE 的 `%` / `_` / `\` 必须转义，否则用户输入会变成通配符。
     */
    private function applyKeyword(Builder $query, string $keyword): void
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return;
        }

        $like = '%'.addcslashes($keyword, '\\%_').'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('public_revision.name', 'like', $like)
                ->orWhere('public_revision.summary', 'like', $like)
                ->orWhere('public_revision.city', 'like', $like)
                ->orWhere('public_revision.location_text', 'like', $like);
        });
    }

    /**
     * G4-01 报名状态筛选。
     *
     * 所有时间比较都先转 UTC；scope 以 `default` 为准（首期每活动一个 scope）。
     * `open` 额外要求平台双闸开启 —— 否则列表会把“窗口开着但平台停写”的活动
     * 误标成可报名，用户点进去必然失败。
     */
    private function applyRegistrationState(Builder $query, string $state): void
    {
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $notEnded = function (Builder $inner) use ($now): void {
            $inner->where(function (Builder $hosting): void {
                $hosting->whereNull('events.hosting_status')
                    ->orWhereNotIn('events.hosting_status', ['canceled', 'completed']);
            })->where(function (Builder $ends) use ($now): void {
                $ends->whereNull('events.ends_at')
                    ->orWhere('events.ends_at', '>', $now);
            });
        };

        $scopeExists = function (array $constraints) use ($now) {
            // whereExists 直接转发给 Query\Builder，闭包参数不是 Eloquent Builder。
            return function ($inner) use ($constraints, $now): void {
                $inner->selectRaw('1')
                    ->from('event_registration_scopes as rs')
                    ->whereColumn('rs.event_id', 'events.id')
                    ->where('rs.scope_key', 'default');

                foreach ($constraints as $constraint) {
                    $constraint($inner, $now);
                }
            };
        };

        switch ($state) {
            case 'canceled':
                $query->where('events.hosting_status', 'canceled');

                return;

            case 'ended':
                $query->where(function (Builder $inner) use ($now): void {
                    $inner->where('events.hosting_status', 'completed')
                        ->orWhere(function (Builder $ended) use ($now): void {
                            $ended->whereNotNull('events.ends_at')
                                ->where('events.ends_at', '<=', $now);
                        });
                });

                return;

            case 'external':
                $query->whereNotNull('public_revision.external_registration_url')
                    ->where('public_revision.external_registration_url', 'like', 'http%');

                return;

            case 'open':
                $platformOpen = (bool) config('ecosystem.events.registration_enabled', false)
                    && (bool) config('ecosystem.events.registration_write_verified', false);

                if (! $platformOpen) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $notEnded($query);
                $query->whereExists($scopeExists([
                    fn ($inner) => $inner->where('rs.status', 'open'),
                    fn ($inner, string $now) => $inner->where(function ($window) use ($now): void {
                        $window->whereNull('rs.registration_opens_at')
                            ->orWhere('rs.registration_opens_at', '<=', $now);
                    }),
                    fn ($inner, string $now) => $inner->where(function ($window) use ($now): void {
                        $window->whereNull('rs.registration_closes_at')
                            ->orWhere('rs.registration_closes_at', '>', $now);
                    }),
                    fn ($inner) => $inner->where(function ($capacity): void {
                        $capacity->whereNull('rs.capacity')
                            ->orWhere(function ($limited): void {
                                $limited->where('rs.capacity', '>', 0)
                                    ->whereColumn('rs.confirmed_count', '<', 'rs.capacity');
                            });
                    }),
                ]));

                return;

            case 'upcoming':
                $notEnded($query);
                $query->whereExists($scopeExists([
                    fn ($inner) => $inner->where('rs.status', 'open'),
                    fn ($inner, string $now) => $inner->whereNotNull('rs.registration_opens_at')
                        ->where('rs.registration_opens_at', '>', $now),
                ]));

                return;

            case 'full':
                $notEnded($query);
                $query->whereExists($scopeExists([
                    fn ($inner) => $inner->where('rs.status', 'open')
                        ->where('rs.capacity', '>', 0)
                        ->whereColumn('rs.confirmed_count', '>=', 'rs.capacity'),
                ]));

                return;

            case 'closed':
                $notEnded($query);
                $query->whereExists($scopeExists([
                    fn ($inner, string $now) => $inner->where(function ($closed) use ($now): void {
                        $closed->where('rs.status', '!=', 'open')
                            ->orWhere(function ($window) use ($now): void {
                                $window->whereNotNull('rs.registration_closes_at')
                                    ->where('rs.registration_closes_at', '<=', $now);
                            })
                            ->orWhere('rs.capacity', 0);
                    }),
                ]));

                return;

            case 'unavailable':
                $notEnded($query);
                $query->whereNotExists(function ($inner): void {
                    $inner->selectRaw('1')
                        ->from('event_registration_scopes as rs')
                        ->whereColumn('rs.event_id', 'events.id')
                        ->where('rs.scope_key', 'default');
                })->where(function (Builder $external): void {
                    $external->whereNull('public_revision.external_registration_url')
                        ->orWhere('public_revision.external_registration_url', 'not like', 'http%');
                });

                return;
        }
    }

    /**
     * 无时间排序值的行在 MySQL DESC 中排在最后；游标要显式区分“时间值”和 NULL。
     */
    private function applyCursor(Builder $query, ?string $sort, int $id): void
    {
        if ($sort === null) {
            $query->whereNull('events.published_at')
                ->where('events.id', '<', $id);

            return;
        }

        $query->where(function (Builder $inner) use ($sort, $id): void {
            $inner->where('events.published_at', '<', $sort)
                ->orWhere(function (Builder $sameTime) use ($sort, $id): void {
                    $sameTime->where('events.published_at', $sort)
                        ->where('events.id', '<', $id);
                })
                ->orWhereNull('events.published_at');
        });
    }

    /**
     * 游标指纹只包含筛选条件；分页参数变化不应导致游标失效。
     *
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
