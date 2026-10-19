<?php

namespace Webkul\Shop\Services\Ecosystem;

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
}
