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
use Webkul\Shop\Services\Ecosystem\ContentViewRecorder;
use Webkul\Shop\Services\Ecosystem\FavoriteService;
use Webkul\Shop\Services\Ecosystem\PublicBodyRenderer;
use Webkul\Shop\Services\Ecosystem\PublicCursorException;
use Webkul\Shop\Services\Ecosystem\PublicEventQuery;
use Webkul\Shop\Services\Ecosystem\PublicMediaResolver;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;
use Webkul\Shop\Services\Ecosystem\PublicRegistrationState;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Services\Ecosystem\RelationService;

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
        private readonly RegistrationSubmissionService $submissions,
        private readonly FavoriteService $favorites,
        private readonly RelationService $relations,
        private readonly ContentViewRecorder $views,
    ) {}

    /**
     * 活动列表。
     *
     * G4-01：在原有 page 模式之外增加 cursor 模式；`q` 与 `registration_state`
     * 受 `ecosystem_g4.search.enabled` 控制，关闭时明确 400，不静默忽略。
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

        $validated = $validator->validated();

        if ($searchError = $this->assertSearchEnabled($validated)) {
            return $searchError;
        }

        $mode = (string) ($validated['mode'] ?? (isset($validated['cursor']) ? 'cursor' : 'page'));

        if ($mode === 'page' && isset($validated['cursor'])) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '筛选参数不合法。',
                ['errors' => ['cursor' => ['page 与 cursor 不能同时使用。']]],
            );
        }

        $filters = $this->filters($validated);

        if (isset($filters['q']) && trim((string) $filters['q']) === '') {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '筛选参数不合法。',
                ['errors' => ['q' => ['关键词不能为空。']]],
            );
        }

        if ($rangeError = $this->assertDateRange($filters, 'starts_from', 'starts_to')) {
            return $rangeError;
        }

        $perPage = (int) ($validated['per_page'] ?? PublicEventQuery::DEFAULT_PER_PAGE);

        if ($mode === 'cursor') {
            try {
                $cursorResult = $this->events->cursorPaginate(
                    $filters,
                    isset($validated['cursor']) ? (string) $validated['cursor'] : null,
                    $perPage,
                );
            } catch (PublicCursorException $exception) {
                return $this->reject(
                    ApiErrorCode::VALIDATION_ERROR,
                    $exception->getMessage(),
                    ['errors' => ['cursor' => [$exception->getMessage()]], 'reason' => $exception->reason()],
                );
            }

            $collection = $cursorResult['items'];
            $meta = [
                'mode'        => 'cursor',
                'per_page'    => $perPage,
                'has_more'    => $cursorResult['has_more'],
                'next_cursor' => $cursorResult['next_cursor'],
                'total'       => null,
                'filters'     => $this->echoFilters($filters),
            ];
        } else {
            $paginator = $this->events->paginate($filters, (int) ($validated['page'] ?? 1), $perPage);
            $collection = $paginator->getCollection();

            $meta = [
                'mode'         => 'page',
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'filters'      => $this->echoFilters($filters),
            ];
        }

        $stale = $this->events->countIncompatibleSanitizer($filters);

        if ($stale > 0) {
            Log::error('ecosystem.public_event.sanitizer_version_mismatch', [
                'scope' => 'list',
                'count' => $stale,
            ]);
        }

        $revisionIds = $collection
            ->pluck('published_revision_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $refsByRevision = $this->media->approvedRefsByRevision('event_revision', $revisionIds);
        $customerId = auth()->guard('customer')->id();

        $favoriteFlags = $customerId !== null
            ? $this->favorites->flagsFor((int) $customerId, FavoriteService::TYPE_EVENT, $collection->pluck('id')->all())
            : [];

        $data = [];

        foreach ($collection as $event) {
            /** @var Event $event */
            $revision = $event->publishedRevision;

            if (! $revision instanceof EventRevision) {
                continue;
            }

            $data[] = $this->toDto(
                $event,
                $revision,
                $refsByRevision[(int) $revision->id] ?? [],
                $customerId,
                isset($favoriteFlags[(string) $event->id]),
            );
        }

        return response()->json([
            'data' => $data,
            'meta' => $meta,
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
        $customerId = auth()->guard('customer')->id();

        $this->views->record(ContentViewRecorder::TARGET_EVENT, (string) $event->id, $customerId !== null ? (int) $customerId : null);

        return response()->json([
            'data' => $this->toDto(
                $event,
                $revision,
                $refs[(int) $revision->id] ?? [],
                $customerId,
                $customerId !== null
                    && $this->favorites->isFavorited((int) $customerId, FavoriteService::TYPE_EVENT, (string) $event->id),
                $this->relations->forSource(RelationService::SOURCE_EVENT, (int) $event->id),
            ),
        ]);
    }

    /**
     * 赛事报名（G2-04）。
     *
     * 停写检查必须放在最前面 —— 在任何赛事查询/参数校验/写库之前返回。
     * 双重闸打开后，旧“姓名+手机号”直写不再可用：必须登录、带 request_key
     * 与表单/规则/隐私版本；否则明确要求客户端刷新，而不是伪造成功。
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

        $validator = Validator::make($request->all(), [
            'request_key'       => ['required', 'string', 'max:100'],
            'form_version'      => ['required', 'integer', 'min:1'],
            'privacy_version'   => ['required', 'string', 'max:50'],
            'rules_version_id'  => ['nullable', 'integer', 'min:1'],
            'consent'           => ['required', 'accepted'],
            'name'              => ['required', 'string', 'max:50'],
            'phone'             => ['required', 'string', 'max:20'],
            'city'              => ['nullable', 'string', 'max:100'],
            'note'              => ['nullable', 'string', 'max:500'],
            'expected_attempt_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '报名参数不合法。',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        $customer = auth()->guard('customer')->user();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录后再报名。', 401);
        }

        $event = Event::query()->find($id);

        if (! $event) {
            return $this->fail(ApiErrorCode::EVENT_NOT_FOUND, '赛事不存在。', 404);
        }

        try {
            $result = $this->submissions->submit($event, $customer, $validator->validated());
        } catch (RegistrationException $exception) {
            return $this->fail(
                $exception->errorCode(),
                $exception->getMessage(),
                $exception->httpStatus(),
            );
        }

        return $this->ok(
            $this->registrationPayload($result['registration'], $result['attempt']),
            $result['replayed'] ? '已返回原报名结果。' : '报名已提交。',
            ['replayed' => (bool) $result['replayed']],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(
        \Webkul\Shop\Models\EventRegistration $registration,
        \Webkul\Shop\Models\EventRegistrationAttempt $attempt,
    ): array {
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');
        $iso = static fn (mixed $value): ?string => $value instanceof Carbon
            ? $value->copy()->timezone($timezone)->toIso8601String()
            : null;

        return [
            'public_no'   => (string) $registration->public_no,
            'event_id'    => (int) $registration->event_id,
            'status'      => (string) $attempt->status,
            'attempt_no'  => (int) $attempt->attempt_no,
            'attempt_id'  => (int) $attempt->getKey(),
            'form_version'=> (int) $attempt->form_version,
            'privacy_version' => (string) $attempt->privacy_version,
            'submitted_at'=> $iso($attempt->submitted_at),
            'decided_at'  => $iso($attempt->decided_at),
            'canceled_at' => $iso($attempt->canceled_at),
            'decision_reason_code' => $attempt->decision_reason_code,
            'can_reapply' => in_array((string) $attempt->status, [
                \Webkul\Shop\Models\EventRegistrationAttempt::STATUS_CANCELED,
                \Webkul\Shop\Models\EventRegistrationAttempt::STATUS_REJECTED,
            ], true),
        ];
    }

    /**
     * @param  array<int, \Webkul\Shop\Models\EcosystemMediaRef>  $refs
     * @return array<string, mixed>
     */
    private function toDto(
        Event $event,
        EventRevision $revision,
        array $refs,
        ?int $customerId = null,
        bool $favorited = false,
        array $related = [],
    ): array {
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
            $this->registration->forEvent($event, $revision, $customerId),
            $bodyHtml,
            $media,
            $favorited,
            $related,
        ))->toArray();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function indexRules(): array
    {
        $keywordMax = max(1, (int) config('ecosystem_g4.search.keyword_max_length', 50));
        $maxPage = max(1, (int) config('ecosystem_g4.search.max_page', 200));

        return [
            'mode'               => ['sometimes', 'string', Rule::in(['page', 'cursor'])],
            'page'               => ['sometimes', 'integer', 'min:1', 'max:'.$maxPage],
            'cursor'             => ['sometimes', 'string', 'max:512'],
            'per_page'           => ['sometimes', 'integer', 'min:1', 'max:'.PublicEventQuery::MAX_PER_PAGE],
            'q'                  => ['sometimes', 'string', 'max:'.$keywordMax],
            'kind'               => ['sometimes', 'string', Rule::in((array) config('ecosystem_publishing.event_kinds', []))],
            'participation_mode' => ['sometimes', 'string', Rule::in(PublicEventQuery::PARTICIPATION_MODES)],
            'city'               => ['sometimes', 'string', 'max:100'],
            'hosting_status'     => ['sometimes', 'string', Rule::in((array) config('ecosystem_publishing.event_hosting_statuses', []))],
            'registration_state' => ['sometimes', 'string', Rule::in(PublicEventQuery::REGISTRATION_STATES)],
            'starts_from'        => ['sometimes', 'date'],
            'starts_to'          => ['sometimes', 'date'],
        ];
    }

    /**
     * 搜索能力关闭时，带搜索参数必须明确拒绝；静默忽略会让调用方以为筛选生效。
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertSearchEnabled(array $validated): ?JsonResponse
    {
        if (! isset($validated['q']) && ! isset($validated['registration_state'])) {
            return null;
        }

        if ((bool) config('ecosystem_g4.search.enabled', false)) {
            return null;
        }

        return $this->reject(
            ApiErrorCode::VALIDATION_ERROR,
            '搜索与报名状态筛选暂未启用。',
            ['errors' => ['search' => ['该能力尚未开放。']], 'reason' => 'search_disabled'],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function filters(array $validated): array
    {
        unset($validated['mode'], $validated['page'], $validated['cursor'], $validated['per_page']);

        return array_filter(
            $validated,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * 筛选条件原样回显，供客户端重建可回放请求。
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function echoFilters(array $filters): array
    {
        return $filters;
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
