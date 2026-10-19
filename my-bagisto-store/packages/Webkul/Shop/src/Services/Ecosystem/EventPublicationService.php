<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 活动发布 / 排期 / 暂缓 / 恢复 / 撤稿（ADR-0001 §4.5 / §8.1~§8.4 / G1-08B）。
 *
 * 状态语义：
 *
 *   approved  --schedule--> scheduled --publishScheduled--> published
 *   scheduled --unschedule--> approved
 *   approved/scheduled/published --publishNow--> published（已发布对象只切公开指针）
 *   published --withhold--> published + withheld
 *   withheld  --restore-->  published + normal
 *   任意      --retract-->  disposition=retracted（终态）
 *
 * 冻结约束：
 *   · 发布只允许 `approved_revision_id` 指向的 `review_state=approved` revision；
 *   · 已发布对象发布新候选时 `publish_status` 保持 published，旧版本公开到切换那一刻；
 *   · 发布 / 排期 / 恢复都完整重检 PublicationPreconditions + organizer verified + B 开关；
 *   · `published_revision_id` 只能经 PublicationPointerSwitcher 写入；
 *   · 父行锁 → `expected_row_version` CAS → revision 行锁 → 重检 → 切换 → outbox → 审计，
 *     全部在同一事务内完成；
 *   · 重复发布同一 revision、重复暂缓、重复撤稿是 no-op：不写 outbox、不推 row_version；
 *   · schedule / unschedule 不写 outbox（排期不是“已发生事实”）。
 */
class EventPublicationService
{
    public function __construct(
        private readonly PublicationPreconditions $preconditions,
        private readonly PublicationAudit $audit,
        private readonly PublicationState $state,
        private readonly PublicationPointerSwitcher $switcher,
        private readonly PublicationOutbox $outbox,
    ) {}

    /**
     * 排期发布：只允许当前已批准候选，时间必须是未来的 UTC 时刻。
     *
     * 从未发布对象：approved -> scheduled。
     * 已发布对象：publish_status 保持 published，仅写 approved_revision_id + scheduled_publish_at，
     * 旧版本继续公开到 G1-09 扫描到点调用 `publishScheduled()`。
     */
    public function schedule(
        Event $event,
        EventRevision $revision,
        int $actorId,
        int $expectedRowVersion,
        DateTimeInterface $publishAt,
    ): Event {
        $this->assertActorExists($actorId);

        $publishAtUtc = Carbon::instance($publishAt)->utc();
        $now = now('UTC');

        if ($publishAtUtc->lessThanOrEqualTo($now)) {
            throw $this->state->transition('event', sprintf(
                '排期时间必须是未来 UTC 时刻；收到 %s（UTC）。',
                $publishAtUtc->format('Y-m-d H:i:s'),
            ));
        }

        return DB::transaction(function () use ($event, $revision, $actorId, $expectedRowVersion, $publishAtUtc): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertPublishEnabled('event');
            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertKnownDisposition('event', (string) $lockedEvent->disposition);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);
            $this->state->assertDispositionNormal('event', (string) $lockedEvent->disposition);

            $this->assertRevisionIsApprovedCandidate($lockedEvent, $lockedRevision);
            $this->preconditions->assertEventPublishable($lockedEvent, $lockedRevision);

            $fromStatus = (string) $lockedEvent->publish_status;
            $targetStatus = $this->state->targetStatus('event', 'schedule', $fromStatus);

            $lockedEvent->publish_status = $targetStatus;
            $lockedEvent->approved_revision_id = (int) $lockedRevision->getKey();
            $lockedEvent->scheduled_publish_at = $publishAtUtc;
            $lockedEvent->row_version = (int) $lockedEvent->row_version + 1;
            $lockedEvent->save();

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.schedule'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'scheduled'),
            );

