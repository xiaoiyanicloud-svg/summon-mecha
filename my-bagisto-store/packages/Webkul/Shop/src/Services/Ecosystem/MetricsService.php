<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G4-06 · 运营指标。
 *
 * 先定义口径，再计算事实（定义同时返回给页面，避免“数字有、口径无”）：
 *   · 报名：按 attempt 计；request 重放不重复计；取消/重报不产生负数；
 *   · 席位：当前 confirmed / checked_in 的 attempt；与 scope.confirmed_count 应对账；
 *   · 内容访问：日聚合，同一访客 1 小时去重；关闭记录时不返回 0 冒充真实；
 *   · 作品：按提交版本计；展示/提审分别计，不混成“转化率”。
 *
 * 没有事实源的指标返回 `available=false`，调用方不得把 0 当作“没有数据源”。
 */
class MetricsService
{
    public function enabled(): bool
    {
        return (bool) config('ecosystem_g4.metrics.enabled', false);
    }

    /**
     * @param  array{from?: string, to?: string}  $input
     * @return array<string, mixed>
     */
    public function build(array $input = []): array
    {
        [$from, $to] = $this->range($input);

        return [
            'enabled'       => $this->enabled(),
            'range'         => [
                'from'     => $from->toDateString(),
                'to'       => $to->toDateString(),
                'timezone' => (string) config('app.timezone', 'Asia/Shanghai'),
            ],
            'definitions'   => $this->definitions(),
            'registrations' => $this->registrations($from, $to),
            'content'       => $this->content($from, $to),
            'competition'   => $this->competition($from, $to),
            'publishing'    => $this->publishing($from, $to),
        ];
    }

