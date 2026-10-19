<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 公开版本指针的**唯一受控写入入口**（ADR-0001 §8.2 / 附录 B A-04 / G1-08B）。
 *
 * 普通写入口（`EventWriteService` / `ContentPostService` / 模型 save）继续拒绝
 * 写入非 NULL 的 `published_revision_id`。发布状态机必须走本类，原因是：
 *   · `ContentPost` 的 updating 守卫会拦截任何 published 指针写入；
 *   · 指针切换必须与 `publish_status` / `disposition` / 时间字段 / `row_version`
 *     在**同一条 SQL** 里原子完成，避免“指针换了但状态没换”的中间态；
 *   · 用 Query Builder + `where row_version = ?` 做最后一道 CAS，
 *     即使调用方漏了行锁也不会静默覆盖。
 *
 * 事务边界：本类**不自开事务**，必须在调用方已锁父行的事务内使用。
 *
 * 本类只做“切换到公开版本”这一种写入；暂缓 / 恢复 / 撤稿不改变公开指针，
 * 由发布服务在行锁内用模型保存完成。
 */
class PublicationPointerSwitcher
{
    /**
     * @throws EventRevisionConflictException 父行版本已变化
     * @throws PublicationTransitionException revision 不属于该活动
     */
    public function switchEvent(
        Event $event,
        EventRevision $revision,
        int $expectedRowVersion,
        DateTimeInterface $publishedAt,
    ): Event {
        if ($event->getKey() === null || $revision->getKey() === null) {
            throw new InvalidArgumentException('切换活动公开指针前，活动与 revision 都必须已保存。');
        }

        if ((int) $revision->event_id !== (int) $event->getKey()) {
            throw PublicationTransitionException::event(sprintf(
                '活动 %d 不能把活动 %d 的 revision %d 作为公开版本。',
                (int) $event->getKey(),
                (int) $revision->event_id,
                (int) $revision->getKey(),
            ));
        }

        $publishedAtUtc = Carbon::instance($publishedAt)->utc();
        $firstPublishedAt = $this->firstPublishedAt($event, $publishedAtUtc);

        $affected = DB::table('events')
            ->where('id', (int) $event->getKey())
            ->where('row_version', $expectedRowVersion)
            ->update([
                'published_revision_id' => (int) $revision->getKey(),
                'publish_status'        => 'published',
                'disposition'           => 'normal',
                'published_at'          => $publishedAtUtc->format('Y-m-d H:i:s'),
                'first_published_at'    => $firstPublishedAt,
                // 候选一经发布即消费；排期同时失效。
                'approved_revision_id'  => null,
                'scheduled_publish_at'  => null,
                'row_version'           => $expectedRowVersion + 1,
                'updated_at'            => now(),
            ]);

        if ($affected !== 1) {
            throw new EventRevisionConflictException(
                (int) $event->getKey(),
                $expectedRowVersion,
                (int) Event::query()->whereKey($event->getKey())->value('row_version'),
            );
        }

        return Event::query()->whereKey($event->getKey())->firstOrFail();
    }

    /**
     * @throws ContentRevisionConflictException 父行版本已变化
     * @throws PublicationTransitionException revision 不属于该文章
     */
    public function switchContent(
        ContentPost $post,
        ContentRevision $revision,
        int $expectedRowVersion,
        DateTimeInterface $publishedAt,
        ?int $actorId = null,
    ): ContentPost {
        if ($post->getKey() === null || $revision->getKey() === null) {
            throw new InvalidArgumentException('切换文章公开指针前，文章与 revision 都必须已保存。');
        }

        if ((int) $revision->post_id !== (int) $post->getKey()) {
            throw PublicationTransitionException::content(sprintf(
                '文章 %d 不能把文章 %d 的 revision %d 作为公开版本。',
                (int) $post->getKey(),
                (int) $revision->post_id,
                (int) $revision->getKey(),
            ));
        }

        $publishedAtUtc = Carbon::instance($publishedAt)->utc();
        $firstPublishedAt = $this->firstPublishedAt($post, $publishedAtUtc);

        $attributes = [
            'published_revision_id' => (int) $revision->getKey(),
            'publish_status'        => 'published',
            'disposition'           => 'normal',
            'published_at'          => $publishedAtUtc->format('Y-m-d H:i:s'),
            'first_published_at'    => $firstPublishedAt,
            'approved_revision_id'  => null,
            'scheduled_publish_at'  => null,
            'row_version'           => $expectedRowVersion + 1,
            'updated_at'            => now(),
        ];

        if ($actorId !== null) {
            $attributes['updated_by'] = $actorId;
        }

        $affected = DB::table('content_posts')
            ->where('id', (int) $post->getKey())
            ->where('row_version', $expectedRowVersion)
            ->update($attributes);

        if ($affected !== 1) {
            throw new ContentRevisionConflictException(
                (int) $post->getKey(),
                $expectedRowVersion,
                (int) ContentPost::query()->whereKey($post->getKey())->value('row_version'),
            );
        }

        return ContentPost::query()->whereKey($post->getKey())->firstOrFail();
    }

    /**
     * `first_published_at` 只写一次；已存在时原样保留原始 DATETIME 字符串。
     */
    private function firstPublishedAt(Event|ContentPost $parent, Carbon $publishedAtUtc): string
    {
        $raw = $parent->getRawOriginal('first_published_at');

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        return $publishedAtUtc->format('Y-m-d H:i:s');
    }
}
