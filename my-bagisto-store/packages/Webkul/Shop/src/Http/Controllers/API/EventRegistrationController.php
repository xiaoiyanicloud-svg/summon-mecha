<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Resources\Ecosystem\RegistrationResource;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Services\Ecosystem\RegistrationCancellationService;
use Webkul\Shop\Services\Ecosystem\RegistrationChangeService;
use Webkul\Shop\Services\Ecosystem\RegistrationCredentialService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationIdempotency;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;

/**
 * 本人报名 API（G2-05 / G2-07 / G2-10）。
 *
 * 身份只认 Sanctum 登录客户；public_no 不是授权凭证 —— 每个读写都重新核验
 * `customer_id` 归属。查不到与不属于本人统一 404，避免枚举。
 */
class EventRegistrationController extends APIController
{
    public function __construct(
        private readonly RegistrationResource $resource,
        private readonly RegistrationCancellationService $cancellations,
        private readonly RegistrationSubmissionService $submissions,
        private readonly RegistrationIdempotency $idempotency,
        private readonly RegistrationCredentialService $credentials,
        private readonly RegistrationChangeService $changes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->query(), [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '分页参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $paginator = EventRegistration::query()
            ->with(['event', 'currentAttempt'])
            ->where('customer_id', (int) $customer->getKey())
            ->where('identity_kind', EventRegistration::IDENTITY_CUSTOMER)
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        $data = [];

        foreach ($paginator->items() as $registration) {
            $attempt = $registration->currentAttempt;

            if (! $attempt instanceof EventRegistrationAttempt) {
                continue;
            }

            $data[] = $this->resource->own($registration, $attempt);
        }

        return $this->ok($data, '', [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(string $publicNo): JsonResponse
    {
        $owned = $this->owned($publicNo);

        if ($owned === null) {
            return $this->notFound();
        }

        [$registration, $attempt] = $owned;

        return $this->ok($this->resource->own($registration, $attempt));
    }

    public function cancel(Request $request, string $publicNo): JsonResponse
    {
        $owned = $this->owned($publicNo);

        if ($owned === null) {
            return $this->notFound();
        }

        [$registration] = $owned;

        $validator = Validator::make($request->all(), [
            'request_key'         => ['required', 'string', 'max:100'],
            // 取消必须钉住当前轮次：旧页面/旧指令不能误伤重报后的新轮次。
            'expected_attempt_id' => ['required', 'integer', 'min:1'],
            'reason'              => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '取消参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $result = $this->cancellations->cancelByCustomer(
                (int) $registration->getKey(),
                (int) $this->customer()->getKey(),
                $validator->validated(),
            );
        } catch (RegistrationException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return $this->ok($this->resource->submitted($registration, $result['attempt']), '报名已取消。', [
            'replayed' => (bool) $result['replayed'],
        ]);
    }

    public function reapply(Request $request, string $publicNo): JsonResponse
    {
        $owned = $this->owned($publicNo);

        if ($owned === null) {
            return $this->notFound();
        }

        [$registration] = $owned;

        $validator = Validator::make($request->all(), [
            'request_key'         => ['required', 'string', 'max:100'],
            'expected_attempt_id' => ['required', 'integer', 'min:1'],
            'form_version'        => ['required', 'integer', 'min:1'],
            'privacy_version'     => ['required', 'string', 'max:50'],
            'rules_version_id'    => ['nullable', 'integer', 'min:1'],
            'consent'             => ['required', 'accepted'],
            'name'                => ['required', 'string', 'max:50'],
            'phone'               => ['required', 'string', 'max:20'],
            'city'                => ['nullable', 'string', 'max:100'],
            'note'                => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '重报参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $event = Event::query()->find($registration->event_id);

        if ($event === null) {
            return $this->fail(ApiErrorCode::EVENT_NOT_FOUND, '赛事不存在。', 404);
        }

        try {
            $result = $this->submissions->submit(
                $event,
                $this->customer(),
                $validator->validated(),
                'reapply',
            );
        } catch (RegistrationException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return $this->ok(
            $this->resource->submitted($result['registration'], $result['attempt']),
            $result['replayed'] ? '已返回原报名结果。' : '重报已提交。',
            ['replayed' => (bool) $result['replayed']],
        );
    }

    /**
     * 超时查回：同一 request_key 的提交结果。
     *
     * 只查当前客户自己的请求；不存在与不属于本人统一 404。
     */
    public function requestStatus(Request $request, string $requestKey): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $action = (string) $request->query('action', 'submit');

        if (! in_array($action, ['submit', 'reapply', 'cancel', 'ack_change'], true)) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, 'action 不合法。');
        }

        $record = $this->idempotency->find(
            'customer',
            (int) $customer->getKey(),
            $action,
            $requestKey,
        );

        if ($record === null) {
            return $this->fail(ApiErrorCode::REGISTRATION_NOT_FOUND, '未找到该请求。', 404);
        }

        $payload = [
            'completed'    => $record->completed_at !== null,
            'action'       => (string) $record->action,
            'public_no'    => $record->result_public_no,
            'result_type'  => $record->result_type,
        ];

        if ($record->completed_at !== null) {
            if ((string) $record->result_type === 'attempt') {
                $attempt = EventRegistrationAttempt::query()->find((int) $record->result_id);
                $registration = $attempt !== null
                    ? EventRegistration::query()->find((int) $attempt->registration_id)
                    : null;

                if ($attempt !== null && $registration !== null
                    && (int) $registration->customer_id === (int) $customer->getKey()) {
                    $payload['result'] = $this->resource->submitted($registration, $attempt);
                }
            } elseif ((string) $record->result_type === 'registration') {
                $registration = EventRegistration::query()->find((int) $record->result_id);

                if ($registration !== null
                    && (int) $registration->customer_id === (int) $customer->getKey()
                    && $registration->current_attempt_id !== null) {
                    $attempt = EventRegistrationAttempt::query()->find((int) $registration->current_attempt_id);

                    if ($attempt !== null) {
                        $payload['result'] = $this->resource->submitted($registration, $attempt);
                    }
                }
            }
        }

        return $this->ok($payload);
    }

    /**
     * 本人领取/重发入场凭证。原始 token 只返回一次；重复调用会吊销旧凭证。
     */
    public function credential(string $publicNo): JsonResponse
    {
        $owned = $this->owned($publicNo);

        if ($owned === null) {
            return $this->notFound();
        }

        [$registration] = $owned;

        try {
            $credential = $this->credentials->issueForCustomer(
                (int) $registration->getKey(),
                (int) $this->customer()->getKey(),
            );
        } catch (RegistrationException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return $this->ok($credential, '凭证已签发；旧凭证同时失效。');
    }

    /**
     * 本人待确认的活动变更。
     */
    public function changes(string $publicNo): JsonResponse
    {
        $owned = $this->owned($publicNo);

        if ($owned === null) {
            return $this->notFound();
        }

        [$registration] = $owned;

        return $this->ok($this->changes->pendingForRegistration($registration));
    }

    /**
     * 接受/拒绝活动变更。拒绝是否释放席位由已公布政策决定。
     */
    public function ackChange(Request $request, string $publicNo, int $changeId): JsonResponse
    {
        $owned = $this->owned($publicNo);

        if ($owned === null) {
            return $this->notFound();
        }

        [$registration] = $owned;

        $validator = Validator::make($request->all(), [
            'request_key'         => ['required', 'string', 'max:100'],
            'expected_attempt_id' => ['required', 'integer', 'min:1'],
            'response'            => ['required', 'in:accepted,declined'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '变更确认参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $data = $validator->validated();

        try {
            $ack = $this->changes->acknowledge(
                (int) $registration->getKey(),
                (int) $this->customer()->getKey(),
                $changeId,
                (string) $data['response'],
                (int) $data['expected_attempt_id'],
            );
        } catch (RegistrationException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return $this->ok([
            'change_id'   => (int) $ack->change_id,
            'attempt_id'  => (int) $ack->attempt_id,
            'response'    => (string) $ack->response,
            'responded_at'=> $ack->responded_at?->toIso8601String(),
        ], '变更确认已记录。');
    }

    /**
     * @return array{0: EventRegistration, 1: EventRegistrationAttempt}|null
     */
    private function owned(string $publicNo): ?array
    {
        $customer = $this->customer();

        if ($customer === null) {
            return null;
        }

        $registration = EventRegistration::query()
            ->with('event')
            ->where('public_no', $publicNo)
            ->where('customer_id', (int) $customer->getKey())
            ->where('identity_kind', EventRegistration::IDENTITY_CUSTOMER)
            ->first();

        if ($registration === null || $registration->current_attempt_id === null) {
            return null;
        }

        $attempt = EventRegistrationAttempt::query()
            ->whereKey($registration->current_attempt_id)
            ->where('registration_id', $registration->getKey())
            ->first();

        return $attempt !== null ? [$registration, $attempt] : null;
    }

    private function customer(): ?Customer
    {
        $customer = auth()->guard('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }

    private function notFound(): JsonResponse
    {
        return $this->fail(ApiErrorCode::REGISTRATION_NOT_FOUND, '报名不存在。', 404);
    }
}
