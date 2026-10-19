<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;

/**
 * 资讯发布 / 排期 / 暂缓 / 恢复 / 撤稿（ADR-0001 §4.5 / §8.1~§8.4 / G1-08B）。
 *
 * 与 EventPublicationService 同构；差异：
 *   · 发布开关还要求 B-04（slug 最终规则）与 B-07（内容法律责任）已签署，
 *     由 `PublicationState::assertPublishEnabled('content')` 统一判断；
 *   · 写路径必须绕过 `ContentPost` 对 published 指针的模型守卫，
 *     因此公开指针只经 `PublicationPointerSwitcher::switchContent()` 切换；
 *   · 所有人工动作同步 `updated_by`。
 *
 * 语义、并发与事务边界见 EventPublicationService 的类注释。
 */
class ContentPublicationService
{
    public function __construct(
        private readonly PublicationPreconditions $preconditions,
        private readonly PublicationAudit $audit,
        private readonly PublicationState $state,
        private readonly PublicationPointerSwitcher $switcher,
        private readonly PublicationOutbox $outbox,
    ) {}

    public function schedule(
        ContentPost $post,
        ContentRevision $revision,
        int $actorId,
        int $expectedRowVersion,
        DateTimeInterface $publishAt,
    ): ContentPost {
        $this->assertActorExists($actorId);

        $publishAtUtc = Carbon::instance($publishAt)->utc();
        $now = now('UTC');

        if ($publishAtUtc->lessThanOrEqualTo($now)) {
            throw $this->state->transition('content', sprintf(
                '排期时间必须是未来 UTC 时刻；收到 %s（UTC）。',
                $publishAtUtc->format('Y-m-d H:i:s'),
            ));
        }

        return DB::transaction(function () use ($post, $revision, $actorId, $expectedRowVersion, $publishAtUtc): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);
            $lockedRevision = $this->lockRevision($revision);

            $this->state->assertPublishEnabled('content');
            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertKnownDisposition('content', (string) $lockedPost->disposition);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);
            $this->state->assertDispositionNormal('content', (string) $lockedPost->disposition);

            $this->assertRevisionIsApprovedCandidate($lockedPost, $lockedRevision);
            $this->preconditions->assertContentPublishable($lockedPost, $lockedRevision);

            $fromStatus = (string) $lockedPost->publish_status;
            $targetStatus = $this->state->targetStatus('content', 'schedule', $fromStatus);

