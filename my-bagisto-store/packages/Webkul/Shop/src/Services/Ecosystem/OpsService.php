<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G4-07 · 运营工作台事实聚合。
 *
 * 只做“取事实 + 裁剪”，不做处置：处置动作走既有发布/报名/赛务页面。
 * 每个分区返回可定位的行（ID/标题/时间/负责人），不是只有一个总数。
 *
 * 权限边界：
 *   · 路由级 `ecosystem.ops.view`；
 *   · 报名行还要有该活动的 review 或 pii 能力；PII 明文只在同时具备
 *     `ecosystem.registrations.pii` 与活动 `pii` 能力时输出。
 */
class OpsService
{
    public function __construct(
        private readonly RegistrationStaffScope $staff,
        private readonly RegistrationContactVault $vault,
        private readonly OperationLogger $logger,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ecosystem_g4.ops.enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?Admin $admin, bool $canViewPii): array
    {
        $limit = max(1, (int) config('ecosystem_g4.ops.max_items_per_section', 50));

        return [
            'enabled'     => $this->enabled(),
            'responsible' => (array) config('ecosystem_g4.ops.responsible', []),
            'reviews'     => $this->pendingRevisions($limit),
            'registrations' => $this->pendingRegistrations($admin, $canViewPii, $limit),
            'closing_soon' => $this->closingSoon($limit),
            'notifications' => $this->notificationFailures($limit),
            'sources'      => $this->sourcesDue($limit),
            'dispositions' => $this->recentDispositions($limit),
        ];
    }

