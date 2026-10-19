<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Http\Resources\Ecosystem\EventPublicResource;
use Webkul\Shop\Http\Resources\Ecosystem\MediaPublicResource;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\PublicBodyRenderer;
use Webkul\Shop\Services\Ecosystem\PublicEventQuery;
use Webkul\Shop\Services\Ecosystem\PublicMediaResolver;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;
use Webkul\Shop\Services\Ecosystem\PublicRegistrationState;

/**
 * 公开活动 API（ADR-0001 G1-D08 / 规划 §6 / G1-10）。
 *
 * 公开读取只认 `published_revision_id` + `review_state=approved`：
 *   · 列表：published + normal + 有效已批准公开版本；稳定排序 + 分页 + 白名单筛选；
 *   · 详情：不存在 / 草稿 / 待审 / 已批准未发布 / 已排期 / 暂缓统一 404；
 *     已撤稿且曾发布 → 410，中性文案，不回显旧内容；
 *   · kill switch（G1-09）最先检查，命中 503，且不查库；
 *   · 域开关关闭时列表 `{data:[]}`、详情 404，同样不查库。
 *
 * 旧站内报名 `POST /api/events/{id}/register` 保持 G0 停写语义，不在本批接 G2。
 */
class EventController extends APIController
{
    public function __construct(
        private readonly PublicReadGate $gate,
        private readonly PublicEventQuery $events,
        private readonly PublicMediaResolver $media,
        private readonly PublicRegistrationState $registration,
        private readonly PublicBodyRenderer $renderer,
    ) {}

    /**
     * 活动列表。
     */
    public function index(Request $request): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        // 域开关关闭：不查库，保持旧前端的空列表语义。
        if (! $this->publicEnabled()) {
            return response()->json(['data' => []]);
        }

        $validator = Validator::make($request->query(), $this->indexRules());

        if ($validator->fails()) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '筛选参数不合法。',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        $filters = $this->filters($validator->validated());

        if ($rangeError = $this->assertDateRange($filters, 'starts_from', 'starts_to')) {
            return $rangeError;
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? PublicEventQuery::DEFAULT_PER_PAGE);

        unset($filters['page'], $filters['per_page']);

        $paginator = $this->events->paginate($filters, $page, $perPage);

        $stale = $this->events->countIncompatibleSanitizer($filters);

        if ($stale > 0) {
            Log::error('ecosystem.public_event.sanitizer_version_mismatch', [
                'scope' => 'list',
                'count' => $stale,
            ]);
        }

        $revisionIds = $paginator->getCollection()
            ->pluck('published_revision_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $refsByRevision = $this->media->approvedRefsByRevision('event_revision', $revisionIds);

        $data = [];

        foreach ($paginator->items() as $event) {
            /** @var Event $event */
            $revision = $event->publishedRevision;

            if (! $revision instanceof EventRevision) {
                continue;
            }

            $data[] = $this->toDto($event, $revision, $refsByRevision[(int) $revision->id] ?? []);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * 活动详情。
     */
    public function show(int $id): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! $this->publicEnabled()) {
            return $this->eventNotFound();
        }

        $event = $this->events->findPublished($id);

        if (! $event) {
            return $this->events->findRetracted($id)
                ? $this->fail(ApiErrorCode::EVENT_UNAVAILABLE, '该赛事已撤稿，内容不再提供。', 410)
                : $this->eventNotFound();
        }

        $revision = $event->publishedRevision;

        if (! $revision instanceof EventRevision) {
            return $this->eventNotFound();
        }

        // fail-closed：正文与当前净化版本不一致时，绝不输出旧 HTML。
        if (! $this->events->bodyIsRenderable($revision)) {
            Log::error('ecosystem.public_event.sanitizer_version_mismatch', [
                'scope'            => 'detail',
                'event_id'         => (int) $event->id,
                'revision_id'      => (int) $revision->id,
                'sanitizer_version' => (string) $revision->sanitizer_version,
            ]);

            return $this->eventNotFound();
        }

        $refs = $this->media->approvedRefsByRevision('event_revision', [(int) $revision->id]);

        return response()->json([
            'data' => $this->toDto($event, $revision, $refs[(int) $revision->id] ?? []),
        ]);
    }

