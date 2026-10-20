<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G2-06 · 人工审核。
 *
 * 批准在事务内取额：两个审核者抢最后名额时只有一个能成功。
 * 拒绝不释放不存在的席位（submitted 从未占位）。
 * 审核截止后不再批准；到期未审由扫描置 rejected（review_expired）。
 */
class RegistrationReviewService
{
    public function __construct(
        private readonly RegistrationPolicy $policy,
        private readonly RegistrationStaffScope $staff,
        private readonly OutboxWriter $outbox,
        private readonly OperationLogger $logger,
        private readonly CompetitionEntryService $competitionEntries,
    ) {}

    public function approve(int $attemptId, Admin $admin, ?int $expectedRowVersion = null): EventRegistrationAttempt
    {
        return DB::transaction(function () use ($attemptId, $admin, $expectedRowVersion): EventRegistrationAttempt {
            [$event, $scope, $registration, $attempt] = $this->lockAggregate($attemptId);

            $this->staff->assertCapability((int) $event->getKey(), 'review', $admin);

            if ($expectedRowVersion !== null && (int) $attempt->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            if ((string) $attempt->status !== EventRegistrationAttempt::STATUS_SUBMITTED) {
                throw RegistrationException::transition('只有待审核报名可以批准。', 'not_submitted');
            }

            if ((string) $scope->review_mode !== 'manual') {
                throw RegistrationException::transition('该活动不是人工审核模式。', 'not_manual');
            }

            if (! $this->policy->isReviewWindowOpen($scope)) {
                throw RegistrationException::reviewClosed();
            }

            if (! $this->policy->capacityAllowsConfirmation($scope)) {
                throw RegistrationException::full('名额已满，无法批准。');
            }

            $scope->confirmed_count = (int) $scope->confirmed_count + 1;
            $scope->row_version = (int) $scope->row_version + 1;
            $scope->save();

            $attempt->status = EventRegistrationAttempt::STATUS_CONFIRMED;
            $attempt->decided_at = now('UTC');
            $attempt->decided_by = (int) $admin->getKey();
            $attempt->decision_reason_code = 'approved';
            $attempt->row_version = (int) $attempt->row_version + 1;
            $attempt->save();

            // G3-04：队伍报名的参赛条目随审核批准转为 confirmed。
            $this->competitionEntries->syncForAttempt($attempt, \Webkul\Shop\Models\CompetitionEntry::STATUS_CONFIRMED);

            $this->writeOutbox($attempt, 'registration.confirmed');
            $this->logger->logOrFail(
                'admin',
                (int) $admin->getKey(),
                'registration.approved',
                'event_registration_attempt',
                (int) $attempt->getKey(),
                [
                    'event_id'        => (int) $event->getKey(),
                    'registration_id' => (int) $registration->getKey(),
                    'scope_id'        => (int) $scope->getKey(),
                ],
            );

            return $attempt->refresh();
        });
    }

    public function reject(int $attemptId, Admin $admin, string $reasonCode, ?string $note = null, ?int $expectedRowVersion = null): EventRegistrationAttempt
    {
        return DB::transaction(function () use ($attemptId, $admin, $reasonCode, $note, $expectedRowVersion): EventRegistrationAttempt {
            [$event, $scope, $registration, $attempt] = $this->lockAggregate($attemptId);

            $this->staff->assertCapability((int) $event->getKey(), 'review', $admin);

            if ($expectedRowVersion !== null && (int) $attempt->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            return $this->rejectLocked(
                $event,
                $scope,
                $registration,
                $attempt,
                'admin',
                (int) $admin->getKey(),
                $reasonCode,
                $note,
            );
        });
    }

    /**
     * 审核到期扫描：把超过 review_closes_at 的 submitted 置 rejected。
     *
     * 不释放不存在的席位；通知由 outbox 异步处理。
     *
     * @return int 处理条数
     */
    public function expireOverdue(int $limit = 200): int
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        $limit = max(1, min(1000, $limit));

        $ids = DB::table('event_registration_attempts as a')
            ->join('event_registration_scopes as s', 's.id', '=', 'a.scope_id')
            ->where('a.status', EventRegistrationAttempt::STATUS_SUBMITTED)
            ->where('s.review_mode', 'manual')
            ->whereNotNull('s.review_closes_at')
            ->where('s.review_closes_at', '<', $now)
            ->orderBy('a.id')
            ->limit($limit)
            ->pluck('a.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $processed = 0;

        foreach ($ids as $attemptId) {
            try {
                DB::transaction(function () use ($attemptId): void {
                    [$event, $scope, $registration, $attempt] = $this->lockAggregate($attemptId);

                    if ((string) $attempt->status !== EventRegistrationAttempt::STATUS_SUBMITTED) {
                        return;
                    }

                    $this->rejectLocked(
                        $event,
                        $scope,
                        $registration,
                        $attempt,
                        'system',
                        0,
                        'review_expired',
                        '审核截止后未处理，系统置为未通过。',
                    );
                });

                $processed++;
            } catch (RegistrationException) {
                // 单条状态竞争失败不影响整批；下一轮扫描会重新判断。
            }
        }

        return $processed;
    }

    /**
     * @return array{0: Event, 1: EventRegistrationScope, 2: EventRegistration, 3: EventRegistrationAttempt}
     */
    private function lockAggregate(int $attemptId): array
    {
        $probe = EventRegistrationAttempt::query()->find($attemptId);

        if ($probe === null) {
            throw RegistrationException::notFound('报名轮次不存在。');
        }

        $event = Event::query()->whereKey($probe->event_id)->lockForUpdate()->first();

        if ($event === null) {
            throw RegistrationException::notFound('活动不存在。');
        }

        $scope = EventRegistrationScope::query()->whereKey($probe->scope_id)->lockForUpdate()->first();

        if ($scope === null) {
            throw RegistrationException::notFound('报名范围不存在。');
        }

        $registration = EventRegistration::query()
            ->whereKey($probe->registration_id)
            ->lockForUpdate()
            ->first();

        if ($registration === null) {
            throw RegistrationException::notFound('报名记录不存在。');
        }

        $attempt = EventRegistrationAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

        if ($attempt === null) {
            throw RegistrationException::notFound('报名轮次不存在。');
        }

        // 逐层归属校验：客户端不能借其他聚合的 ID 写入。
        if ((int) $attempt->event_id !== (int) $event->getKey()
            || (int) $attempt->scope_id !== (int) $scope->getKey()
            || (int) $attempt->registration_id !== (int) $registration->getKey()
            || (int) $registration->scope_id !== (int) $scope->getKey()
            || (int) $scope->event_id !== (int) $event->getKey()) {
            throw RegistrationException::notFound('报名归属不一致。');
        }

        return [$event, $scope, $registration, $attempt];
    }

    private function rejectLocked(
        Event $event,
        EventRegistrationScope $scope,
        EventRegistration $registration,
        EventRegistrationAttempt $attempt,
        string $actorType,
        int $actorId,
        string $reasonCode,
        ?string $note,
    ): EventRegistrationAttempt {
        if ((string) $attempt->status !== EventRegistrationAttempt::STATUS_SUBMITTED) {
            throw RegistrationException::transition('只有待审核报名可以拒绝。', 'not_submitted');
        }

        $reasonCode = trim($reasonCode);

        if ($reasonCode === '' || mb_strlen($reasonCode) > 50) {
            throw RegistrationException::invalid('拒绝原因码必填且不超过 50 字符。');
        }

        $attempt->status = EventRegistrationAttempt::STATUS_REJECTED;
        $attempt->decided_at = now('UTC');
        $attempt->decided_by = $actorId > 0 ? $actorId : null;
        $attempt->decision_reason_code = $reasonCode;
        $attempt->decision_note = $note;
        $attempt->row_version = (int) $attempt->row_version + 1;
        $attempt->save();

        // G3-04：队伍报名被拒绝时，参赛条目标记为 withdrawn（历史快照保留）。
        $this->competitionEntries->syncForAttempt($attempt, \Webkul\Shop\Models\CompetitionEntry::STATUS_WITHDRAWN);

        $this->writeOutbox($attempt, 'registration.rejected');
        $this->logger->logOrFail(
            $actorType,
            $actorId > 0 ? $actorId : null,
            'registration.rejected',
            'event_registration_attempt',
            (int) $attempt->getKey(),
            [
                'event_id'        => (int) $event->getKey(),
                'registration_id' => (int) $registration->getKey(),
                'scope_id'        => (int) $scope->getKey(),
                'reason'          => $reasonCode,
            ],
        );

        return $attempt->refresh();
    }

    private function writeOutbox(EventRegistrationAttempt $attempt, string $eventType): void
    {
        $this->outbox->write(
            'registration',
            (int) $attempt->registration_id,
            $eventType,
            [
                'event_id'            => (int) $attempt->event_id,
                'registration_id'     => (int) $attempt->registration_id,
                'attempt_id'          => (int) $attempt->getKey(),
                'status'              => (string) $attempt->status,
                'attempt_row_version' => (int) $attempt->row_version,
            ],
            sprintf('registration:%d:%s', (int) $attempt->getKey(), $eventType),
        );
    }
}