    /**
     * 通知失败重排：只允许 failed 且无租约的记录，避免抢走正在消费的任务。
     */
    public function retryOutbox(int $id, int $adminId): void
    {
        $row = DB::table('ecosystem_outbox')->where('id', $id)->first();

        if ($row === null || (string) $row->status !== 'failed' || $row->lease_owner !== null) {
            throw new RuntimeException('只有未租用的失败通知可以重排。');
        }

        DB::table('ecosystem_outbox')->where('id', $id)->update([
            'status'       => 'pending',
            'attempts'     => 0,
            'available_at' => now('UTC')->format('Y-m-d H:i:s'),
            'last_error'   => null,
            'updated_at'   => now(),
        ]);

        $this->logger->log('admin', $adminId, 'ecosystem.outbox.retry', 'ecosystem_outbox', $id, [
            'event_type' => (string) $row->event_type,
            'aggregate'  => (string) $row->aggregate_type.':'.(string) $row->aggregate_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRevisions(int $limit): array
    {
        $events = DB::table('event_revisions as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->where('r.review_state', 'in_review')
            ->orderBy('r.submitted_at')
            ->limit($limit)
            ->get(['r.id as revision_id', 'r.event_id as parent_id', 'r.name as title', 'r.submitted_at']);

        $contents = DB::table('content_revisions as r')
            ->join('content_posts as p', 'p.id', '=', 'r.post_id')
            ->where('r.review_state', 'in_review')
            ->orderBy('r.submitted_at')
            ->limit($limit)
            ->get(['r.id as revision_id', 'r.post_id as parent_id', 'r.title as title', 'r.submitted_at', 'p.slug']);

        return [
            'count' => $events->count() + $contents->count(),
            'events' => $events->map(fn ($row): array => [
                'type'         => 'event',
                'parent_id'    => (int) $row->parent_id,
                'revision_id'  => (int) $row->revision_id,
                'title'        => (string) $row->title,
                'submitted_at' => $this->localTime($row->submitted_at),
                'age_hours'    => $this->ageHours($row->submitted_at),
            ])->all(),
            'contents' => $contents->map(fn ($row): array => [
                'type'         => 'content',
                'parent_id'    => (int) $row->parent_id,
                'revision_id'  => (int) $row->revision_id,
                'slug'         => (string) $row->slug,
                'title'        => (string) $row->title,
                'submitted_at' => $this->localTime($row->submitted_at),
                'age_hours'    => $this->ageHours($row->submitted_at),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRegistrations(?Admin $admin, bool $canViewPii, int $limit): array
    {
        if ($admin === null) {
            return ['count' => 0, 'items' => [], 'pii_visible' => false];
        }

        $base = DB::table('event_registration_attempts as a')
            ->join('events as e', 'e.id', '=', 'a.event_id')
            ->leftJoin('event_registration_scopes as s', 's.id', '=', 'a.scope_id')
            ->where('a.status', 'submitted')
            ->orderBy('a.id');

        $items = [];
        $piiVisible = false;
        $lastId = 0;
        $scanned = 0;

        // 分页扫描，避免“前 150 条都属于别人的活动”导致有权限的人看不到自己的待办。
        while (count($items) < $limit && $scanned < 5000) {
            $rows = (clone $base)
                ->where('a.id', '>', $lastId)
                ->limit(200)
                ->get([
                    'a.id as attempt_id',
                    'a.event_id',
                    'a.submitted_at',
                    'a.contact_name_enc',
                    'a.contact_phone_enc',
                    'a.contact_city',
                    'e.name as event_name',
                    's.scope_key',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $lastId = (int) $row->attempt_id;
                $eventId = (int) $row->event_id;

                $hasReview = $this->staff->hasCapability($eventId, 'review', $admin);
                $hasPii = $this->staff->hasCapability($eventId, 'pii', $admin);

                if (! $hasReview && ! $hasPii) {
                    continue;
                }

                $reveal = $canViewPii && $hasPii;

                if ($reveal) {
                    $piiVisible = true;
                    $name = $this->vault->decryptName((string) $row->contact_name_enc);
                    $phone = $this->vault->decryptPhone((string) $row->contact_phone_enc);
                } else {
                    $masked = $this->vault->maskedContact(
                        (string) $row->contact_name_enc,
                        (string) $row->contact_phone_enc,
                        $row->contact_city !== null ? (string) $row->contact_city : null,
                    );
                    $name = $masked['name'];
                    $phone = $masked['phone'];
                }

                $items[] = [
                    'attempt_id'   => (int) $row->attempt_id,
                    'event_id'     => $eventId,
                    'event_name'   => (string) $row->event_name,
                    'name'         => $name,
                    'phone'        => $phone,
                    'city'         => $row->contact_city !== null ? (string) $row->contact_city : '',
                    'submitted_at' => $this->localTime($row->submitted_at),
                    'age_hours'    => $this->ageHours($row->submitted_at),
                ];

                if (count($items) >= $limit) {
                    break 2;
                }
            }
        }
        return [
            'count'       => count($items),
            'items'       => $items,
            'pii_visible' => $piiVisible,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function closingSoon(int $limit): array
    {
        $hours = max(1, (int) config('ecosystem_g4.ops.closing_soon_hours', 48));
        $now = now('UTC');
        $until = $now->copy()->addHours($hours);

        $rows = DB::table('event_registration_scopes as s')
            ->join('events as e', 'e.id', '=', 's.event_id')
            ->where('s.status', 'open')
            ->whereNotNull('s.registration_closes_at')
            ->whereBetween('s.registration_closes_at', [
                $now->format('Y-m-d H:i:s'),
                $until->format('Y-m-d H:i:s'),
            ])
            ->orderBy('s.registration_closes_at')
            ->limit($limit)
            ->get([
                's.id as scope_id',
                's.event_id',
                's.scope_key',
                's.capacity',
                's.confirmed_count',
                's.registration_closes_at',
                'e.name as event_name',
            ]);

        return [
            'window_hours' => $hours,
            'count'        => $rows->count(),
            'items'        => $rows->map(fn ($row): array => [
                'scope_id'    => (int) $row->scope_id,
                'event_id'    => (int) $row->event_id,
                'scope_key'   => (string) $row->scope_key,
                'event_name'  => (string) $row->event_name,
                'capacity'    => $row->capacity !== null ? (int) $row->capacity : null,
                'confirmed'   => (int) $row->confirmed_count,
                'closes_at'   => $this->localTime($row->registration_closes_at),
                'hours_left'  => $this->hoursUntil($row->registration_closes_at),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationFailures(int $limit): array
    {
        $rows = DB::table('ecosystem_outbox')
            ->where('status', 'failed')
            ->whereNull('lease_owner')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'aggregate_type', 'aggregate_id', 'event_type', 'attempts', 'last_error', 'updated_at']);

        return [
            'count' => $rows->count(),
            'items' => $rows->map(fn ($row): array => [
                'id'             => (int) $row->id,
                'aggregate_type' => (string) $row->aggregate_type,
                'aggregate_id'   => (int) $row->aggregate_id,
                'event_type'     => (string) $row->event_type,
                'attempts'       => (int) $row->attempts,
                // last_error 可能含内部路径；截断并只在后台输出。
                'last_error'     => mb_substr((string) $row->last_error, 0, 300),
                'updated_at'     => $this->localTime($row->updated_at),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcesDue(int $limit): array
    {
        $days = max(1, (int) config('ecosystem_g4.ops.source_reverify_days', 180));
        $cutoff = now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        $events = DB::table('event_revisions as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->where('r.review_state', 'approved')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('r.source_verified_at')
                    ->orWhere('r.source_verified_at', '<', $cutoff);
            })
            ->orderBy('r.source_verified_at')
            ->limit($limit)
            ->get(['r.id as revision_id', 'r.event_id as parent_id', 'r.name as title', 'r.source_name', 'r.source_verified_at']);

        $contents = DB::table('content_revisions as r')
            ->join('content_posts as p', 'p.id', '=', 'r.post_id')
            ->where('r.review_state', 'approved')
            ->whereExists(function ($query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('content_sources as cs')
                    ->whereColumn('cs.revision_id', 'r.id')
                    ->where(function ($source) use ($cutoff): void {
                        $source->whereNull('cs.source_verified_at')
                            ->orWhere('cs.source_verified_at', '<', $cutoff);
                    });
            })
            ->orderBy('r.id')
            ->limit($limit)
            ->get(['r.id as revision_id', 'r.post_id as parent_id', 'r.title', 'p.slug']);

        return [
            'window_days' => $days,
            'count'       => $events->count() + $contents->count(),
            'events'      => $events->map(fn ($row): array => [
                'type'              => 'event',
                'parent_id'         => (int) $row->parent_id,
                'revision_id'       => (int) $row->revision_id,
                'title'             => (string) $row->title,
                'source_name'       => (string) $row->source_name,
                'source_verified_at' => $this->localTime($row->source_verified_at),
            ])->all(),
            'contents'    => $contents->map(fn ($row): array => [
                'type'        => 'content',
                'parent_id'   => (int) $row->parent_id,
                'revision_id' => (int) $row->revision_id,
                'slug'        => (string) $row->slug,
                'title'       => (string) $row->title,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recentDispositions(int $limit): array
    {
        $days = max(1, (int) config('ecosystem_g4.ops.retraction_window_days', 30));
        $cutoff = now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        $events = DB::table('events')
            ->whereIn('disposition', ['withheld', 'retracted'])
            ->where('updated_at', '>=', $cutoff)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'name', 'disposition', 'updated_at']);

        $contents = DB::table('content_posts')
            ->whereIn('disposition', ['withheld', 'retracted'])
            ->where('updated_at', '>=', $cutoff)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'slug', 'disposition', 'updated_at']);

        return [
            'window_days' => $days,
            'count'       => $events->count() + $contents->count(),
            'events'      => $events->map(fn ($row): array => [
                'type'        => 'event',
                'id'          => (int) $row->id,
                'title'       => (string) $row->name,
                'disposition' => (string) $row->disposition,
                'updated_at'  => $this->localTime($row->updated_at),
            ])->all(),
            'contents'    => $contents->map(fn ($row): array => [
                'type'        => 'content',
                'id'          => (int) $row->id,
                'slug'        => (string) $row->slug,
                'disposition' => (string) $row->disposition,
                'updated_at'  => $this->localTime($row->updated_at),
            ])->all(),
        ];
    }

    private function localTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')
            ->timezone((string) config('app.timezone', 'Asia/Shanghai'))
            ->toIso8601String();
    }

    private function ageHours(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) Carbon::parse((string) $value, 'UTC')->diffInHours(now('UTC')));
    }

    private function hoursUntil(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) now('UTC')->diffInHours(Carbon::parse((string) $value, 'UTC')));
    }
}
