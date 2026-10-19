<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * 活动规则版本（ADR-0001 §4.5 / G1-04）。
 *
 * 与活动 revision 一样是审计证据：
 *   · `event_id` / `version_no` / `created_by` 创建即冻结；
 *   · `status` 离开 draft 后，规则内容（标题、语言、正文、外链、文件、指纹、
 *     生效时间、变更摘要）不可更新；新规则只能发 `version_no + 1`；
 *   · 永不物理删除。
 *
 * `file_media_id` 本批没有外键（ecosystem_media 归 G1-06），模型也不校验其存在性；
 * 校验留给 G1-06 的媒体服务。
 */
class EventRuleVersion extends Model
{
    protected $table = 'event_rule_versions';

    protected $fillable = [
        'event_id',
        'version_no',
        'title',
        'authority_language',
        'body_html',
        'external_url',
        'file_media_id',
        'content_fingerprint',
        'change_summary',
        'effective_from',
        'status',
        'reviewed_by',
        'reviewed_at',
        'created_by',
    ];

    /**
     * non-draft 后不可更新的规则内容列。
     *
     * 刻意**不含** status / reviewed_by / reviewed_at：它们是审核流转本身。
     */
    public const CONTENT_FIELDS = [
        'title',
        'authority_language',
        'body_html',
        'external_url',
        'file_media_id',
        'content_fingerprint',
        'change_summary',
        'effective_from',
    ];

    protected $casts = [
        // ADR G1-D04：DATETIME 以 UTC 存储。
        'effective_from' => UtcDateTime::class,
        'reviewed_at'    => UtcDateTime::class,

        'event_id'       => 'integer',
        'version_no'     => 'integer',
        'file_media_id'  => 'integer',
        'reviewed_by'    => 'integer',
        'created_by'     => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            foreach (['event_id', 'version_no', 'created_by'] as $field) {
                if ($rule->isDirty($field)) {
                    throw new RevisionImmutableException(sprintf(
                        '规则版本 %s 的 %s 创建即冻结，不可修改。',
                        (string) $rule->getKey(),
                        $field,
                    ));
                }
            }

            if (! $rule->isDirty(self::CONTENT_FIELDS)) {
                return;
            }

            $immutableStatuses = (array) config(
                'ecosystem_revisions.immutable_rule_statuses',
                ['approved', 'superseded', 'withdrawn']
            );

            $originalStatus = (string) $rule->getOriginal('status');
            $currentStatus = (string) $rule->status;

            if (in_array($originalStatus, $immutableStatuses, true)
                || in_array($currentStatus, $immutableStatuses, true)) {
                throw new RevisionImmutableException(sprintf(
                    '规则版本 %s 当前 status=%s，内容列不可更新；请创建 version_no + 1。',
                    (string) $rule->getKey(),
                    $originalStatus !== '' ? $originalStatus : $currentStatus,
                ));
            }
        });

        static::deleting(function (self $rule): void {
            throw new RevisionImmutableException(sprintf(
                '规则版本 %s 是审计证据，禁止删除。',
                (string) $rule->getKey(),
            ));
        });
    }

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
