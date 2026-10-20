<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventChange;
use Webkul\Shop\Models\EventChangeAcknowledgment;
use Webkul\Shop\Models\EventCheckinToken;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G2-08 · 活动变更（取消 / 改期）与报名人确认。
 *
 * 边界：
 *   · 活动取消/改期的写能力受 `ecosystem_registration.lifecycle.enabled` 与
 *     B-06 政策签署约束；默认关闭。关闭时运营只能用暂缓/撤稿停止展示。
 *   · 改期本身仍由 G1 发布流程完成；本服务记录“已发布版本带来的变更”，
 *     不复制第二条改时间写路径。
 *   · 需要确认的变更必须显式配置截止时间、拒绝/逾期后果；缺政策拒绝发布。
 *   · 未响应不是同意；到期由扫描按公布政策处理。
 */
class RegistrationChangeService
{
    public function __construct(
        private readonly RegistrationStaffScope $staff,
        private readonly OutboxWriter $outbox,
        private readonly OperationLogger $logger,
    ) {}

    public function lifecycleWritesEnabled(): bool
    {
        return (bool) config('ecosystem_registration.lifecycle.enabled', false);
    }

    /**
     * 取消活动：立即停止报名/签到，取消活动内有效轮次并释放席位。
     */
    public function cancelEvent(Event $event, Admin $admin, string $summary, string $reasonCode = 'event_canceled'): EventChange
    {
        if (! $this->lifecycleWritesEnabled()) {
            throw new RegistrationException('REGISTRATION_VALIDATION_ERROR', 422, '活动取消政策未签署，写能力保持关闭。', 'lifecycle_disabled');
        }

        return DB::transaction(function () use ($event, $admin, $summary, $reasonCode): EventChange {
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $this->staff->assertCapability((int) $lockedEvent->getKey(), 'settings', $admin);

            $existing = EventChange::query()
                ->where('event_id', $lockedEvent->getKey())
                ->where('type', EventChange::TYPE_CANCEL)
                ->where('status', 'published')
                ->orderByDesc('change_version')
                ->first();

            if ((string) $lockedEvent->hosting_status === 'canceled' && $existing !== null) {
                return $existing;
            }

            $previousHostingStatus = (string) $lockedEvent->hosting_status;

            $lockedEvent->hosting_status = 'canceled';
            $lockedEvent->row_version = (int) $lockedEvent->row_version + 1;
            $lockedEvent->save();

            $scope = EventRegistrationScope::query()
                ->where('event_id', $lockedEvent->getKey())
                ->where('scope_key', 'default')
                ->lockForUpdate()
                ->first();

            if ($scope !== null) {
                $scope->status = 'closed';
                $scope->row_version = (int) $scope->row_version + 1;
                $scope->save();
            }

            $change = $this->createChange(
                $lockedEvent,
                EventChange::TYPE_CANCEL,
                $summary,
                [
                    'previous_hosting_status' => $previousHostingStatus,
                    'canceled_at'             => now('UTC')->toIso8601String(),
                    'scope_closed'            => $scope !== null,
                ],
                false,
                null,
                false,
                'none',
                $admin,
            );

            $releasedSeats = 0;
            $affected = 0;

            // 分块处理，事务内持有活动/scope 锁；入口已经因 hosting_status=canceled
            // 立即拒绝新报名/签到，通知异步不阻塞停写。
            EventRegistrationAttempt::query()
                ->where('event_id', $lockedEvent->getKey())
                ->whereIn('status', EventRegistrationAttempt::ACTIVE_STATUSES)
                ->orderBy('id')
                ->chunkById(500, function ($attempts) use ($lockedEvent, $scope, $admin, $reasonCode, &$releasedSeats, &$affected): void {
                    foreach ($attempts as $probe) {
                        $attempt = EventRegistrationAttempt::query()
                            ->whereKey($probe->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($attempt === null || ! $attempt->isActive()) {
                            continue;
                        }

                        if ($attempt->occupiesSeat()) {
                            $releasedSeats++;
                        }

                        $attempt->status = EventRegistrationAttempt::STATUS_CANCELED;
                        $attempt->canceled_at = now('UTC');
                        $attempt->cancel_reason_code = $reasonCode;
                        $attempt->canceled_by = (int) $admin->getKey();
                        $attempt->row_version = (int) $attempt->row_version + 1;
                        $attempt->save();

                        EventCheckinToken::query()
                            ->where('attempt_id', $attempt->getKey())
                            ->whereNull('revoked_at')
                            ->update(['revoked_at' => now('UTC'), 'updated_at' => now()]);

                        $this->outbox->write(
                            'registration',
                            (int) $attempt->registration_id,
                            'registration.event_canceled',
                            [
                                'event_id'            => (int) $lockedEvent->getKey(),
                                'registration_id'     => (int) $attempt->registration_id,
                                'attempt_id'          => (int) $attempt->getKey(),
                                'status'              => EventRegistrationAttempt::STATUS_CANCELED,
                                'reason'              => $reasonCode,
                                'attempt_row_version' => (int) $attempt->row_version,
                            ],
                            sprintf('registration:%d:registration.event_canceled', (int) $attempt->getKey()),
                        );

                        $affected++;
                    }
                });

            if ($scope !== null && $releasedSeats > 0) {
                $scope->confirmed_count = max(0, (int) $scope->confirmed_count - $releasedSeats);
                $scope->row_version = (int) $scope->row_version + 1;
                $scope->save();
            }

            $details = (array) $change->details;
            $details['affected_attempts'] = $affected;
            $details['released_seats'] = $releasedSeats;
            $change->details = $details;
            $change->save();

            $this->logger->logOrFail(
                'admin',
                (int) $admin->getKey(),
                'registration.event.canceled',
                'event',
                (int) $lockedEvent->getKey(),
                ['change_id' => (int) $change->getKey(), 'affected' => $affected, 'released_seats' => $releasedSeats],
            );

            return $change->refresh();
        });
    }

    /**
     * 记录一次已发布版本带来的改期。改时间本身必须已由 G1 发布流程完成。
     *
     * @param  array<string, mixed>  $details
     */
    public function recordReschedule(
        Event $event,
        Admin $admin,
        string $summary,
        array $details,
        bool $requiresAck,
        ?Carbon $ackDeadline,
        bool $declineReleasesSeat,
        string $noResponseAction,
    ): EventChange {
        if (! $this->lifecycleWritesEnabled()) {
            throw new RegistrationException('REGISTRATION_VALIDATION_ERROR', 422, '活动改期政策未签署，写能力保持关闭。', 'lifecycle_disabled');
        }

        if (! in_array($noResponseAction, ['none', 'release'], true)) {
            throw RegistrationException::invalid('未响应政策必须是 none 或 release。');
        }

        return DB::transaction(function () use (
            $event,
            $admin,
            $summary,
            $details,
            $requiresAck,
            $ackDeadline,
            $declineReleasesSeat,
            $noResponseAction,
        ): EventChange {
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $this->staff->assertCapability((int) $lockedEvent->getKey(), 'settings', $admin);

            if ((string) $lockedEvent->publish_status !== 'published' || $lockedEvent->published_revision_id === null) {
                throw RegistrationException::invalid('活动没有已发布版本，不能记录改期。');
            }

            $scope = EventRegistrationScope::query()
                ->where('event_id', $lockedEvent->getKey())
                ->where('scope_key', 'default')
                ->lockForUpdate()
                ->first();

            if ($requiresAck) {
                if (! $scope instanceof EventRegistrationScope || ! $scope->change_ack_required) {
                    throw RegistrationException::invalid('活动未配置“变更需确认”，不能发布需确认的改期。');
                }

                if ($ackDeadline === null) {
                    throw RegistrationException::invalid('需确认的改期必须配置确认截止时间。');
                }

                if ($ackDeadline->lessThanOrEqualTo(now('UTC'))) {
                    throw RegistrationException::invalid('确认截止时间必须在未来。');
                }
            }

            $change = $this->createChange(
                $lockedEvent,
                EventChange::TYPE_RESCHEDULE,
                $summary,
                $details,
                $requiresAck,
                $ackDeadline,
                $declineReleasesSeat,
                $noResponseAction,
                $admin,
            );

            if ($requiresAck) {
                EventRegistrationAttempt::query()
                    ->where('event_id', $lockedEvent->getKey())
                    ->whereIn('status', EventRegistrationAttempt::ACTIVE_STATUSES)
                    ->orderBy('id')
                    ->chunkById(500, function ($attempts) use ($lockedEvent, $change): void {
                        foreach ($attempts as $attempt) {
                            $this->outbox->write(
                                'registration',
                                (int) $attempt->registration_id,
                                'registration.change_requested',
                                [
                                    'event_id'        => (int) $lockedEvent->getKey(),
                                    'registration_id' => (int) $attempt->registration_id,
                                    'attempt_id'      => (int) $attempt->getKey(),
                                    'change_id'       => (int) $change->getKey(),
                                    'ack_deadline'    => $change->ack_deadline?->toIso8601String(),
                                ],
                                sprintf('registration:%d:change:%d:requested', (int) $attempt->getKey(), (int) $change->getKey()),
                            );
                        }
                    });
            }

            $this->logger->logOrFail(
                'admin',
                (int) $admin->getKey(),
                'registration.change.published',
                'event_change',
                (int) $change->getKey(),
                ['event_id' => (int) $lockedEvent->getKey(), 'type' => EventChange::TYPE_RESCHEDULE, 'requires_ack' => $requiresAck],
            );

            return $change->refresh();
        });
    }

    /**
     * 本人确认/拒绝变更。拒绝是否释放席位由变更政策决定。
     */
    public function acknowledge(
        int $registrationId,
        int $customerId,
        int $changeId,
        string $response,
        ?int $expectedAttemptId = null,
    ): EventChangeAcknowledgment {
        if (! in_array($response, [
            EventChangeAcknowledgment::RESPONSE_ACCEPTED,
            EventChangeAcknowledgment::RESPONSE_DECLINED,
        ], true)) {
            throw RegistrationException::invalid('确认结果必须是 accepted 或 declined。');
        }

        return DB::transaction(function () use ($registrationId, $customerId, $changeId, $response, $expectedAttemptId): EventChangeAcknowledgment {
            $registration = EventRegistration::query()->whereKey($registrationId)->lockForUpdate()->first();

            if ($registration === null || (int) $registration->customer_id !== $customerId) {
                throw RegistrationException::notFound('报名不存在。');
            }

            $event = Event::query()->whereKey($registration->event_id)->lockForUpdate()->firstOrFail();

            $scope = EventRegistrationScope::query()->whereKey($registration->scope_id)->lockForUpdate()->first();

            if ($scope === null) {
                throw RegistrationException::notFound('报名范围不存在。');
            }

            $attempt = $registration->current_attempt_id !== null
                ? EventRegistrationAttempt::query()
                    ->whereKey($registration->current_attempt_id)
                    ->where('registration_id', $registration->getKey())
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($attempt === null || ! $attempt->isActive()) {
                throw RegistrationException::transition('当前轮次不可确认变更。', 'not_active');
            }

            if ($expectedAttemptId !== null && (int) $attempt->getKey() !== $expectedAttemptId) {
                throw RegistrationException::transition('报名轮次已变化，请刷新后重试。', 'attempt_changed');
            }

            $change = EventChange::query()
                ->whereKey($changeId)
                ->where('event_id', $event->getKey())
                ->where('status', 'published')
                ->lockForUpdate()
                ->first();

            if ($change === null || ! $change->requires_ack) {
                throw RegistrationException::notFound('变更不存在或不需要确认。');
            }

            if ($change->ack_deadline !== null && $change->ack_deadline->lessThan(now('UTC'))) {
                throw new RegistrationException('CHANGE_ACK_CLOSED', 422, '变更确认已截止。', 'ack_closed');
            }

            $ack = EventChangeAcknowledgment::query()
                ->where('change_id', $change->getKey())
                ->where('attempt_id', $attempt->getKey())
                ->lockForUpdate()
                ->first();

            if ($ack !== null) {
                if ((string) $ack->response === $response) {
                    return $ack;
                }

                throw RegistrationException::transition('该变更已确认过，不能改判。', 'already_acknowledged');
            }

            $ack = EventChangeAcknowledgment::query()->create([
                'change_id'       => (int) $change->getKey(),
                'registration_id' => (int) $registration->getKey(),
                'attempt_id'      => (int) $attempt->getKey(),
                'response'        => $response,
                'responded_at'    => now('UTC'),
                'note'            => null,
            ]);

            if ($response === EventChangeAcknowledgment::RESPONSE_DECLINED
                && $change->decline_releases_seat
                && $attempt->occupiesSeat()) {
                $this->releaseAndCancel(
                    $event,
                    $scope,
                    $registration,
                    $attempt,
                    (int) $customerId,
                    'change_declined',
                );
            }

            $this->outbox->write(
                'registration',
                (int) $registration->getKey(),
                'registration.change_acknowledged',
                [
                    'event_id'        => (int) $event->getKey(),
                    'registration_id' => (int) $registration->getKey(),
                    'attempt_id'      => (int) $attempt->getKey(),
                    'change_id'       => (int) $change->getKey(),
                    'response'        => $response,
                ],
                sprintf('registration:%d:change:%d:%s', (int) $attempt->getKey(), (int) $change->getKey(), $response),
            );

            $this->logger->logOrFail(
                'customer',
                $customerId,
                'registration.change.acknowledged',
                'event_change',
                (int) $change->getKey(),
                ['attempt_id' => (int) $attempt->getKey(), 'response' => $response],
            );

            return $ack->refresh();
        });
    }

    /**
     * 到期未响应扫描：写入 no_response，并按政策决定是否释放席位。
     *
     * @return int 处理的变更-轮次数量
     */
    public function expireAcks(int $limit = 200): int
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        $limit = max(1, min(1000, $limit));

        $changes = EventChange::query()
            ->where('status', 'published')
            ->where('requires_ack', true)
            ->whereNotNull('ack_deadline')
            ->where('ack_deadline', '<', $now)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($changes as $change) {
            // 用 chunkById 而不是 offset chunk：处理过程中轮次状态会变化，
            // offset 分页会因结果集收缩而漏行（T53）。
            EventRegistrationAttempt::query()
                ->where('event_id', $change->event_id)
                ->whereIn('status', EventRegistrationAttempt::ACTIVE_STATUSES)
                ->chunkById(500, function ($attempts) use ($change, &$processed): void {
                    foreach ($attempts as $attempt) {
                        try {
                            DB::transaction(function () use ($change, $attempt, &$processed): void {
                                $exists = EventChangeAcknowledgment::query()
                                    ->where('change_id', $change->getKey())
                                    ->where('attempt_id', $attempt->getKey())
                                    ->exists();

                                if ($exists) {
                                    return;
                                }

                                $registration = EventRegistration::query()->whereKey($attempt->registration_id)->lockForUpdate()->firstOrFail();
                                $event = Event::query()->whereKey($attempt->event_id)->lockForUpdate()->firstOrFail();
                                $scope = EventRegistrationScope::query()->whereKey($attempt->scope_id)->lockForUpdate()->firstOrFail();
                                $lockedAttempt = EventRegistrationAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();

                                if (! $lockedAttempt->isActive()) {
                                    return;
                                }

                                EventChangeAcknowledgment::query()->create([
                                    'change_id'       => (int) $change->getKey(),
                                    'registration_id' => (int) $registration->getKey(),
                                    'attempt_id'      => (int) $lockedAttempt->getKey(),
                                    'response'        => EventChangeAcknowledgment::RESPONSE_NO_RESPONSE,
                                    'responded_at'    => now('UTC'),
                                    'note'            => 'ack_deadline_passed',
                                ]);

                                if ((string) $change->no_response_action === 'release' && $lockedAttempt->occupiesSeat()) {
                                    $this->releaseAndCancel(
                                        $event,
                                        $scope,
                                        $registration,
                                        $lockedAttempt,
                                        0,
                                        'change_no_response',
                                    );
                                }

                                $this->outbox->write(
                                    'registration',
                                    (int) $registration->getKey(),
                                    'registration.change_expired',
                                    [
                                        'event_id'        => (int) $event->getKey(),
                                        'registration_id' => (int) $registration->getKey(),
                                        'attempt_id'      => (int) $lockedAttempt->getKey(),
                                        'change_id'       => (int) $change->getKey(),
                                    ],
                                    sprintf('registration:%d:change:%d:expired', (int) $lockedAttempt->getKey(), (int) $change->getKey()),
                                );

                                $processed++;
                            });
                        } catch (RegistrationException) {
                            // 状态竞争：下一轮扫描重新判断。
                        }
                    }
                });
        }

        return $processed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingForRegistration(EventRegistration $registration): array
    {
        if ($registration->current_attempt_id === null) {
            return [];
        }

        $attempt = EventRegistrationAttempt::query()->find($registration->current_attempt_id);

        if ($attempt === null || ! $attempt->isActive()) {
            return [];
        }

        $changes = EventChange::query()
            ->where('event_id', $registration->event_id)
            ->where('status', 'published')
            ->where('requires_ack', true)
            ->where(function ($query): void {
                $query->whereNull('ack_deadline')->orWhere('ack_deadline', '>=', now('UTC'));
            })
            ->whereDoesntHave('acknowledgments', function ($query) use ($attempt): void {
                $query->where('attempt_id', $attempt->getKey());
            })
            ->orderBy('id')
            ->get();

        return $changes->map(fn (EventChange $change): array => [
            'id'           => (int) $change->getKey(),
            'type'         => (string) $change->type,
            'summary'      => (string) $change->summary,
            'details'      => $change->details,
            'ack_deadline' => $change->ack_deadline?->toIso8601String(),
            'decline_releases_seat' => (bool) $change->decline_releases_seat,
        ])->all();
    }

    private function createChange(
        Event $event,
        string $type,
        string $summary,
        array $details,
        bool $requiresAck,
        ?Carbon $ackDeadline,
        bool $declineReleasesSeat,
        string $noResponseAction,
        Admin $admin,
    ): EventChange {
        $nextVersion = (int) EventChange::query()
            ->where('event_id', $event->getKey())
            ->max('change_version') + 1;

        return EventChange::query()->create([
            'event_id'              => (int) $event->getKey(),
            'change_version'        => $nextVersion,
            'type'                  => $type,
            'summary'               => $summary,
            'details'               => $details,
            'requires_ack'          => $requiresAck,
            'ack_deadline'          => $ackDeadline,
            'decline_releases_seat' => $declineReleasesSeat,
            'no_response_action'    => $noResponseAction,
            'status'                => 'published',
            'created_by'            => (int) $admin->getKey(),
            'published_at'          => now('UTC'),
        ]);
    }

    private function releaseAndCancel(
        Event $event,
        EventRegistrationScope $scope,
        EventRegistration $registration,
        EventRegistrationAttempt $attempt,
        int $actorId,
        string $reasonCode,
    ): void {
        if ($attempt->occupiesSeat()) {
            $scope->confirmed_count = max(0, (int) $scope->confirmed_count - 1);
            $scope->row_version = (int) $scope->row_version + 1;
            $scope->save();
        }

        $attempt->status = EventRegistrationAttempt::STATUS_CANCELED;
        $attempt->canceled_at = now('UTC');
        $attempt->cancel_reason_code = $reasonCode;
        $attempt->canceled_by = $actorId > 0 ? $actorId : null;
        $attempt->row_version = (int) $attempt->row_version + 1;
        $attempt->save();

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
                'attempt_row_version' => (int) $attempt->row_version,
            ],
            sprintf('registration:%d:registration.canceled', (int) $attempt->getKey()),
        );
    }
}
