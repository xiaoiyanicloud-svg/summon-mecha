<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;

/**
 * 内容审核闭环（ADR-0001 §5.1 / §8.3 / 附录 B / G1-08A）。
 *
 * 与 EventReviewService 同构：
 *   draft --submit--> in_review --approve--> approved
 *                            \--reject---> rejected（从未发布对象父回 draft）
 *
 * 已发布对象提交/批准/拒绝后 publish_status 保持 published，
 * published_revision_id 不变；approved_revision_id 指向新候选。
 * 拒绝表示该候选已被否决：不论父对象是否 published，一律清空
 * approved_revision_id（不得残留可发布指针），scheduled_publish_at 存在也清空，
 * 避免旧排期复活；published_revision_id 仍不变。
 *
 * B-04 / B-07 未签署：本类只提供 submit/approve/reject 演练，
 * 不提供发布/排期，不打开任何公开开关。
 *
 * 并发：父文章行锁 + `expected_row_version` CAS；
 * 审计与状态写同事务（logOrFail），审计失败整个动作回滚。
 */
class ContentReviewService
{
    public function __construct(
        private readonly PublicationPreconditions $preconditions,
        private readonly PublicationAudit $audit,
        private readonly PublicationState $state,
        private readonly RevisionPointerValidator $pointers,
    ) {}

    public function submit(
        ContentPost $post,
        ContentRevision $revision,
        int $actorId,
        int $expectedRowVersion,
    ): ContentPost {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($post, $revision, $actorId, $expectedRowVersion): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertReviewEnabled('content');
            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            if ((int) $lockedRevision->post_id !== (int) $lockedPost->getKey()) {
                throw $this->state->transition('content', 'revision 不属于该文章，不能提交。');
            }

            if ((string) $lockedRevision->review_state !== 'draft') {
                throw $this->state->transition('content', sprintf(
                    '内容 revision %d 当前 review_state=%s，只有 draft 可以提交。',
                    (int) $lockedRevision->getKey(),
                    (string) $lockedRevision->review_state,
                ));
            }

            if ($lockedPost->working_revision_id !== null
                && (int) $lockedPost->working_revision_id !== (int) $lockedRevision->getKey()) {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 当前工作版本是 revision %d，不能提交 revision %d。',
                    (int) $lockedPost->getKey(),
                    (int) $lockedPost->working_revision_id,
                    (int) $lockedRevision->getKey(),
                ));
            }

            $this->preconditions->assertContentSubmittable($lockedPost, $lockedRevision, true);

            $fromStatus = (string) $lockedPost->publish_status;
            $targetStatus = $this->state->targetStatus('content', 'submit', $fromStatus);

            $this->applyRevisionTransition($lockedRevision, 'in_review', $actorId, null);

            $lockedPost->publish_status = $targetStatus;
            $this->clearWorkingPointerIfMatches($lockedPost, $lockedRevision);
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = (int) $lockedPost->row_version + 1;
            $lockedPost->save();

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.submit'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'submitted'),
            );

            return $lockedPost;
        });
    }

    public function approve(
        ContentPost $post,
        ContentRevision $revision,
        int $actorId,
        int $expectedRowVersion,
        ?string $reviewNote = null,
    ): ContentPost {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($post, $revision, $actorId, $expectedRowVersion, $reviewNote): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertReviewEnabled('content');
            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            if ((int) $lockedRevision->post_id !== (int) $lockedPost->getKey()) {
                throw $this->state->transition('content', 'revision 不属于该文章，不能批准。');
            }

            if ((string) $lockedRevision->review_state !== 'in_review') {
                throw $this->state->transition('content', sprintf(
                    '内容 revision %d 当前 review_state=%s，只有 in_review 可以批准。',
                    (int) $lockedRevision->getKey(),
                    (string) $lockedRevision->review_state,
                ));
            }

            $this->preconditions->assertContentSubmittable($lockedPost, $lockedRevision, false);

            $fromStatus = (string) $lockedPost->publish_status;
            $targetStatus = $this->state->targetStatus('content', 'approve', $fromStatus);

            $this->applyRevisionTransition($lockedRevision, 'approved', $actorId, $reviewNote);

            $lockedPost->publish_status = $targetStatus;
            $lockedPost->approved_revision_id = (int) $lockedRevision->getKey();
            $this->clearWorkingPointerIfMatches($lockedPost, $lockedRevision);
            $this->pointers->assertContentApprovedAssignable($lockedPost);
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = (int) $lockedPost->row_version + 1;
            $lockedPost->save();

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.approve'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'approved'),
            );

            return $lockedPost;
        });
    }

    public function reject(
        ContentPost $post,
        ContentRevision $revision,
        int $actorId,
        int $expectedRowVersion,
        string $reason,
    ): ContentPost {
        $this->assertActorExists($actorId);
        $reason = $this->normalizeRejectReason($reason);

        return DB::transaction(function () use ($post, $revision, $actorId, $expectedRowVersion, $reason): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertReviewEnabled('content');
            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            if ((int) $lockedRevision->post_id !== (int) $lockedPost->getKey()) {
                throw $this->state->transition('content', 'revision 不属于该文章，不能拒绝。');
            }

            if ((string) $lockedRevision->review_state !== 'in_review') {
                throw $this->state->transition('content', sprintf(
                    '内容 revision %d 当前 review_state=%s，只有 in_review 可以拒绝。',
                    (int) $lockedRevision->getKey(),
                    (string) $lockedRevision->review_state,
                ));
            }

            $fromStatus = (string) $lockedPost->publish_status;
            $targetStatus = $this->state->targetStatus('content', 'reject', $fromStatus);

            $this->applyRevisionTransition($lockedRevision, 'rejected', $actorId, $reason);

            $lockedPost->publish_status = $targetStatus;

            // 被拒绝的候选不得残留可发布指针；已发布对象的公开指针不变。
            // 不论父对象是否 published 都清空，避免旧批准 / 旧排期复活。
            $lockedPost->approved_revision_id = null;
            $lockedPost->scheduled_publish_at = null;

            $this->clearWorkingPointerIfMatches($lockedPost, $lockedRevision);
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = (int) $lockedPost->row_version + 1;
            $lockedPost->save();

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.reject'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'review_rejected'),
            );

            return $lockedPost;
        });
    }

    private function lockPost(ContentPost $post): ContentPost
    {
        if ($post->getKey() === null) {
            throw new InvalidArgumentException('审核文章前必须先保存文章。');
        }

        return ContentPost::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockRevision(ContentRevision $revision): ContentRevision
    {
        if ($revision->getKey() === null) {
            throw new InvalidArgumentException('审核 revision 前必须先保存 revision。');
        }

        return ContentRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertRowVersion(ContentPost $post, int $expectedRowVersion): void
    {
        $actual = (int) $post->row_version;

        if ($actual !== $expectedRowVersion) {
            throw new ContentRevisionConflictException(
                (int) $post->getKey(),
                $expectedRowVersion,
                $actual,
            );
        }
    }

    private function applyRevisionTransition(
        ContentRevision $revision,
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

    private function clearWorkingPointerIfMatches(ContentPost $post, ContentRevision $revision): void
    {
        if ($post->working_revision_id !== null
            && (int) $post->working_revision_id === (int) $revision->getKey()) {
            $post->working_revision_id = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(
        ContentRevision $revision,
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
            throw $this->state->transition('content', '拒绝内容 revision 必须填写非空内部原因。');
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
