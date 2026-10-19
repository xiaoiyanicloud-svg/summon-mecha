<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 活动审核闭环（ADR-0001 §5.1 / §8.3 / 附录 B / G1-08A）。
 *
 * 状态机（只做这三步，发布/排期属于 G1-08B）：
 *
 *   draft --submit--> in_review --approve--> approved
 *                            \--reject---> rejected（从未发布对象父回 draft）
 *
 * 已发布对象（publish_status=published）提交/批准/拒绝后父状态保持 published，
 * published_revision_id 不变；候选版本由 approved_revision_id 表达。
 * 拒绝表示该候选已被否决：不论父对象是否 published，一律清空
 * approved_revision_id（不得残留可发布指针），scheduled_publish_at 存在也清空，
 * 避免旧排期复活；published_revision_id 仍不变。
 *
 * 并发：父活动行锁 + `expected_row_version` CAS。两人同时操作只有一个成功，
 * 另一个拿到 `EventRevisionConflictException`（409 EVENT_REVISION_CONFLICT）。
 *
 * 事务：状态写、revision 写、审计（OperationLogger::logOrFail）同一事务；
 * 审计失败整个动作回滚。本类不写 outbox、不写 published_revision_id、
 * 不写 scheduled_publish_at 非空值（拒绝时只清空）。
 */
class EventReviewService
{
    public function __construct(
        private readonly PublicationPreconditions $preconditions,
        private readonly PublicationAudit $audit,
        private readonly PublicationState $state,
        private readonly RevisionPointerValidator $pointers,
    ) {}

    public function submit(
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

            $this->state->assertReviewEnabled('event');
            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            if ((int) $lockedRevision->event_id !== (int) $lockedEvent->getKey()) {
                throw $this->state->transition('event', 'revision 不属于该活动，不能提交。');
            }

            if ((string) $lockedRevision->review_state !== 'draft') {
                throw $this->state->transition('event', sprintf(
                    '活动 revision %d 当前 review_state=%s，只有 draft 可以提交。',
                    (int) $lockedRevision->getKey(),
                    (string) $lockedRevision->review_state,
                ));
            }

            // working 指针只指 draft；指定 revision 必须是当前工作版本（或尚未挂工作版本）。
            if ($lockedEvent->working_revision_id !== null
                && (int) $lockedEvent->working_revision_id !== (int) $lockedRevision->getKey()) {
                throw $this->state->transition('event', sprintf(
                    '活动 %d 当前工作版本是 revision %d，不能提交 revision %d。',
                    (int) $lockedEvent->getKey(),
                    (int) $lockedEvent->working_revision_id,
                    (int) $lockedRevision->getKey(),
                ));
            }

            $this->preconditions->assertEventSubmittable($lockedEvent, $lockedRevision, true);

            $fromStatus = (string) $lockedEvent->publish_status;
            $targetStatus = $this->state->targetStatus('event', 'submit', $fromStatus);

            $this->applyRevisionTransition($lockedRevision, 'in_review', $actorId, null);

            $lockedEvent->publish_status = $targetStatus;
            $this->clearWorkingPointerIfMatches($lockedEvent, $lockedRevision);
            $lockedEvent->row_version = (int) $lockedEvent->row_version + 1;
            $lockedEvent->save();

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.submit'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'submitted'),
            );

