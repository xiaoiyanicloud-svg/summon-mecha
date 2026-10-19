<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 为已发布 / 已批准 / 已排期对象启动新 working draft（ADR-0001 §8.4 / 附录 B A-04）。
 *
 * 冻结语义：
 *   · 已发布对象：publish_status 与 published_revision_id **不变**，公开页继续
 *     读旧版本；但清 approved_revision_id、scheduled_publish_at —— 任何未公开
 *     候选被新 draft 取代即失效；
 *   · 已批准 / 已排期对象：原批准失效 —— 清 approved_revision_id、
 *     清 scheduled_publish_at、父回 draft；
 *   · 从未发布且仍是 draft：只挂 working 指针；
 *   · in_review / retracted：拒绝（先完成审核或对象已终态）；
 *   · 已有 working 指针：拒绝，避免同一对象并行多个草稿；
 *   · 同对象存在 `review_state=in_review` 的 revision：一律拒绝，不得并行审核
 *     （已发布对象提交后 working 指针为空，仅靠状态字段无法发现）。
 *
 * 并发：父对象行锁 + expected_row_version CAS；in_review 探测也在父行锁内。
 * 审计与状态写同事务（logOrFail）。
 *
 * 本类不写 published_revision_id、不写 scheduled_publish_at 非空值
 * （只清空）、不写 outbox。
 */
class BeginRevisionService
{
    public function __construct(
        private readonly EventRevisionService $eventRevisions,
        private readonly ContentRevisionService $contentRevisions,
        private readonly PublicationAudit $audit,
        private readonly PublicationState $state,
    ) {}

