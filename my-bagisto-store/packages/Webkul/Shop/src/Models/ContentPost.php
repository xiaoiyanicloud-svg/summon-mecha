<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;

/**
 * 资讯文章（ADR-0001 §4.6 / G1-D06 / G1-05）。
 *
 * 本表只保存发布轴 / 处置轴与双 revision 指针；标题、正文、作者、来源
 * 属于 `content_revisions`。模型守卫兜底以下不可变边界：
 *   · slug 在 `first_published_at` 非空后不可改（必须换 slug 等于换公开地址）；
 *   · `published_revision_id` 在 G1-05 **任何非 NULL 写入一律拒绝** ——
 *     公开版本切换属于 G1-08 的发布状态机，不能借普通编辑入口绕过审核；
 *   · `working_revision_id` 非 NULL 时必须指向**同一文章**且 `review_state=draft`
 *     的 revision；
 *   · 文章是发布/撤稿审计对象，禁止物理删除（撤稿不删记录）。
 *
 * 服务层（`ContentPostService`）是唯一业务写入口；模型守卫用于兜底绕过服务的写操作。
 */
class ContentPost extends Model
{
    protected $table = 'content_posts';

    protected $fillable = [
        'type',
        'slug',
        'category',
        'publish_status',
        'disposition',
        'published_revision_id',
        'working_revision_id',
        // G1-08A：当前已批准候选版本（ADR 附录 B A-01）。
        // ContentPostService 不透传；只由审核服务经受控校验写入。
        'approved_revision_id',
        // G1-08A 只建列；排期写入属于 G1-08B。
        'scheduled_publish_at',
        'first_published_at',
        'published_at',
        'row_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        // ADR G1-D06：这些 DATETIME 以 UTC 存储，不能用默认 datetime cast 读取。
        'first_published_at'    => UtcDateTime::class,
        'published_at'          => UtcDateTime::class,

        'published_revision_id' => 'integer',
        'working_revision_id'   => 'integer',
        'approved_revision_id'  => 'integer',
        'scheduled_publish_at'  => UtcDateTime::class,
        'row_version'           => 'integer',
        'created_by'            => 'integer',
        'updated_by'            => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $post): void {
            if ($post->published_revision_id !== null) {
                throw new ContentTransitionException(
                    'G1-05 不允许写入 published_revision_id；公开版本切换属于 G1-08 发布状态机。'
                );
            }

            if ($post->working_revision_id !== null) {
                // 新建文章还没有主键，无法证明 revision 归属同一文章。
                throw new ContentTransitionException(
                    '新建文章不能直接挂 working_revision_id；请先保存文章再挂工作版本。'
                );
            }
        });

        static::updating(function (self $post): void {
            // 同时检查“改之前”和“改之后”的首次发布时间：既不能改已发布文章的 slug，
            // 也不能在同一次保存里一边写下首次发布时间、一边夹带 slug 变更。
            if ($post->isDirty('slug')
                && ($post->getOriginal('first_published_at') !== null || $post->first_published_at !== null)) {
                throw new ContentTransitionException(sprintf(
                    '文章 %s 已首次发布，slug 不可修改；如需更换公开地址必须走独立迁移策略。',
                    (string) $post->getKey(),
                ));
            }

            if ($post->isDirty('published_revision_id') && $post->published_revision_id !== null) {
                throw new ContentTransitionException(sprintf(
                    '文章 %s 不允许在 G1-05 写入 published_revision_id；公开版本切换属于 G1-08 发布状态机。',
                    (string) $post->getKey(),
                ));
            }

            if ($post->isDirty('working_revision_id') && $post->working_revision_id !== null) {
                self::assertWorkingRevision($post);
            }
        });

        static::deleting(function (self $post): void {
            throw new ContentTransitionException(sprintf(
                '文章 %s 是发布与撤稿的审计对象，禁止删除；撤稿应更新 disposition。',
                (string) $post->getKey(),
            ));
        });
    }

    private static function assertWorkingRevision(self $post): void
    {
        if ($post->getKey() === null) {
            throw new ContentTransitionException('新建文章不能挂 working_revision_id；请先保存文章。');
        }

        $revision = ContentRevision::query()->find($post->working_revision_id);

        if ($revision === null) {
            throw new ContentTransitionException(sprintf(
                '文章 %d 的 working_revision_id=%d 不存在。',
                (int) $post->getKey(),
                (int) $post->working_revision_id,
            ));
        }

        if ((int) $revision->post_id !== (int) $post->getKey()) {
            throw new ContentTransitionException(sprintf(
                '文章 %d 不能挂文章 %d 的 revision %d 作为工作版本。',
                (int) $post->getKey(),
                (int) $revision->post_id,
                (int) $post->working_revision_id,
            ));
        }

        if ((string) $revision->review_state !== 'draft') {
            throw new ContentTransitionException(sprintf(
                '文章 %d 的 revision %d 当前 review_state=%s，只有 draft 才能作为工作版本。',
                (int) $post->getKey(),
                (int) $post->working_revision_id,
                (string) $revision->review_state,
            ));
        }
    }

    public function revisions()
    {
        return $this->hasMany(ContentRevision::class, 'post_id');
    }

    public function publishedRevision()
    {
        return $this->belongsTo(ContentRevision::class, 'published_revision_id');
    }

    public function workingRevision()
    {
        return $this->belongsTo(ContentRevision::class, 'working_revision_id');
    }

    /**
     * 当前已批准候选版本（G1-08A）。已发布对象的新版本在此等待发布，
     * 公开读取仍只读 `publishedRevision`。
     */
    public function approvedRevision()
    {
        return $this->belongsTo(ContentRevision::class, 'approved_revision_id');
    }
}
