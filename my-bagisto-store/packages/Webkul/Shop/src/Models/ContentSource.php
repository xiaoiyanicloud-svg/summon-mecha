<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;

/**
 * 资讯来源与权利依据（ADR-0001 §4.8 / G1-D06 / G1-05）。
 *
 * 来源记录属于父 revision 的审计证据：
 *   · 父 revision 是 draft 时允许新增 / 修改 / 删除（服务可在草稿期整组替换来源）；
 *   · 父 revision 一旦离开 draft，来源记录随之冻结 —— 修改来源必须创建新 revision；
 *   · 本表没有 `updated_at`：来源记录不提供“原地更新”的对外语义。
 *
 * 模型守卫只兜底状态；字段完整性（来源名、URL、核验时间、权利依据匹配）
 * 由后续来源服务校验。
 */
class ContentSource extends Model
{
    protected $table = 'content_sources';

    // ADR §4.8 只有 created_at，没有 updated_at。
    public const UPDATED_AT = null;

    protected $fillable = [
        'revision_id',
        'source_name',
        'source_url',
        'source_verified_at',
        'rights_basis',
        'rights_scope',
        'evidence_ref',
        'sort_order',
    ];

    protected $casts = [
        'source_verified_at' => UtcDateTime::class,
        'revision_id'        => 'integer',
        'sort_order'         => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $source): void {
            self::assertParentIsDraft($source->revision_id, '新增');
        });

        static::updating(function (self $source): void {
            // 原父 revision 不是 draft 时，任何修改都必须被拒绝；
            // 若本次把来源挪到另一条 revision，新父也必须是 draft。
            self::assertParentIsDraft($source->getOriginal('revision_id'), '修改');

            if ($source->isDirty('revision_id')) {
                self::assertParentIsDraft($source->revision_id, '修改');
            }
        });

        static::deleting(function (self $source): void {
            self::assertParentIsDraft($source->getOriginal('revision_id'), '删除');
        });
    }

    private static function assertParentIsDraft(mixed $revisionId, string $action): void
    {
        $revision = $revisionId === null
            ? null
            : ContentRevision::query()->find((int) $revisionId);

        if ($revision === null) {
            throw new ContentTransitionException(sprintf(
                '来源记录缺少存在的父 revision（revision_id=%s），不能%s来源。',
                $revisionId === null ? 'NULL' : (string) $revisionId,
                $action,
            ));
        }

        if ((string) $revision->review_state !== 'draft') {
            throw new ContentTransitionException(sprintf(
                '内容修订 %d 当前 review_state=%s，来源记录不可%s；请创建新 revision。',
                (int) $revision->getKey(),
                (string) $revision->review_state,
                $action,
            ));
        }
    }

    public function revision()
    {
        return $this->belongsTo(ContentRevision::class, 'revision_id');
    }
}