    /**
     * @param  array<string, mixed>  $data  EventRevisionService::CONTENT_FIELDS
     * @param  array<int, int|string>  $allowedMediaIds
     */
    public function startEventRevision(
        Event $event,
        int $actorId,
        int $expectedRowVersion,
        array $data,
        array $allowedMediaIds = [],
    ): EventRevision {
        return DB::transaction(function () use ($event, $actorId, $expectedRowVersion, $data, $allowedMediaIds): EventRevision {
            $lockedEvent = $this->lockEvent($event);
            $this->assertRowVersion($lockedEvent, $expectedRowVersion);
            $this->state->assertReviewEnabled('event');
            $this->state->assertDispositionActionable('event', (string) $lockedEvent->disposition);

            $fromStatus = (string) $lockedEvent->publish_status;
            $this->assertBeginAllowed('event', $fromStatus, $lockedEvent->working_revision_id);
            $this->assertNoRevisionInReview('event', (int) $lockedEvent->getKey());

            $revision = $this->eventRevisions->createDraft($lockedEvent, $actorId, $data, $allowedMediaIds);

            $lockedEvent->working_revision_id = (int) $revision->getKey();

            // 新草稿取代所有未公开候选：批准与排期一律失效。
            // 只清空；G1-08A 从不写入排期时间（写入属于 G1-08B）。
            $lockedEvent->approved_revision_id = null;
            $lockedEvent->scheduled_publish_at = null;

            // 已批准 / 已排期：父回到 draft。
            // published：publish_status / published_revision_id 保持不变。
            if (in_array($fromStatus, ['approved', 'scheduled'], true)) {
                $lockedEvent->publish_status = 'draft';
            }

            $lockedEvent->row_version = (int) $lockedEvent->row_version + 1;
            $lockedEvent->save();

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.begin_revision'),
                $actorId,
                (int) $lockedEvent->getKey(),
                $this->auditPayload($revision, $fromStatus, (string) $lockedEvent->publish_status),
            );

            return $revision;
        });
    }

    /**
     * @param  array<string, mixed>  $data  ContentRevisionService::CONTENT_FIELDS
     * @param  array<int, array<string, mixed>>  $sources
     * @param  array<int, int|string>  $allowedMediaIds
     */
    public function startContentRevision(
        ContentPost $post,
        int $actorId,
        int $expectedRowVersion,
        array $data,
        array $sources = [],
        array $allowedMediaIds = [],
    ): ContentRevision {
        return DB::transaction(function () use ($post, $actorId, $expectedRowVersion, $data, $sources, $allowedMediaIds): ContentRevision {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);
            $this->state->assertReviewEnabled('content');
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            $fromStatus = (string) $lockedPost->publish_status;
            $this->assertBeginAllowed('content', $fromStatus, $lockedPost->working_revision_id);
            $this->assertNoRevisionInReview('content', (int) $lockedPost->getKey());

            $revision = $this->contentRevisions->createDraft(
                $lockedPost,
                $actorId,
                $data,
                $sources,
                $allowedMediaIds,
            );

            $lockedPost->working_revision_id = (int) $revision->getKey();

            // 新草稿取代所有未公开候选：批准与排期一律失效。
            $lockedPost->approved_revision_id = null;
            $lockedPost->scheduled_publish_at = null;

            // 已批准 / 已排期：父回到 draft。
            // published：publish_status / published_revision_id 保持不变。
            if (in_array($fromStatus, ['approved', 'scheduled'], true)) {
                $lockedPost->publish_status = 'draft';
            }

            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = (int) $lockedPost->row_version + 1;
            $lockedPost->save();

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.begin_revision'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($revision, $fromStatus, (string) $lockedPost->publish_status),
            );

            return $revision;
        });
    }

    private function assertBeginAllowed(string $domain, string $status, mixed $workingRevisionId): void
    {
        if ($status === 'in_review') {
            throw $this->state->transition($domain, sprintf(
                '%s 当前有 revision 正在审核，不能开始新草稿；请先完成批准或拒绝。',
                $domain,
            ));
        }

        if (! in_array($status, ['draft', 'approved', 'scheduled', 'published'], true)) {
            throw $this->state->transition($domain, sprintf(
                '%s 当前 publish_status=%s，不能开始新草稿。',
                $domain,
                $status,
            ));
        }

        if ($workingRevisionId !== null) {
            throw $this->state->transition($domain, sprintf(
                '%s 已存在工作草稿 revision %d；请先提交或完成该草稿。',
                $domain,
                (int) $workingRevisionId,
            ));
        }
    }

    /**
     * 不得并行审核：已发布对象提交后 working 指针为空、父状态仍是 published，
     * 仅凭父行无法发现正在审核的 revision，必须显式查同对象 revision。
     * 调用方已在父对象行锁事务内。
     */
    private function assertNoRevisionInReview(string $domain, int $parentId): void
    {
        $inReviewId = $domain === 'event'
            ? EventRevision::query()
                ->where('event_id', $parentId)
                ->where('review_state', 'in_review')
                ->value('id')
            : ContentRevision::query()
                ->where('post_id', $parentId)
                ->where('review_state', 'in_review')
                ->value('id');

        if ($inReviewId !== null) {
            throw $this->state->transition($domain, sprintf(
                '%s %d 已有 revision %d 正在审核，不能开始新草稿；请先完成批准或拒绝。',
                $domain,
                $parentId,
                (int) $inReviewId,
            ));
        }
    }

    private function lockEvent(Event $event): Event
    {
        if ($event->getKey() === null) {
            throw new InvalidArgumentException('开始新活动草稿前必须先保存活动。');
        }

        return Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockPost(ContentPost $post): ContentPost
    {
        if ($post->getKey() === null) {
            throw new InvalidArgumentException('开始新内容草稿前必须先保存文章。');
        }

        return ContentPost::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertRowVersion(Event|ContentPost $parent, int $expectedRowVersion): void
    {
        $actual = (int) $parent->row_version;

        if ($actual !== $expectedRowVersion) {
            if ($parent instanceof Event) {
                throw new EventRevisionConflictException((int) $parent->getKey(), $expectedRowVersion, $actual);
            }

            throw new ContentRevisionConflictException((int) $parent->getKey(), $expectedRowVersion, $actual);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(EventRevision|ContentRevision $revision, string $from, string $to): array
    {
        return [
            'revision_id'         => (int) $revision->getKey(),
            'revision_no'         => (int) $revision->revision_no,
            'from'                => $from,
            'to'                  => $to,
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'reason_code'         => (string) config('ecosystem_publishing.reason_codes.new_working_draft'),
        ];
    }
}