            $lockedPost->publish_status = $targetStatus;
            $lockedPost->approved_revision_id = (int) $lockedRevision->getKey();
            $lockedPost->scheduled_publish_at = $publishAtUtc;
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = (int) $lockedPost->row_version + 1;
            $lockedPost->save();

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.schedule'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, 'scheduled'),
            );

            return $lockedPost;
        });
    }

    public function unschedule(ContentPost $post, int $actorId, int $expectedRowVersion): ContentPost
    {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($post, $actorId, $expectedRowVersion): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);

            // 停止未来的发布是安全动作：即使发布开关关闭也必须可用。
            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            if ($lockedPost->scheduled_publish_at === null) {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 当前没有可取消的排期。',
                    (int) $lockedPost->getKey(),
                ));
            }

            $fromStatus = (string) $lockedPost->publish_status;
            $targetStatus = $this->state->targetStatus('content', 'unschedule', $fromStatus);

            $lockedPost->publish_status = $targetStatus;
            $lockedPost->scheduled_publish_at = null;
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = (int) $lockedPost->row_version + 1;
            $lockedPost->save();

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.unschedule'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($this->approvedRevision($lockedPost), $fromStatus, $targetStatus, 'unscheduled'),
            );

            return $lockedPost;
        });
    }

    public function publishNow(
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

            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertKnownDisposition('content', (string) $lockedPost->disposition);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            if ($this->isAlreadyPublished($lockedPost, $lockedRevision)) {
                return $lockedPost;
            }

            $this->state->assertDispositionNormal('content', (string) $lockedPost->disposition);
            $this->assertRevisionIsApprovedCandidate($lockedPost, $lockedRevision);

            return $this->publishLocked($lockedPost, $lockedRevision, $actorId, 'published');
        });
    }

    public function publishScheduled(ContentPost $post): ContentPost
    {
        return DB::transaction(function () use ($post): ContentPost {
            $lockedPost = $this->lockPost($post);

            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertKnownDisposition('content', (string) $lockedPost->disposition);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            $status = (string) $lockedPost->publish_status;

            if (! in_array($status, ['scheduled', 'published'], true)) {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 当前 publish_status=%s，不能执行系统到期发布。',
                    (int) $lockedPost->getKey(),
                    $status,
                ));
            }

            if ($lockedPost->scheduled_publish_at === null) {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 没有 scheduled_publish_at，不能执行系统到期发布。',
                    (int) $lockedPost->getKey(),
                ));
            }

            if ($lockedPost->scheduled_publish_at->greaterThan(now('UTC'))) {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 的排期时间 %s（UTC）尚未到期。',
                    (int) $lockedPost->getKey(),
                    $lockedPost->scheduled_publish_at->format('Y-m-d H:i:s'),
                ));
            }

            $approvedRevisionId = $lockedPost->approved_revision_id;

            if ($approvedRevisionId === null) {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 没有已批准候选版本，不能执行系统到期发布。',
                    (int) $lockedPost->getKey(),
                ));
            }

            $lockedRevision = ContentRevision::query()
                ->whereKey((int) $approvedRevisionId)
                ->lockForUpdate()
                ->first();

            if ($lockedRevision === null) {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 的已批准候选 revision %d 不存在。',
                    (int) $lockedPost->getKey(),
                    (int) $approvedRevisionId,
                ));
            }

            $this->state->assertDispositionNormal('content', (string) $lockedPost->disposition);
            $this->assertRevisionIsApprovedCandidate($lockedPost, $lockedRevision);

            return $this->publishLocked($lockedPost, $lockedRevision, null, 'scheduled_published');
        });
    }

    public function withhold(
        ContentPost $post,
        int $actorId,
        int $expectedRowVersion,
        string $reason,
    ): ContentPost {
        $this->assertActorExists($actorId);
        $reason = $this->normalizeDispositionReason($reason, '暂缓文章');

        $result = DB::transaction(function () use ($post, $actorId, $expectedRowVersion): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);

            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertKnownDisposition('content', (string) $lockedPost->disposition);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            if ((string) $lockedPost->disposition === 'withheld') {
                return $lockedPost;
            }

            $this->assertCurrentlyPublished($lockedPost);
            $revision = $this->lockPublishedRevision($lockedPost);

            $expectedRowVersion = (int) $lockedPost->row_version;
            $lockedPost->disposition = 'withheld';
            $lockedPost->approved_revision_id = null;
            $lockedPost->scheduled_publish_at = null;
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = $expectedRowVersion + 1;
            $lockedPost->save();

            $this->outbox->record(
                'content',
                (int) $lockedPost->getKey(),
                'withhold',
                (int) $revision->getKey(),
                (int) $revision->revision_no,
                $expectedRowVersion + 1,
                (string) config('ecosystem_publishing.reason_codes.withheld'),
            );

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.withhold'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($revision, 'normal', 'withheld', 'withheld'),
            );

            return $lockedPost;
        });

        Log::info('ecosystem.content.withheld', [
            'content_post_id' => (int) $result->getKey(),
            'reason'          => $reason,
        ]);

        return $result;
    }

    public function restore(ContentPost $post, int $actorId, int $expectedRowVersion): ContentPost
    {
        $this->assertActorExists($actorId);

        return DB::transaction(function () use ($post, $actorId, $expectedRowVersion): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);

            $this->state->assertPublishEnabled('content');
            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertKnownDisposition('content', (string) $lockedPost->disposition);
            $this->state->assertDispositionActionable('content', (string) $lockedPost->disposition);

            if ((string) $lockedPost->disposition !== 'withheld') {
                throw $this->state->transition('content', sprintf(
                    '文章 %d 当前 disposition=%s，只有 withheld 可以恢复。',
                    (int) $lockedPost->getKey(),
                    (string) $lockedPost->disposition,
                ));
            }

            $this->assertCurrentlyPublished($lockedPost);
            $revision = $this->lockPublishedRevision($lockedPost);
            $this->preconditions->assertContentPublishable($lockedPost, $revision);

            $expectedRowVersion = (int) $lockedPost->row_version;
            $lockedPost->disposition = 'normal';
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = $expectedRowVersion + 1;
            $lockedPost->save();

            $this->outbox->record(
                'content',
                (int) $lockedPost->getKey(),
                'restore',
                (int) $revision->getKey(),
                (int) $revision->revision_no,
                $expectedRowVersion + 1,
                (string) config('ecosystem_publishing.reason_codes.restored'),
            );

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.restore'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($revision, 'withheld', 'normal', 'restored'),
            );

            return $lockedPost;
        });
    }

    public function retract(
        ContentPost $post,
        int $actorId,
        int $expectedRowVersion,
        string $reason,
    ): ContentPost {
        $this->assertActorExists($actorId);
        $reason = $this->normalizeDispositionReason($reason, '撤稿文章');

        $result = DB::transaction(function () use ($post, $actorId, $expectedRowVersion): ContentPost {
            $lockedPost = $this->lockPost($post);
            $this->assertRowVersion($lockedPost, $expectedRowVersion);

            $this->state->assertKnownPublishStatus('content', (string) $lockedPost->publish_status);
            $this->state->assertKnownDisposition('content', (string) $lockedPost->disposition);

            if ((string) $lockedPost->disposition === 'retracted') {
                return $lockedPost;
            }

            $fromDisposition = (string) $lockedPost->disposition;
            $revision = $this->currentPublishedRevision($lockedPost);

            $expectedRowVersion = (int) $lockedPost->row_version;
            $lockedPost->disposition = 'retracted';
            $lockedPost->approved_revision_id = null;
            $lockedPost->scheduled_publish_at = null;
            $lockedPost->updated_by = $actorId;
            $lockedPost->row_version = $expectedRowVersion + 1;
            $lockedPost->save();

            $this->outbox->record(
                'content',
                (int) $lockedPost->getKey(),
                'retract',
                $revision === null ? 0 : (int) $revision->getKey(),
                $revision === null ? 0 : (int) $revision->revision_no,
                $expectedRowVersion + 1,
                (string) config('ecosystem_publishing.reason_codes.retracted'),
            );

            $this->audit->record(
                'content',
                (string) config('ecosystem_publishing.audit_actions.content.retract'),
                $actorId,
                (int) $lockedPost->getKey(),
                $this->auditPayload($revision, $fromDisposition, 'retracted', 'retracted'),
            );

            return $lockedPost;
        });

        Log::info('ecosystem.content.retracted', [
            'content_post_id' => (int) $result->getKey(),
            'reason'          => $reason,
        ]);

        return $result;
    }

    private function publishLocked(
        ContentPost $lockedPost,
        ContentRevision $lockedRevision,
        ?int $actorId,
        string $reasonKey,
    ): ContentPost {
        $this->state->assertPublishEnabled('content');
        $this->state->assertDispositionNormal('content', (string) $lockedPost->disposition);
        $this->preconditions->assertContentPublishable($lockedPost, $lockedRevision);

        $fromStatus = (string) $lockedPost->publish_status;
        $targetStatus = $this->state->targetStatus('content', 'publish', $fromStatus);
        $expectedRowVersion = (int) $lockedPost->row_version;
        $now = now('UTC');

        $published = $this->switcher->switchContent(
            $lockedPost,
            $lockedRevision,
            $expectedRowVersion,
            $now,
            $actorId,
        );

        if ($lockedRevision->published_at === null) {
            $lockedRevision->published_at = $now;
            $lockedRevision->save();
        }

        $this->outbox->record(
            'content',
            (int) $published->getKey(),
            'publish',
            (int) $lockedRevision->getKey(),
            (int) $lockedRevision->revision_no,
            $expectedRowVersion + 1,
            (string) config('ecosystem_publishing.reason_codes.'.$reasonKey),
        );

        $auditAction = (string) config('ecosystem_publishing.audit_actions.content.publish');
        $auditPayload = $this->auditPayload($lockedRevision, $fromStatus, $targetStatus, $reasonKey);

        if ($actorId === null) {
            $this->audit->recordSystem('content', $auditAction, (int) $published->getKey(), $auditPayload);
        } else {
            $this->audit->record('content', $auditAction, $actorId, (int) $published->getKey(), $auditPayload);
        }

        return $published;
    }

    private function assertRevisionIsApprovedCandidate(ContentPost $post, ContentRevision $revision): void
    {
        if ((int) $revision->post_id !== (int) $post->getKey()) {
            throw $this->state->transition('content', sprintf(
                'revision %d 不属于文章 %d，不能发布。',
                (int) $revision->getKey(),
                (int) $post->getKey(),
            ));
        }

        if ((string) $revision->review_state !== 'approved') {
            throw $this->state->transition('content', sprintf(
                '内容 revision %d 当前 review_state=%s，只有 approved 可以发布 / 排期。',
                (int) $revision->getKey(),
                (string) $revision->review_state,
            ));
        }

        if ($post->approved_revision_id === null
            || (int) $post->approved_revision_id !== (int) $revision->getKey()) {
            throw $this->state->transition('content', sprintf(
                '文章 %d 当前已批准候选是 %s，不是 revision %d，不能发布 / 排期。',
                (int) $post->getKey(),
                $post->approved_revision_id === null ? '(无)' : (string) $post->approved_revision_id,
                (int) $revision->getKey(),
            ));
        }
    }

    private function isAlreadyPublished(ContentPost $post, ContentRevision $revision): bool
    {
        return (string) $post->publish_status === 'published'
            && $post->published_revision_id !== null
            && (int) $post->published_revision_id === (int) $revision->getKey()
            && (string) $post->disposition === 'normal';
    }

    private function assertCurrentlyPublished(ContentPost $post): void
    {
        if ((string) $post->publish_status !== 'published' || $post->published_revision_id === null) {
            throw $this->state->transition('content', sprintf(
                '文章 %d 当前不是已发布状态，不能执行该处置动作。',
                (int) $post->getKey(),
            ));
        }
    }

    private function lockPublishedRevision(ContentPost $post): ContentRevision
    {
        return ContentRevision::query()
            ->whereKey((int) $post->published_revision_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function currentPublishedRevision(ContentPost $post): ?ContentRevision
    {
        if ($post->published_revision_id === null) {
            return null;
        }

        return $this->lockPublishedRevision($post);
    }

    private function approvedRevision(ContentPost $post): ?ContentRevision
    {
        if ($post->approved_revision_id === null) {
            return null;
        }

        return ContentRevision::query()->find($post->approved_revision_id);
    }

    private function lockPost(ContentPost $post): ContentPost
    {
        if ($post->getKey() === null) {
            throw new InvalidArgumentException('发布文章前必须先保存文章。');
        }

        return ContentPost::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockRevision(ContentRevision $revision): ContentRevision
    {
        if ($revision->getKey() === null) {
            throw new InvalidArgumentException('发布 revision 前必须先保存 revision。');
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

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(
        ?ContentRevision $revision,
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