            return $lockedEvent;
        });
    }

    /**
     * 取消排期：scheduled -> approved；已发布对象保持 published。只清时间，不删 revision。
     */
    public function unschedule(Event $event, int $actorId, int $expectedRowVersion): Event
    {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($event, $actorId, $expectedRowVersion): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);

            // 停止未来的发布是安全动作：即使发布开关关闭也必须可用。
            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            if ($lockedEvent->scheduled_publish_at === null) {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 当前没有可取消的排期。',
                    (int) $lockedEvent->getKey(),
                ));
            }

            $fromStatus = (string) $lockedEvent->publish_status;
            $targetStatus = $this->state->targetStatus('event', 'unschedule', $fromStatus);

            $lockedEvent->publish_status = $targetStatus;
            $lockedEvent->scheduled_publish_at = null;
            $lockedEvent->row_version = (int) $lockedEvent->row_version + 1;
            $lockedEvent->save();

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.unschedule'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($this->approvedRevision($lockedEvent), $fromStatus, $targetStatus, 'unscheduled'),
            );

            return $lockedEvent;
        });
    }

    /**
     * 立即发布：只允许已批准候选。重复发布同一 revision 是 no-op。
     */
    public function publishNow(
        Event $event,
        EventRevision $revision,
        int $actorId,
        int $expectedRowVersion,
    ): Event {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($event, $revision, $actorId, $expectedRowVersion): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertKnownDisposition('event', (string) $lockedEvent->disposition);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            if ($this->isAlreadyPublished($lockedEvent, $lockedRevision)) {
                return $lockedEvent;
            }

            $this->state->assertDispositionNormal('event', (string) $lockedEvent->disposition);
            $this->assertRevisionIsApprovedCandidate($lockedEvent, $lockedRevision);

            return $this->publishLocked(
                $lockedEvent,
                $lockedRevision,
                $actorId,
                'published',
            );
        });
    }

    /**
     * G1-09 系统入口：只处理到期的 scheduled / 已发布对象候选。
     *
     * 到期条件：`scheduled_publish_at` 非空且 <= 当前 UTC。
     * 任一前置不满足 → 抛异常、**不切换**；回退 approved / 告警由 G1-09 负责。
     */
    public function publishScheduled(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $lockedEvent = $this->lockEvent($event);

            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertKnownDisposition('event', (string) $lockedEvent->disposition);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            $status = (string) $lockedEvent->publish_status;

            if (! in_array($status, ['scheduled', 'published'], true)) {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 当前 publish_status=%s，不能执行系统到期发布。',
                    (int) $lockedEvent->getKey(),
                    $status,
                ));
            }

            if ($lockedEvent->scheduled_publish_at === null) {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 没有 scheduled_publish_at，不能执行系统到期发布。',
                    (int) $lockedEvent->getKey(),
                ));
            }

            if ($lockedEvent->scheduled_publish_at->greaterThan(now('UTC'))) {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 的排期时间 %s（UTC）尚未到期。',
                    (int) $lockedEvent->getKey(),
                    $lockedEvent->scheduled_publish_at->format('Y-m-d H:i:s'),
                ));
            }

            $approvedRevisionId = $lockedEvent->approved_revision_id;

            if ($approvedRevisionId === null) {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 没有已批准候选版本，不能执行系统到期发布。',
                    (int) $lockedEvent->getKey(),
                ));
            }

            $lockedRevision = EventRevision::query()
                ->whereKey((int) $approvedRevisionId)
                ->lockForUpdate()
                ->first();

            if ($lockedRevision === null) {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 的已批准候选 revision %d 不存在。',
                    (int) $lockedEvent->getKey(),
                    (int) $approvedRevisionId,
                ));
            }

            $this->state->assertDispositionNormal('event', (string) $lockedEvent->disposition);
            $this->assertRevisionIsApprovedCandidate($lockedEvent, $lockedRevision);

            return $this->publishLocked(
                $lockedEvent,
                $lockedRevision,
                null,
                'scheduled_published',
            );
        });
    }

    /**
     * 暂缓：只作用于当前已发布对象；公开指针保留，候选与排期全部失效。
     *
     * `$reason` 必填，但只写内部日志与稳定的审计原因码，绝不进入公开字段。
     */
    public function withhold(
        Event $event,
        int $actorId,
        int $expectedRowVersion,
        string $reason,
    ): Event {
        $this->assertActorExists($actorId);
        $reason = $this->normalizeDispositionReason($reason, '暂缓活动');

        $result = DB::transaction(function () use ($event, $actorId, $expectedRowVersion): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);

            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertKnownDisposition('event', (string) $lockedEvent->disposition);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            if ((string) $lockedEvent->disposition === 'withheld') {
                return $lockedEvent;
            }

            $this->assertCurrentlyPublished($lockedEvent);
            $revision = $this->lockPublishedRevision($lockedEvent);

            $expectedRowVersion = (int) $lockedEvent->row_version;
            $lockedEvent->disposition = 'withheld';
            $lockedEvent->approved_revision_id = null;
            $lockedEvent->scheduled_publish_at = null;
            $lockedEvent->row_version = $expectedRowVersion + 1;
            $lockedEvent->save();

            $this->outbox->record(
                'event',
                (int) $lockedEvent->getKey(),
                'withhold',
                (int) $revision->getKey(),
                (int) $revision->revision_no,
                $expectedRowVersion + 1,
                (string) config('ecosystem_publishing.reason_codes.withheld'),
            );

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.withhold'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($revision, 'normal', 'withheld', 'withheld'),
            );

            return $lockedEvent;
        });

        Log::info('ecosystem.event.withheld', [
            'event_id' => (int) $result->getKey(),
            'reason'   => $reason,
        ]);

        return $result;
    }

    /**
     * 恢复：仅 withheld -> normal；重新核验当前公开 revision 的全部前置。
     * 不提供自动恢复。
     */
    public function restore(Event $event, int $actorId, int $expectedRowVersion): Event
    {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($event, $actorId, $expectedRowVersion): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);

            $this->state->assertPublishEnabled('event');
            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertKnownDisposition('event', (string) $lockedEvent->disposition);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            if ((string) $lockedEvent->disposition !== 'withheld') {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 当前 disposition=%s，只有 withheld 可以恢复。',
                    (int) $lockedEvent->getKey(),
                    (string) $lockedEvent->disposition,
                ));
            }

            $this->assertCurrentlyPublished($lockedEvent);
            $revision = $this->lockPublishedRevision($lockedEvent);
            $this->preconditions->assertEventPublishable($lockedEvent, $revision);

            $expectedRowVersion = (int) $lockedEvent->row_version;
            $lockedEvent->disposition = 'normal';
            $lockedEvent->row_version = $expectedRowVersion + 1;
            $lockedEvent->save();

            $this->outbox->record(
                'event',
                (int) $lockedEvent->getKey(),
                'restore',
                (int) $revision->getKey(),
                (int) $revision->revision_no,
                $expectedRowVersion + 1,
                (string) config('ecosystem_publishing.reason_codes.restored'),
            );

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.restore'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($revision, 'withheld', 'normal', 'restored'),
            );

            return $lockedEvent;
        });
    }

    /**
     * 撤稿：disposition=retracted 终态。公开指针保留作审计，候选与排期清空。
     *
     * `$reason` 必填，只写内部日志与稳定审计原因码。
     */
    public function retract(
        Event $event,
        int $actorId,
        int $expectedRowVersion,
        string $reason,
    ): Event {
        $this->assertActorExists($actorId);
        $reason = $this->normalizeDispositionReason($reason, '撤稿活动');

        $result = DB::transaction(function () use ($event, $actorId, $expectedRowVersion): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);

            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertKnownDisposition('event', (string) $lockedEvent->disposition);

            if ((string) $lockedEvent->disposition === 'retracted') {
                return $lockedEvent;
            }

            $fromDisposition = (string) $lockedEvent->disposition;
            $revision = $this->currentPublishedRevision($lockedEvent);

            $expectedRowVersion = (int) $lockedEvent->row_version;
            $lockedEvent->disposition = 'retracted';
            $lockedEvent->approved_revision_id = null;
            $lockedEvent->scheduled_publish_at = null;
            $lockedEvent->row_version = $expectedRowVersion + 1;
            $lockedEvent->save();

            $this->outbox->record(
                'event',
                (int) $lockedEvent->getKey(),
                'retract',
                $revision === null ? 0 : (int) $revision->getKey(),
                $revision === null ? 0 : (int) $revision->revision_no,
                $expectedRowVersion + 1,
                (string) config('ecosystem_publishing.reason_codes.retracted'),
            );

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.retract'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($revision, $fromDisposition, 'retracted', 'retracted'),
            );

            return $lockedEvent;
        });

        Log::info('ecosystem.event.retracted', [
            'event_id' => (int) $result->getKey(),
            'reason'   => $reason,
        ]);

        return $result;
    }

    /**
     * 真正的切换（人工发布与系统到期发布共用）。
     */
    private function publishLocked(
        Event $lockedEvent,
        EventRevision $lockedRevision,
        ?int $actorId,
        string $reasonKey,
    ): Event {
        $this->state->assertPublishEnabled('event');
        $this->state->assertDispositionNormal('event', (string) $lockedEvent->disposition);
        $this->preconditions->assertEventPublishable($lockedEvent, $lockedRevision);

        $fromStatus = (string) $lockedEvent->publish_status;
        $targetStatus = $this->state->targetStatus('event', 'publish', $fromStatus);
        $expectedRowVersion = (int) $lockedEvent->row_version;
        $now = now('UTC');

        $published = $this->switcher->switchEvent(
            $lockedEvent,
            $lockedRevision,
            $expectedRowVersion,
            $now,
        );

        // revision.published_at 只在第一次成为公开版本时写入。
        if ($lockedRevision->published_at === null) {
            $lockedRevision->published_at = $now;
            $lockedRevision->save();
        }

        $this->outbox->record(
            'event',
            (int) $published->getKey(),
            'publish',
            (int) $lockedRevision->getKey(),
            (int) $lockedRevision->revision_no,
            $expectedRowVersion + 1,
            (string) config('ecosystem_publishing.reason_codes.'.$reasonKey),
        );

        $auditAction = (string) config('ecosystem_publishing.audit_actions.event.publish');
        $auditPayload = $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, $reasonKey);

        if ($actorId === null) {
            $this->audit->recordSystem('event', $auditAction, (int) $published->getKey(), $auditPayload);
        } else {
            $this->audit->record('event', $auditAction, $actorId, (int) $published->getKey(), $auditPayload);
        }

        return $published;
    }

    /**
     * 目标 revision 必须是父对象当前已批准候选且仍是 approved。
     */
    private function assertRevisionIsApprovedCandidate(Event $event, EventRevision $revision): void
    {
        if ((int) $revision->event_id !== (int) $event->getKey()) {
            throw $this->state->transition('event', sprintf(
                'revision %d 不属于活动 %d，不能发布。',
                (int) $revision->getKey(),
                (int) $event->getKey(),
            ));
        }

        if ((string) $revision->review_state !== 'approved') {
            throw $this->state->transition('event', sprintf(
                '活动 revision %d 当前 review_state=%s，只有 approved 可以发布 / 排期。',
                (int) $revision->getKey(),
                (string) $revision->review_state,
            ));
        }

        if ($event->approved_revision_id === null
            || (int) $event->approved_revision_id !== (int) $revision->getKey()) {
            throw $this->state->transition('event', sprintf(
                '活动 %d 当前已批准候选是 %s，不是 revision %d，不能发布 / 排期。',
                (int) $event->getKey(),
                $event->approved_revision_id === null ? '(无)' : (string) $event->approved_revision_id,
                (int) $revision->getKey(),
            ));
        }
    }

    private function isAlreadyPublished(Event $event, EventRevision $revision): bool
    {
        return (string) $event->publish_status === 'published'
            && $event->published_revision_id !== null
            && (int) $event->published_revision_id === (int) $revision->getKey()
            && (string) $event->disposition === 'normal';
    }

    private function assertCurrentlyPublished(Event $event): void
    {
        if ((string) $event->publish_status !== 'published' || $event->published_revision_id === null) {
            throw $this->state->transition('event', sprintf(
                '活动 %d 当前不是已发布状态，不能执行该处置动作。',
                (int) $event->getKey(),
            ));
        }
    }

    private function lockPublishedRevision(Event $event): EventRevision
    {
        return EventRevision::query()
            ->whereKey((int) $event->published_revision_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 撤稿允许作用于任意状态；有公开版本就锁它，没有则返回 NULL。
     */
    private function currentPublishedRevision(Event $event): ?EventRevision
    {
        if ($event->published_revision_id === null) {
            return null;
        }

        return $this->lockPublishedRevision($event);
    }

    private function approvedRevision(Event $event): ?EventRevision
    {
        if ($event->approved_revision_id === null) {
            return null;
        }

        return EventRevision::query()->find($event->approved_revision_id);
    }

    private function lockEvent(Event $event): Event
    {
        if ($event->getKey() === null) {
            throw new InvalidArgumentException('发布活动前必须先保存活动。');
        }

        // 锁顺序统一：父对象先锁，再锁 revision。
        return Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockRevision(EventRevision $revision): EventRevision
    {
        if ($revision->getKey() === null) {
            throw new InvalidArgumentException('发布 revision 前必须先保存 revision。');
        }

        return EventRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertRowVersion(Event $event, int $expectedRowVersion): void
    {
        $actual = (int) $event->row_version;

        if ($actual !== $expectedRowVersion) {
            throw new EventRevisionConflictException(
                (int) $event->getKey(),
                $expectedRowVersion,
                $actual,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(
        ?EventRevision $revision,
        string $from,
        string $to,
        string $reasonKey,
    ): array {
        return [
            'revision_id'         => $revision === null ? 0 : (int) $revision->getKey(),
            'revision_no'         => $revision === null ? 0 : (int) $revision->revision_no,
            'from'                => $from,
            'to'                  => $to,
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'reason_code'         => (string) config('ecosystem_publishing.reason_codes.'.$reasonKey),
        ];
    }

    private function normalizeDispositionReason(string $reason, string $action): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(sprintf('%s必须填写非空内部原因。', $action));
        }

        $max = (int) config('ecosystem_publishing.disposition_reason_max_length', 500);

        if (mb_strlen($reason) > $max) {
            throw new InvalidArgumentException(sprintf('%s原因不得超过 %d 个字符。', $action, $max));
        }

        return $reason;
    }

    private function assertActorExists(int $actorId): void
    {
        if ($actorId <= 0 || ! DB::table('admins')->where('id', $actorId)->exists()) {
            throw new InvalidArgumentException(sprintf('发布动作需要存在的后台账号，admin_id=%d 无效。', $actorId));
        }
    }
}