    /**
     * @param  array{from?: string, to?: string}  $input
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(array $input): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');
        $maxDays = max(1, (int) config('ecosystem_g4.metrics.max_range_days', 366));

        $to = isset($input['to']) && $input['to'] !== ''
            ? Carbon::parse((string) $input['to'], $timezone)->endOfDay()
            : Carbon::now($timezone)->endOfDay();

        $from = isset($input['from']) && $input['from'] !== ''
            ? Carbon::parse((string) $input['from'], $timezone)->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from->diffInDays($to) + 1 > $maxDays) {
            $from = $to->copy()->subDays($maxDays - 1)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * @return array<string, array{label: string, source: string, note: string}>
     */
    private function definitions(): array
    {
        return [
            'submitted' => [
                'label'  => '报名提交数',
                'source' => 'event_registration_attempts.submitted_at',
                'note'   => '期间内每次 attempt 计 1；请求重放不重复计。',
            ],
            'confirmed_seats' => [
                'label'  => '确认席位数',
                'source' => 'event_registration_attempts(status)',
                'note'   => '当前 confirmed / checked_in 的 attempt；与 scope.confirmed_count 对账。',
            ],
            'checked_in' => [
                'label'  => '签到数',
                'source' => 'event_registration_attempts.checked_in_at',
                'note'   => '期间内首次签到；重复扫描不重复计。',
            ],
            'canceled' => [
                'label'  => '取消数',
                'source' => 'event_registration_attempts.canceled_at',
                'note'   => '期间内取消；重报生成新 attempt，旧取消不重复计。',
            ],
            'reapplications' => [
                'label'  => '重报数',
                'source' => 'event_registration_attempts.attempt_no',
                'note'   => 'attempt_no >= 2 的新 attempt 按 submitted_at 计。',
            ],
            'content_views' => [
                'label'  => '内容访问',
                'source' => 'ecosystem_content_view_daily.views',
                'note'   => '日聚合；同一访客 1 小时去重；不存 IP/UA/用户 ID。',
            ],
            'submission_versions' => [
                'label'  => '作品提交版本',
                'source' => 'competition_submission_versions.submitted_at',
                'note'   => '同一 submission 的多个版本分别计。',
            ],
            'showcases_published' => [
                'label'  => '展示发布数',
                'source' => 'competition_showcases.published_at',
                'note'   => '已授权并发布的展示；不代表上架或下发。',
            ],
            'developer_handoff' => [
                'label'  => '进入技能提审',
                'source' => 'competition_showcases.developer_handoff_at',
                'note'   => '仅表示交接动作发生，真实提审仍受协议/包/闸门约束。',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registrations(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('event_registration_attempts')) {
            return ['available' => false, 'reason' => 'source_missing'];
        }

        $utcFrom = $from->copy()->utc()->format('Y-m-d H:i:s');
        $utcTo = $to->copy()->utc()->format('Y-m-d H:i:s');

        $attempts = DB::table('event_registration_attempts');

        $submitted = (clone $attempts)->whereBetween('submitted_at', [$utcFrom, $utcTo])->count();

        $seats = (clone $attempts)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->whereRaw('COALESCE(decided_at, submitted_at) BETWEEN ? AND ?', [$utcFrom, $utcTo])
            ->count();

        $checkedIn = (clone $attempts)->whereBetween('checked_in_at', [$utcFrom, $utcTo])->count();
        $canceled = (clone $attempts)->whereBetween('canceled_at', [$utcFrom, $utcTo])->count();
        $reapplications = (clone $attempts)
            ->where('attempt_no', '>=', 2)
            ->whereBetween('submitted_at', [$utcFrom, $utcTo])
            ->count();

        $byEvent = (clone $attempts)
            ->select('event_id', DB::raw('COUNT(*) as total'))
            ->whereBetween('submitted_at', [$utcFrom, $utcTo])
            ->groupBy('event_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $eventNames = [];

        if ($byEvent->isNotEmpty()) {
            $eventNames = DB::table('events')
                ->leftJoin('event_revisions', 'event_revisions.id', '=', 'events.published_revision_id')
                ->whereIn('events.id', $byEvent->pluck('event_id')->all())
                ->pluck('event_revisions.name', 'events.id')
                ->all();
        }

        return [
            'available'         => true,
            'submitted'         => $submitted,
            'confirmed_seats'   => $seats,
            'checked_in'        => $checkedIn,
            'canceled'          => $canceled,
            'reapplications'    => $reapplications,
            'by_event'          => $byEvent->map(fn ($row): array => [
                'event_id' => (int) $row->event_id,
                'name'     => $eventNames[(int) $row->event_id] ?? ('#'.$row->event_id),
                'total'    => (int) $row->total,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(Carbon $from, Carbon $to): array
    {
        if (! $this->enabled() || ! (bool) config('ecosystem_g4.metrics.content_views_enabled', false)) {
            return ['available' => false, 'reason' => 'content_views_disabled'];
        }

        if (! Schema::hasTable('ecosystem_content_view_daily')) {
            return ['available' => false, 'reason' => 'source_missing'];
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $total = (int) DB::table('ecosystem_content_view_daily')
            ->whereBetween('view_date', [$fromDate, $toDate])
            ->sum('views');

        $top = DB::table('ecosystem_content_view_daily')
            ->select('target_type', 'target_key', DB::raw('SUM(views) as total'))
            ->whereBetween('view_date', [$fromDate, $toDate])
            ->groupBy('target_type', 'target_key')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $contentSlugs = $top->where('target_type', 'content')->pluck('target_key')->all();
        $eventIds = $top->where('target_type', 'event')->pluck('target_key')
            ->map(static fn (mixed $id): int => (int) $id)->all();

        $contentTitles = $contentSlugs === [] ? [] : DB::table('content_posts')
            ->leftJoin('content_revisions', 'content_revisions.id', '=', 'content_posts.published_revision_id')
            ->whereIn('content_posts.slug', $contentSlugs)
            ->pluck('content_revisions.title', 'content_posts.slug')
            ->all();

        $eventTitles = $eventIds === [] ? [] : DB::table('events')
            ->leftJoin('event_revisions', 'event_revisions.id', '=', 'events.published_revision_id')
            ->whereIn('events.id', $eventIds)
            ->pluck('event_revisions.name', 'events.id')
            ->all();

        return [
            'available' => true,
            'total'     => $total,
            'top'       => $top->map(function ($row) use ($contentTitles, $eventTitles): array {
                $key = (string) $row->target_key;

                return [
                    'target_type' => (string) $row->target_type,
                    'target_key'  => $key,
                    'title'       => $row->target_type === 'content'
                        ? ($contentTitles[$key] ?? $key)
                        : ($eventTitles[(int) $key] ?? ('#'.$key)),
                    'views'       => (int) $row->total,
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function competition(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('competition_submission_versions')) {
            return ['available' => false, 'reason' => 'source_missing'];
        }

        $utcFrom = $from->copy()->utc()->format('Y-m-d H:i:s');
        $utcTo = $to->copy()->utc()->format('Y-m-d H:i:s');

        $submissionVersions = (int) DB::table('competition_submission_versions')
            ->whereBetween('submitted_at', [$utcFrom, $utcTo])
            ->count();

        $showcasesPublished = Schema::hasTable('competition_showcases')
            ? (int) DB::table('competition_showcases')
                ->where('status', 'published')
                ->whereBetween('published_at', [$utcFrom, $utcTo])
                ->count()
            : null;

        $handoffs = Schema::hasTable('competition_showcases')
            ? (int) DB::table('competition_showcases')
                ->whereNotNull('developer_handoff_at')
                ->whereBetween('developer_handoff_at', [$utcFrom, $utcTo])
                ->count()
            : null;

        return [
            'available'           => true,
            'submission_versions' => $submissionVersions,
            'showcases_published' => $showcasesPublished,
            'developer_handoff'   => $handoffs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishing(Carbon $from, Carbon $to): array
    {
        $utcFrom = $from->copy()->utc()->format('Y-m-d H:i:s');
        $utcTo = $to->copy()->utc()->format('Y-m-d H:i:s');

        return [
            'available'     => true,
            'events'        => (int) DB::table('events')
                ->where('publish_status', 'published')
                ->whereBetween('published_at', [$utcFrom, $utcTo])
                ->count(),
            'content_posts' => (int) DB::table('content_posts')
                ->where('publish_status', 'published')
                ->whereBetween('published_at', [$utcFrom, $utcTo])
                ->count(),
        ];
    }
}
