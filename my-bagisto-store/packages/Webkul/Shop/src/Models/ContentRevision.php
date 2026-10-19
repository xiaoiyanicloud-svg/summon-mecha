<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;

/**
 * 资讯修订（ADR-0001 §4.7 / G1-D06 / G1-05）。
 *
 * 一个 revision 是文章公开内容的**不可变快照**。写入规则由模型守卫兜底：
 *   · `post_id` / `revision_no` / `created_by` 创建即冻结；
 *   · `review_state` 离开 draft 后，内容列不可再更新；
 *     被拒绝也不重新打开本 revision —— 编辑必须创建 `revision_no + 1`；
 *   · 永不物理删除：revision 是审核、发布与撤回的审计证据。
 *
 * 状态流转字段（review_state / submitted_* / reviewed_* / review_note / published_at）
 * 仍可更新，由 G1-08 的服务在合法转移上操作。
 */
class ContentRevision extends Model
{
    protected $table = 'content_revisions';

    protected $fillable = [
        'post_id',
        'revision_no',
        'title',
        'summary',
        'body_html',
        'author_name',
        'author_org',
        'source_type',
        'original_published_at',
        'correction_note',
        'change_summary',
        'content_fingerprint',
        'media_manifest_hash',
        'sanitizer_version',
        'review_state',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'published_at',
        'created_by',
    ];

    /**
     * non-draft 后不可更新的内容列。
     *
     * 刻意**不含**审核流转字段（review_state / submitted_* / reviewed_* /
     * review_note / published_at）：它们本来就要在审核/发布过程中变化。
     */
    public const CONTENT_FIELDS = [
        'title',
        'summary',
        'body_html',
        'author_name',
        'author_org',
        'source_type',
        'original_published_at',
        'correction_note',
        'change_summary',
        'content_fingerprint',
        'media_manifest_hash',
        'sanitizer_version',
    ];

    protected $casts = [
        // ADR G1-D06：这些 DATETIME 以 UTC 存储。
        'original_published_at' => UtcDateTime::class,
        'submitted_at'          => UtcDateTime::class,
        'reviewed_at'           => UtcDateTime::class,
        'published_at'          => UtcDateTime::class,

        'post_id'               => 'integer',
        'revision_no'           => 'integer',
        'submitted_by'          => 'integer',
        'reviewed_by'           => 'integer',
        'created_by'            => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            foreach (['post_id', 'revision_no', 'created_by'] as $field) {
                if ($revision->isDirty($field)) {
                    throw new ContentTransitionException(sprintf(
                        '内容修订 %s 的 %s 创建即冻结，不可修改。',
                        (string) $revision->getKey(),
                        $field,
                    ));
                }
            }

            if (! $revision->isDirty(self::CONTENT_FIELDS)) {
                return;
            }

            $immutableStates = (array) config(
                'ecosystem_content.immutable_review_states',
                ['in_review', 'approved', 'rejected']
            );

            // 同时检查“改之前”和“改之后”的状态：既不能改已冻结版本的内容，
            // 也不能在把 draft 提审的同一次保存里夹带内容修改。
            $originalState = (string) $revision->getOriginal('review_state');
            $currentState = (string) $revision->review_state;

            if (in_array($originalState, $immutableStates, true)
                || in_array($currentState, $immutableStates, true)) {
                throw new ContentTransitionException(sprintf(
                    '内容修订 %s 当前 review_state=%s，内容列不可更新；请创建 revision_no + 1。',
                    (string) $revision->getKey(),
                    $originalState !== '' ? $originalState : $currentState,
                ));
            }
        });

        static::deleting(function (self $revision): void {
            throw new ContentTransitionException(sprintf(
                '内容修订 %s 是审核与发布审计证据，禁止删除。',
                (string) $revision->getKey(),
            ));
        });
    }

    public function post()
    {
        return $this->belongsTo(ContentPost::class, 'post_id');
    }

    public function sources()
    {
        return $this->hasMany(ContentSource::class, 'revision_id');
    }
}
