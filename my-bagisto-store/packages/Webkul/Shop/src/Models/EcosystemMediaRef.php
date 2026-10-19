<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\MediaValidationException;

/**
 * 媒体引用（ADR-0001 §4.10 / G1-D06 / G1-06）。
 *
 * 把一份已上传媒体挂到某个 revision 上：
 *   · `owner_type` 只有 `event_revision` / `content_revision`，**不做多态外键**；
 *     归属对象必须存在且仍是 draft —— 该守卫在模型层兜底，正常写入走
 *     MediaRefService（负责媒体批准状态、上限、排序与指纹重算）；
 *   · `role` 为 cover / inline / attachment / rule_document；
 *   · `review_status` 为 pending / approved / rejected，公开读取要求 approved；
 *   · 引用是审核快照，只有 `created_at`，没有 `updated_at`。
 *
 * 冻结规则：父 revision 一旦离开 draft，本表任何创建 / 修改 / 删除都被拒绝。
 * 编辑必须创建 revision_no + 1 后重新挂媒体，不能改历史审核快照。
 */
class EcosystemMediaRef extends Model
{
    protected $table = 'ecosystem_media_refs';

    /** 只有 created_at；写入时 Laravel 不再维护 updated_at。 */
    public const UPDATED_AT = null;

    protected $fillable = [
        'media_id',
        'owner_type',
        'owner_id',
        'role',
        'sort_order',
        'alt_text',
        'review_status',
    ];

    protected $casts = [
        'media_id'    => 'integer',
        'owner_id'    => 'integer',
        'sort_order'  => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $ref): void {
            // Eloquent 不会在写入前套用数据库默认值；这里显式补默认，
            // 否则“省略 review_status”会被守卫误判为非法值。
            if ($ref->review_status === null || $ref->review_status === '') {
                $ref->review_status = 'pending';
            }

            if ($ref->sort_order === null) {
                $ref->sort_order = 0;
            }

            $ownerTypes = array_values((array) config('ecosystem_media.owner_types', []));

            if (! in_array((string) $ref->owner_type, $ownerTypes, true)) {
                throw new MediaValidationException(sprintf(
                    '媒体引用的 owner_type 必须是 %s 之一，收到「%s」。',
                    implode(' / ', $ownerTypes),
                    (string) $ref->owner_type,
                ));
            }

            $roles = array_values((array) config('ecosystem_media.roles', []));

            if (! in_array((string) $ref->role, $roles, true)) {
                throw new MediaValidationException(sprintf(
                    '媒体引用的 role 必须是 %s 之一，收到「%s」。',
                    implode(' / ', $roles),
                    (string) $ref->role,
                ));
            }

            $reviewStatuses = array_values((array) config('ecosystem_media.review_statuses', []));

            if (! in_array((string) $ref->review_status, $reviewStatuses, true)) {
                throw new MediaValidationException(sprintf(
                    '媒体引用的 review_status 必须是 %s 之一，收到「%s」。',
                    implode(' / ', $reviewStatuses),
                    (string) $ref->review_status,
                ));
            }

            // 枚举合法后才查父 revision；非法 owner_type 不应触发数据库查询。
            self::assertParentRevisionIsDraft($ref, '创建或修改');
        });

        static::deleting(function (self $ref): void {
            self::assertParentRevisionIsDraft($ref, '删除');
        });
    }

    /**
     * 父 revision 必须是 draft；缺失也视为非法（不做“孤儿引用”）。
     */
    private static function assertParentRevisionIsDraft(self $ref, string $action): void
    {
        $ownerType = (string) $ref->owner_type;
        $ownerId = (int) $ref->owner_id;

        $table = match ($ownerType) {
            'event_revision'   => 'event_revisions',
            'content_revision' => 'content_revisions',
            default            => null,
        };

        if ($table === null || $ownerId <= 0) {
            throw new MediaValidationException(sprintf(
                '媒体引用的归属对象非法（owner_type=%s，owner_id=%d），不能%s。',
                $ownerType,
                $ownerId,
                $action,
            ));
        }

        $reviewState = DB::table($table)->where('id', $ownerId)->value('review_state');

        if ($reviewState === null) {
            throw new MediaValidationException(sprintf(
                '媒体引用归属的 %s #%d 不存在，不能%s。',
                $ownerType,
                $ownerId,
                $action,
            ));
        }

        if ((string) $reviewState !== 'draft') {
            throw new MediaValidationException(sprintf(
                '%s #%d 当前 review_state=%s，非 draft 禁止%s媒体引用；请创建 revision_no + 1。',
                $ownerType,
                $ownerId,
                (string) $reviewState,
                $action,
            ));
        }
    }

    public function media()
    {
        return $this->belongsTo(EcosystemMedia::class, 'media_id');
    }
}
