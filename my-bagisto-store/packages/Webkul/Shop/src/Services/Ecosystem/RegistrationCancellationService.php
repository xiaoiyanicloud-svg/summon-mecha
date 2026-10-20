<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventCheckinToken;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationRequest;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G2-07 · 本人取消 / 运营纠正取消。
 *
 * 只有当前有效轮次可取消；已签到不允许本人自助取消。
 * 已确认/已签到的席位只释放一次；重复取消重放返回原轮次当前状态。
 * 取消/重报会吊销该轮次的全部入场凭证，旧 token 不得作用于新轮次。
 */
class RegistrationCancellationService
{
    public function __construct(
        private readonly RegistrationPolicy $policy,
        private readonly RegistrationIdempotency $idempotency,
        private readonly RegistrationStaffScope $staff,
        private readonly OutboxWriter $outbox,
        private readonly OperationLogger $logger,
        private readonly CompetitionEntryService $competitionEntries,
    ) {}

    /**
     * @param  array<string, mixed>  $input  request_key / expected_attempt_id / reason
     */
    public function cancelByCustomer(int $registrationId, int $customerId, array $input): array
    {
        $requestKey = (string) ($input['request_key'] ?? '');
        $payloadHash = $this->idempotency->payloadHash([
            'action'          => 'cancel',
            'registration_id' => $registrationId,
            'customer_id'     => $customerId,
            'reason'          => trim((string) ($input['reason'] ?? '')),
            'expected_attempt_id' => isset($input['expected_attempt_id']) ? (int) $input['expected_attempt_id'] : null,
        ]);

        return DB::transaction(function () use ($registrationId, $customerId, $input, $requestKey, $payloadHash): array {
            $begin = $this->idempotency->begin(
                'customer',
                $customerId,
                'cancel',
                $requestKey,
                $payloadHash,
                'registration',
                $registrationId,
            );

            if ($begin['replayed']) {
                /** @var EventRegistrationRequest $request */
                $request = $begin['request'];

                return [
                    'attempt'  => $this->attemptForReplay($request),
                    'replayed' => true,
                ];
            }

            /** @var EventRegistrationRequest $request */
            $request = $begin['request'];

            [$event, $scope, $registration, $attempt] = $this->lockAggregateByRegistration($registrationId);

            if ((int) $registration->customer_id !== $customerId
                || (int) $attempt->owner_customer_id !== $customerId) {
                throw RegistrationException::forbidden('只能取消本人的报名。');
            }

            $expectedAttemptId = isset($input['expected_attempt_id']) && $input['expected_attempt_id'] !== null
                ? (int) $input['expected_attempt_id']
                : null;

            if ($expectedAttemptId !== null && $expectedAttemptId !== (int) $attempt->getKey()) {
                throw RegistrationException::transition('报名轮次已变化，请刷新后重试。', 'attempt_changed');
            }

            if ((string) $attempt->status === EventRegistrationAttempt::STATUS_CANCELED) {
                throw RegistrationException::transition('报名已取消，无需重复操作。', 'already_canceled');
            }

            if ((string) $attempt->status === EventRegistrationAttempt::STATUS_CHECKED_IN) {
                throw RegistrationException::transition('已签到，不能自助取消；请联系活动运营。', 'checked_in');
            }

            if (! $attempt->isActive()) {
                throw RegistrationException::transition('当前轮次不可取消。', 'not_active');
            }

            if ((string) $scope->cancel_policy === 'never') {
                throw RegistrationException::forbidden('该活动不允许自助取消。');
            }

            if ((string) $scope->cancel_policy === 'before_close'
                && $scope->registration_closes_at !== null
                && now('UTC')->greaterThan($scope->registration_closes_at)) {
                throw RegistrationException::closed('报名已截止，不能自助取消；请联系活动运营。');
            }

            $this->cancelLocked(
                $event,
                $scope,
                $registration,
                $attempt,
                'customer',
                $customerId,
                'customer',
                trim((string) ($input['reason'] ?? '')) ?: null,
            );

            $this->idempotency->complete(
                $request,
                'attempt',
                (int) $attempt->getKey(),
                (string) $registration->public_no,
            );

            return [
                'attempt'  => $attempt->refresh(),
                'replayed' => false,
            ];
        });
    }