    /**
     * 赛事报名（G0 停写语义，本批不改）。
     *
     * 停写检查必须放在最前面 —— 在任何赛事查询/参数校验/写库之前返回。
     */
    public function register(Request $request, int $id): JsonResponse
    {
        if (! $this->registrationWritable()) {
            return $this->fail(
                ApiErrorCode::EVENT_REGISTRATION_DISABLED,
                (string) config('ecosystem.events.registration_disabled_reason', '站内报名正在升级，暂未开放。'),
                403
            );
        }

        $event = Event::find($id);

        if (! $event) {
            return $this->fail(ApiErrorCode::EVENT_NOT_FOUND, '赛事不存在。', 404);
        }

        if ($event->status === 'ended') {
            return $this->fail(ApiErrorCode::EVENT_ENDED, '该赛事已结束报名。', 422);
        }

        if ($event->status !== 'registering') {
            return $this->fail(ApiErrorCode::EVENT_NOT_REGISTERING, '当前不在报名阶段。', 422);
        }

        $request->validate([
            'name'  => 'required|string',
            'phone' => 'required|string',
            'city'  => 'nullable|string',
            'note'  => 'nullable|string',
        ]);

        $customer = auth()->guard('customer')->user();

        // 同一人同一赛事只报一次
        $exists = EventRegistration::where('event_id', $id)
            ->where('phone', $request->input('phone'))
            ->exists();

        if ($exists) {
            return $this->fail(ApiErrorCode::EVENT_ALREADY_REGISTERED, '该手机号已报名过此赛事。', 422);
        }

        $registration = EventRegistration::create([
            'event_id'    => $id,
            'customer_id' => $customer?->id,
            'name'        => $request->input('name'),
            'phone'       => $request->input('phone'),
            'city'        => $request->input('city', ''),
            'note'        => $request->input('note', ''),
        ]);

        return response()->json([
            'success'      => true,
            'message'      => '报名成功',
            'registration' => ['id' => $registration->id, 'event' => $event->name],
        ]);
    }

    /**
     * @param  array<int, \Webkul\Shop\Models\EcosystemMediaRef>  $refs
     * @return array<string, mixed>
     */
    private function toDto(Event $event, EventRevision $revision, array $refs): array
    {
        $media = MediaPublicResource::forEvent((int) $event->id, $refs);

        $allowedMediaIds = array_map(
            static fn (\Webkul\Shop\Models\EcosystemMediaRef $ref): int => (int) $ref->media_id,
            $refs,
        );

        $bodyHtml = $this->renderer->render(
            $revision->detail_html,
            $allowedMediaIds,
            static fn (int $mediaId): string => sprintf(
                '/api/events/%d/media/%d',
                (int) $event->id,
                $mediaId,
            ),
        );

        return (new EventPublicResource(
            $event,
            $revision,
            $this->registration->forEvent($event, $revision),
            $bodyHtml,
            $media,
        ))->toArray();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function indexRules(): array
    {
        return [
            'page'               => ['sometimes', 'integer', 'min:1'],
            'per_page'           => ['sometimes', 'integer', 'min:1', 'max:'.PublicEventQuery::MAX_PER_PAGE],
            'kind'               => ['sometimes', 'string', Rule::in((array) config('ecosystem_publishing.event_kinds', []))],
            'participation_mode' => ['sometimes', 'string', Rule::in(PublicEventQuery::PARTICIPATION_MODES)],
            'city'               => ['sometimes', 'string', 'max:100'],
            'hosting_status'     => ['sometimes', 'string', Rule::in((array) config('ecosystem_publishing.event_hosting_statuses', []))],
            'starts_from'        => ['sometimes', 'date'],
            'starts_to'          => ['sometimes', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function filters(array $validated): array
    {
        return array_filter(
            $validated,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function assertDateRange(array $filters, string $from, string $to): ?JsonResponse
    {
        if (! isset($filters[$from], $filters[$to])) {
            return null;
        }

        if (Carbon::parse((string) $filters[$from])->greaterThan(Carbon::parse((string) $filters[$to]))) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '筛选参数不合法。',
                ['errors' => [$from => ['开始时间不得晚于结束时间。']]],
            );
        }

        return null;
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

    private function eventNotFound(): JsonResponse
    {
        // 不存在与未公开共用同一文案和结构，不泄露对象是否存在。
        return $this->fail(ApiErrorCode::EVENT_NOT_FOUND, '赛事尚未公开发布或不存在。', 404);
    }

    /**
     * 公开读取域开关。关闭时 index/show 不查数据库。
     */
    private function publicEnabled(): bool
    {
        return (bool) config('ecosystem.events.public_enabled', false);
    }

    /**
     * 双重闸：产品开关 + G2 写能力验收都通过才允许写库。
     */
    private function registrationWritable(): bool
    {
        return (bool) config('ecosystem.events.registration_enabled', false)
            && (bool) config('ecosystem.events.registration_write_verified', false);
    }
}
