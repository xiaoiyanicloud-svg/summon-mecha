<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Shop\Http\Resources\Ecosystem\CompetitionResource;
use Webkul\Shop\Models\CompetitionEdition;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionAwardService;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Services\Ecosystem\CompetitionScheduleService;
use Webkul\Shop\Services\Ecosystem\CompetitionShowcaseService;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamService;
use Webkul\Shop\Services\Ecosystem\PublicEventQuery;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;

/**
 * 赛事赛务公开读取（G3-02 / G3-06）。
 *
 *   · 活动必须已公开（沿用 G1 发布状态）；
 *   · 只返回 active 赛项的公开字段，不返回规则安全联系引用；
 *   · 赛程只在 `public_schedule_enabled=true` 时读取；关闭时 404 且不查库；
 *   · kill switch 最先检查。
 */
class CompetitionPublicController extends APIController
{
    public function __construct(
        private readonly PublicReadGate $gate,
        private readonly PublicEventQuery $events,
        private readonly CompetitionScheduleService $schedule,
        private readonly CompetitionTeamService $teams,
        private readonly CompetitionResultService $results,
        private readonly CompetitionAwardService $awards,
        private readonly CompetitionShowcaseService $showcases,
    ) {}

    public function show(int $eventId): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! (bool) config('ecosystem.events.public_enabled', false)) {
            return $this->notFound();
        }

        $event = $this->events->findPublished($eventId);

        if ($event === null) {
            return $this->notFound();
        }

        $edition = CompetitionEdition::query()->where('event_id', (int) $event->getKey())->first();

        if ($edition === null) {
            return $this->notFound();
        }

        $tracks = CompetitionTrack::query()
            ->with('scope')
            ->where('edition_id', $edition->getKey())
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->map(static fn (CompetitionTrack $track): array => CompetitionResource::track($track))
            ->values()
            ->all();

        $customer = auth()->guard('customer')->user();
        $myTeams = [];

        if ($customer !== null) {
            foreach ($this->teams->membershipsFor($customer) as $membership) {
                $team = $membership->team;

                if ($team === null || (int) $team->edition_id !== (int) $edition->getKey()) {
                    continue;
                }

                $myTeams[] = [
                    'team_id'    => (int) $team->getKey(),
                    'name'       => (string) $team->name,
                    'track_key'  => $team->track?->track_key,
                    'role'       => (string) $membership->role,
                    'status'     => (string) $membership->status,
                ];
            }
        }

        $schedule = (bool) config('ecosystem_competition.public_schedule_enabled', false)
            ? $this->schedule->publicSchedule((int) $event->getKey())
            : [];

        return $this->ok([
            'event_id'    => (int) $event->getKey(),
            'edition'     => [
                'id'         => (int) $edition->getKey(),
                'edition_no' => (int) $edition->edition_no,
                'name'       => (string) $edition->name,
                'timezone'   => (string) $edition->timezone,
                'starts_at'  => $edition->starts_at?->toIso8601String(),
                'ends_at'    => $edition->ends_at?->toIso8601String(),
            ],
            'tracks'      => $tracks,
            'my_teams'    => $myTeams,
            'schedule_enabled' => (bool) config('ecosystem_competition.public_schedule_enabled', false),
            'schedule'    => $schedule,
        ]);
    }

    public function schedule(int $eventId): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        // 开关关闭：不查库。
        if (! (bool) config('ecosystem_competition.public_schedule_enabled', false)
            || ! (bool) config('ecosystem.events.public_enabled', false)) {
            return $this->notFound();
        }

        $event = $this->events->findPublished($eventId);

        if ($event === null) {
            return $this->notFound();
        }

        return $this->ok([
            'event_id' => (int) $event->getKey(),
            'timezone' => (string) ($event->timezone ?? 'Asia/Shanghai'),
            'schedule' => $this->schedule->publicSchedule((int) $event->getKey()),
        ]);
    }

    /**
     * G3-08 公开成绩：独立开关，关闭时 404 且不查库。
     * 只返回已公布版本；修订中返回旧版本并标 under_review。
     */
    public function results(int $eventId): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! (bool) config('ecosystem_competition.public_results_enabled', false)
            || ! (bool) config('ecosystem.events.public_enabled', false)) {
            return $this->notFound();
        }

        $event = $this->events->findPublished($eventId);

        if ($event === null) {
            return $this->notFound();
        }

        $results = $this->results->publicResults((int) $event->getKey());
        $awards = $this->awards->publicAwards((int) $event->getKey());

        foreach ($results as $index => $track) {
            $results[$index]['awards'] = $awards[(string) $track['track_key']] ?? [];
        }

        return $this->ok([
            'event_id' => (int) $event->getKey(),
            'results'  => $results,
        ]);
    }

    /**
     * G3-11 公开展示：独立开关；只返回已发布且授权仍有效的展示。
     * 不含附件、repo、video 或内部备注。
     */
    public function showcases(int $eventId): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! (bool) config('ecosystem_competition.public_showcase_enabled', false)
            || ! (bool) config('ecosystem.events.public_enabled', false)) {
            return $this->notFound();
        }

        $event = $this->events->findPublished($eventId);

        if ($event === null) {
            return $this->notFound();
        }

        return $this->ok([
            'event_id'  => (int) $event->getKey(),
            'showcases' => $this->showcases->publicShowcases((int) $event->getKey()),
        ]);
    }

    private function publicReadUnavailable(): ?JsonResponse
    {
        try {
            $this->gate->assertAvailable();
        } catch (PublicReadUnavailableException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return null;
    }

    private function notFound(): JsonResponse
    {
        return $this->fail(ApiErrorCode::COMPETITION_NOT_FOUND, '赛事赛务信息尚未公开或不存在。', 404);
    }
}