            return $lockedEvent;
        });
    }

    public function approve(
        Event $event,
        EventRevision $revision,
        int $actorId,
        int $expectedRowVersion,
        ?string $reviewNote = null,
    ): Event {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($event, $revision, $actorId, $expectedRowVersion, $reviewNote): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertReviewEnabled('event');
            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            if ((int) $lockedRevision->event_id !== (int) $lockedEvent->getKey()) {
                throw $this->state->transition('event', 'revision 不属于该活动，不能批准。');
            }

            if ((string) $lockedRevision->review_state !== 'in_review') {
                throw $this->state->transition('event', sprintf(
                    '活动 revision %d 当前 review_state=%s，只有 in_review 可以批准。',
                    (int) $lockedRevision->getKey(),
                    (string) $lockedRevision->review_state,
                ));
            }

            // 批准必须重做全部前置：指纹 / manifest / sanitizer / 来源 / 媒体。
            $this->preconditions->assertEventSubmittable($lockedEvent, $lockedRevision, false);

            $fromStatus = (string) $lockedEvent->publish_status;
            $targetStatus = $this->state->targetStatus('event', 'approve', $fromStatus);

            $this->applyRevisionTransition($lockedRevision, 'approved', $actorId, $reviewNote);

            $lockedEvent->publish_status = $targetStatus;
            // 已批准候选版本；已发布对象的 published_revision_id 保持不变。
            $lockedEvent->approved_revision_id = (int) $lockedRevision->getKey();
            $this->clearWorkingPointerIfMatches($lockedEvent, $lockedRevision);
            $this->pointers->assertEventApprovedAssignable($lockedEvent);
            $lockedEvent->row_version = (int) $lockedEvent->row_version + 1;
            $lockedEvent->save();

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.approve'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'approved'),
            );

            return $lockedEvent;
        });
    }

    public function reject(
        Event $event,
        EventRevision $revision,
        int $actorId,
        int $expectedRowVersion,
        string $reason,
    ): Event {
        $this->assertActorExists($actorId);
        $reason = $this->normalizeRejectReason($reason);

        return DB::transaction(function () use ($event, $revision, $actorId, $expectedRowVersion, $reason): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertReviewEnabled('event');
            $this->state->assertKnownPublishStatus('event', (string) $lockedEvent->publish_status);
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            if ((int) $lockedRevision->event_id !== (int) $lockedEvent->getKey()) {
                throw $this->state->transition('event', 'revision 不属于该活动，不能拒绝。');
            }

            if ((string) $lockedRevision->review_state !== 'in_review') {
                throw $this->state->transition('event', sprintf(
                    '活动 revision %d 当前 review_state=%s，只有 in_review 可以拒绝。',
                    (int) $lockedRevision->getKey(),
                    (string) $lockedRevision->review_state,
                ));
            }

            $fromStatus = (string) $lockedEvent->publish_status;
            $targetStatus = $this->state->targetStatus('event', 'reject', $fromStatus);

            // 拒绝不重新打开原 revision；内部原因只写 revision.review_note。
            $this->applyRevisionTransition($lockedRevision, 'rejected', $actorId, $reason);

            $lockedEvent->publish_status = $targetStatus;

            // 被拒绝的候选不得残留可发布指针；已发布对象的公开指针不变。
            // 不论父对象是否 published 都清空，避免旧批准 / 旧排期复活。
            $lockedEvent->approved_revision_id = null;
            $lockedEvent->scheduled_publish_at = null;

            $this->clearWorkingPointerIfMatches($lockedEvent, $lockedRevision);
            $lockedEvent->row_version = (int) $lockedEvent->row_version + 1;
            $lockedEvent->save();

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.reject'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'review_rejected'),
            );

            return $lockedEvent;
        });
    }

    private function lockEvent(Event $event): Event
    {
        if ($event->getKey() === null) {
            throw new InvalidArgumentException('审核活动前必须先保存活动。');
        }

        return Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockRevision(EventRevision $revision): EventRevision
    {
        if ($revision->getKey() === null) {
            throw new InvalidArgumentException('审核 revision 前必须先保存 revision。');
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

    private function applyRevisionTransition(
        EventRevision $revision,
        string $state,
        int $actorId,
        ?string $note,
    ): void {
        $revision->review_state = $state;
        $revision->submitted_by = $revision->submitted_by ?? $actorId;

        if ($revision->submitted_at === null) {
            $revision->submitted_at = now('UTC');
        }

        $revision->reviewed_by = $actorId;
        $revision->reviewed_at = now('UTC');
        $revision->review_note = $note;
        $revision->save();
    }

    /**
     * working 指针只指 draft：revision 离开 draft 时清掉指向它的工作指针。
     */
    private function clearWorkingPointerIfMatches(Event $event, EventRevision $revision): void
    {
        if ($event->working_revision_id !== null
            && (int) $event->working_revision_id === (int) $revision->getKey()) {
            $event->working_revision_id = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(
        EventRevision $revision,
        string $from,
        string $to,
        string $reasonCode,
    ): array {
        return [
            'revision_id'         => (int) $revision->getKey(),
            'revision_no'         => (int) $revision->revision_no,
            'from'                => $from,
            'to'                  => $to,
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'reason_code'         => (string) config('ecosystem_publishing.reason_codes.'.$reasonCode),
        ];
    }

    private function normalizeRejectReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw $this->state->transition('event', '拒绝活动 revision 必须填写非空内部原因。');
        }

        if (mb_strlen($reason) > 5000) {
            throw new InvalidArgumentException('拒绝原因不得超过 5000 个字符。');
        }

        return $reason;
    }

    private function assertActorExists(int $actorId): void
    {
        if ($actorId <= 0 || ! DB::table('admins')->where('id', $actorId)->exists()) {
            throw new InvalidArgumentException(sprintf('审核动作需要存在的后台账号，admin_id=%d 无效。', $actorId));
        }
    }
}