    public function cancelByAdmin(
        int $attemptId,
        Admin $admin,
        string $reasonCode,
        ?string $note = null,
        ?int $expectedRowVersion = null,
    ): EventRegistrationAttempt {
        return DB::transaction(function () use ($attemptId, $admin, $reasonCode, $note, $expectedRowVersion): EventRegistrationAttempt {
            $probe = EventRegistrationAttempt::query()->find($attemptId);

            if ($probe === null) {
                throw RegistrationException::notFound('报名轮次不存在。');
            }

            [$event, $scope, $registration, $attempt] = $this->lockAggregateByRegistration((int) $probe->registration_id, $attemptId);

            $this->staff->assertCapability((int) $event->getKey(), 'cancel', $admin);

            if ($expectedRowVersion !== null && (int) $attempt->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            if ((string) $attempt->status === EventRegistrationAttempt::STATUS_CANCELED) {
                throw RegistrationException::transition('报名已取消。', 'already_canceled');
            }

            $reasonCode = trim($reasonCode);

            if ($reasonCode === '' || mb_strlen($reasonCode) > 50) {
                throw RegistrationException::invalid('取消原因码必填且不超过 50 字符。');
            }

            $this->cancelLocked(
                $event,
                $scope,
                $registration,
                $attempt,
                'admin',
                (int) $admin->getKey(),
                $reasonCode,
                $note,
            );

            return $attempt->refresh();
        });
    }

    private function cancelLocked(
        Event $event,
        EventRegistrationScope $scope,
        EventRegistration $registration,
        EventRegistrationAttempt $attempt,
        string $actorType,
        int $actorId,
        string $reasonCode,
        ?string $note,
    ): void {
        if ($attempt->occupiesSeat()) {
            $next = (int) $scope->confirmed_count - 1;

            // 只释放一次；出现负数说明计数已经漂移，拒绝继续并留给对账处理。
            if ($next < 0) {
                throw RegistrationException::transition('确认席位计数异常，请先对账。', 'count_underflow');
            }

            $scope->confirmed_count = $next;
            $scope->row_version = (int) $scope->row_version + 1;
            $scope->save();
        }

        $wasCheckedIn = (string) $attempt->status === EventRegistrationAttempt::STATUS_CHECKED_IN;

        $attempt->status = EventRegistrationAttempt::STATUS_CANCELED;
        $attempt->canceled_at = now('UTC');
        $attempt->cancel_reason_code = $reasonCode;
        $attempt->canceled_by = $actorId > 0 ? $actorId : null;
        $attempt->row_version = (int) $attempt->row_version + 1;
        $attempt->save();

        // G3-04：取消队伍报名时，参赛条目转为 withdrawn；旧快照保留供审计。
        $this->competitionEntries->syncForAttempt($attempt, \Webkul\Shop\Models\CompetitionEntry::STATUS_WITHDRAWN);

        // 旧轮次凭证立即失效；签到事实保留在 checked_in_at，供审计核对。
        EventCheckinToken::query()
            ->where('attempt_id', $attempt->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now('UTC'), 'updated_at' => now()]);

        $this->outbox->write(
            'registration',
            (int) $registration->getKey(),
            'registration.canceled',
            [
                'event_id'            => (int) $event->getKey(),
                'registration_id'     => (int) $registration->getKey(),
                'attempt_id'          => (int) $attempt->getKey(),
                'status'              => EventRegistrationAttempt::STATUS_CANCELED,
                'reason'              => $reasonCode,
                'was_checked_in'      => $wasCheckedIn,
                'attempt_row_version' => (int) $attempt->row_version,
            ],
            sprintf('registration:%d:registration.canceled', (int) $attempt->getKey()),
        );

        $this->logger->logOrFail(
            $actorType,
            $actorId > 0 ? $actorId : null,
            'registration.canceled',
            'event_registration_attempt',
            (int) $attempt->getKey(),
            [
                'event_id'        => (int) $event->getKey(),
                'registration_id' => (int) $registration->getKey(),
                'scope_id'        => (int) $scope->getKey(),
                'reason'          => $reasonCode,
                'note'            => $note,
            ],
        );
    }

    /**
     * @return array{0: Event, 1: EventRegistrationScope, 2: EventRegistration, 3: EventRegistrationAttempt}
     */
    private function lockAggregateByRegistration(int $registrationId, ?int $attemptId = null): array
    {
        $registrationProbe = EventRegistration::query()->find($registrationId);

        if ($registrationProbe === null) {
            throw RegistrationException::notFound('报名记录不存在。');
        }

        $event = Event::query()->whereKey($registrationProbe->event_id)->lockForUpdate()->first();

        if ($event === null) {
            throw RegistrationException::notFound('活动不存在。');
        }

        $scope = $registrationProbe->scope_id !== null
            ? EventRegistrationScope::query()->whereKey($registrationProbe->scope_id)->lockForUpdate()->first()
            : null;

        if ($scope === null) {
            throw RegistrationException::notFound('报名范围不存在。');
        }

        $registration = EventRegistration::query()->whereKey($registrationId)->lockForUpdate()->first();

        if ($registration === null) {
            throw RegistrationException::notFound('报名记录不存在。');
        }

        $targetAttemptId = $attemptId ?? ($registration->current_attempt_id !== null ? (int) $registration->current_attempt_id : null);

        if ($targetAttemptId === null) {
            throw RegistrationException::notFound('报名轮次不存在。');
        }

        $attempt = EventRegistrationAttempt::query()->whereKey($targetAttemptId)->lockForUpdate()->first();

        if ($attempt === null) {
            throw RegistrationException::notFound('报名轮次不存在。');
        }

        if ((int) $attempt->registration_id !== (int) $registration->getKey()
            || (int) $attempt->scope_id !== (int) $scope->getKey()
            || (int) $attempt->event_id !== (int) $event->getKey()
            || (int) $registration->scope_id !== (int) $scope->getKey()
            || (int) $scope->event_id !== (int) $event->getKey()) {
            throw RegistrationException::notFound('报名归属不一致。');
        }

        return [$event, $scope, $registration, $attempt];
    }

    private function attemptForReplay(EventRegistrationRequest $request): EventRegistrationAttempt
    {
        $attempt = EventRegistrationAttempt::query()->find((int) $request->result_id);

        if ($attempt === null) {
            throw RegistrationException::notFound('原报名轮次不存在。');
        }

        return $attempt;
    }
}
