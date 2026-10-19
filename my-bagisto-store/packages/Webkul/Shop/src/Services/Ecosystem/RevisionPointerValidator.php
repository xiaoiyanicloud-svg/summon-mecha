<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * `events.published_revision_id` / `working_revision_id` 的写入守卫（ADR-0001 §4.2）。
 *
 * G1-04 只建立 revision 表与指针外键，**不开放发布动作**：
 *   · `published_revision_id` 任何非 NULL 写入一律拒绝；
 *     公开版本的切换必须由 G1-08 的发布状态机完成，不能借普通编辑入口绕过审核；
 *   · `working_revision_id` 可以写，但必须指向**同一活动**且 `review_state=draft`
 *     的 revision —— 允许挂一条别的活动的版本，会让工作区指针与归属漂移；
 *     允许挂已提审/已批准的版本，会让“编辑草稿”在审核后继续改公开内容来源；
 *   · NULL 允许：表示暂无工作版本（新建活动、清空工作区）。
 *
 * 异常统一为 `RevisionImmutableException`（HTTP 映射 EVENT_TRANSITION_INVALID），
 * 调用方应刷新后按合法动作重做。
 */
class RevisionPointerValidator
{
    public function assertAssignable(Event $event): void
    {
        if ($event->published_revision_id !== null) {
            throw new RevisionImmutableException(sprintf(
                '活动 %s 不允许在 G1-04 写入 published_revision_id；公开版本切换属于 G1-08 发布状态机。',
                $event->getKey() === null ? '(未保存)' : (string) $event->getKey(),
            ));
        }

        $workingRevisionId = $event->working_revision_id;

        if ($workingRevisionId === null) {
            return;
        }

        if ($event->getKey() === null) {
            throw new RevisionImmutableException('新建活动不能直接挂 working_revision_id；请先保存活动。');
        }

        $revision = EventRevision::query()->find($workingRevisionId);

        if ($revision === null) {
            throw new RevisionImmutableException(sprintf(
                '活动 %d 的 working_revision_id=%d 不存在。',
                (int) $event->getKey(),
                (int) $workingRevisionId,
            ));
        }

        if ((int) $revision->event_id !== (int) $event->getKey()) {
            throw new RevisionImmutableException(sprintf(
                '活动 %d 不能挂活动 %d 的 revision %d 作为工作版本。',
                (int) $event->getKey(),
                (int) $revision->event_id,
                (int) $workingRevisionId,
            ));
        }

        if ((string) $revision->review_state !== 'draft') {
            throw new RevisionImmutableException(sprintf(
                '活动 %d 的 revision %d 当前 review_state=%s，只有 draft 才能作为工作版本。',
                (int) $event->getKey(),
                (int) $workingRevisionId,
                (string) $revision->review_state,
            ));
        }
    }

    /**
     * G1-08A · `approved_revision_id` 的受控校验（ADR 附录 B A-01）。
     *
     * 唯一允许写入该指针的路径是审核服务（EventReviewService / BeginRevisionService）；
     * EventWriteService 的白名单**不包含**该字段，普通编辑入口不得透传。
     * 校验目标 revision 必须属于同一活动且 `review_state=approved`。
     * NULL 始终允许（拒绝审批、新建工作草稿时失效旧批准）。
     */
    public function assertEventApprovedAssignable(Event $event): void
    {
        $approvedRevisionId = $event->approved_revision_id;

        if ($approvedRevisionId === null) {
            return;
        }

        if ($event->getKey() === null) {
            throw new RevisionImmutableException('新建活动不能直接挂 approved_revision_id；请先保存活动。');
        }

        $revision = EventRevision::query()->find($approvedRevisionId);

        if ($revision === null) {
            throw new RevisionImmutableException(sprintf(
                '活动 %d 的 approved_revision_id=%d 不存在。',
                (int) $event->getKey(),
                (int) $approvedRevisionId,
            ));
        }

        if ((int) $revision->event_id !== (int) $event->getKey()) {
            throw new RevisionImmutableException(sprintf(
                '活动 %d 不能把活动 %d 的 revision %d 作为已批准版本。',
                (int) $event->getKey(),
                (int) $revision->event_id,
                (int) $approvedRevisionId,
            ));
        }

        if ((string) $revision->review_state !== 'approved') {
            throw new RevisionImmutableException(sprintf(
                '活动 %d 的 revision %d 当前 review_state=%s，只有 approved 才能作为已批准版本。',
                (int) $event->getKey(),
                (int) $approvedRevisionId,
                (string) $revision->review_state,
            ));
        }
    }

    /**
     * 内容域的 `approved_revision_id` 受控校验；语义同活动域。
     */
    public function assertContentApprovedAssignable(ContentPost $post): void
    {
        $approvedRevisionId = $post->approved_revision_id;

        if ($approvedRevisionId === null) {
            return;
        }

        if ($post->getKey() === null) {
            throw new ContentTransitionException('新建文章不能直接挂 approved_revision_id；请先保存文章。');
        }

        $revision = ContentRevision::query()->find($approvedRevisionId);

        if ($revision === null) {
            throw new ContentTransitionException(sprintf(
                '文章 %d 的 approved_revision_id=%d 不存在。',
                (int) $post->getKey(),
                (int) $approvedRevisionId,
            ));
        }

        if ((int) $revision->post_id !== (int) $post->getKey()) {
            throw new ContentTransitionException(sprintf(
                '文章 %d 不能把文章 %d 的 revision %d 作为已批准版本。',
                (int) $post->getKey(),
                (int) $revision->post_id,
                (int) $approvedRevisionId,
            ));
        }

        if ((string) $revision->review_state !== 'approved') {
            throw new ContentTransitionException(sprintf(
                '文章 %d 的 revision %d 当前 review_state=%s，只有 approved 才能作为已批准版本。',
                (int) $post->getKey(),
                (int) $approvedRevisionId,
                (string) $revision->review_state,
            ));
        }
    }
}
