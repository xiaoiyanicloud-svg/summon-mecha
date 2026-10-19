<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Models\EventRuleVersion;

/**
 * 内容指纹（ADR-0001 §4.3 / §4.5 / §4.7 / §4.8 / G1-04 / G1-05）。
 *
 * 指纹用于提交审核、批准与发布前的内容一致性核验：
 *   · 算法固定为 `sha256(规范化 JSON)`，只输出 64 位小写十六进制；
 *   · 规范化 = 递归 ksort；DATETIME 一律换算成 UTC 的 `Y-m-d H:i:s`；
 *   · 负载带 `fingerprint_version`（`config('ecosystem_revisions.fingerprint_version')`），
 *     算法或字段口径变更时必须递增，否则新旧指纹不可比。
 *
 * 资讯修订（`content_revision`）的指纹额外包含**有序来源**：正文与来源是
 * 一个审核整体，只换来源不改正文同样必须产生新指纹。
 *
 * **参与指纹的字段是白名单**：主键、版本号、审核字段、审计时间与
 * `content_fingerprint` 自身都不参与，否则“重新计算指纹”会改变输入。
 *
 * 时间字段在模型里由 `Casts\UtcDateTime` 读取，正常已是 UTC Carbon；
 * 这里仍再做一次显式换算，保证日期时间无论来自哪种表示都落到同一字节。
 */
class ContentFingerprint
{
    /**
     * 活动修订参与指纹的字段（§4.3，排除 content_fingerprint 自身）。
     *
     * @var array<int, string>
     */
    public const EVENT_REVISION_FIELDS = [
        'name',
        'summary',
        'detail_html',
        'city',
        'location_text',
        'participation_mode',
        'timezone',
        'starts_at',
        'ends_at',
        'source_type',
        'platform_role',
        'organizer_id',
        'organizer_name_snapshot',
        'source_name',
        'source_url',
        'source_verified_at',
        'authorization_basis',
        'authorization_evidence_ref',
        'external_registration_url',
        'rule_summary',
        'media_manifest_hash',
        'sanitizer_version',
        'change_summary',
    ];

    /**
     * 规则版本参与指纹的字段（§4.5）。
     *
     * @var array<int, string>
     */
    public const RULE_VERSION_FIELDS = [
        'title',
        'authority_language',
        'body_html',
        'external_url',
        'file_media_id',
        'effective_from',
        'change_summary',
    ];

    /**
     * 内容修订参与指纹的字段（§4.7，排除指纹、审核轴与审计字段）。
     *
     * @var array<int, string>
     */
    public const CONTENT_REVISION_FIELDS = [
        'title',
        'summary',
        'body_html',
        'author_name',
        'author_org',
        'source_type',
        'original_published_at',
        'correction_note',
        'change_summary',
        'media_manifest_hash',
        'sanitizer_version',
    ];

    /**
     * 每条来源参与指纹的字段（§4.8）。
     *
     * 刻意不含 `id` / `revision_id` / `created_at`：它们是数据库审计信息，
     * 同一组来源整组替换后主键与创建时间必然变化，若参与指纹会让
     * “内容没改”也产生新指纹。
     *
     * @var array<int, string>
     */
    public const CONTENT_SOURCE_FIELDS = [
        'source_name',
        'source_url',
        'source_verified_at',
        'rights_basis',
        'rights_scope',
        'evidence_ref',
        'sort_order',
    ];

    public function eventRevision(EventRevision $revision): string
    {
        return $this->hash('event_revision', self::EVENT_REVISION_FIELDS, $revision);
    }

    public function ruleVersion(EventRuleVersion $rule): string
    {
        return $this->hash('event_rule_version', self::RULE_VERSION_FIELDS, $rule);
    }

    /**
     * 内容修订指纹（§4.7 / §4.8）。
     *
     * 正文与来源是一个整体：只改来源、不改正文也必须产生新指纹，
     * 否则审核通过后替换来源可以绕过指纹核验。来源按 `sort_order`
     * 升序参与计算，确保调用方传入顺序与库内读取顺序得到同一字节。
     *
     * @param  array<int, array<string, mixed>|Model>  $sources
     */
    public function contentRevision(ContentRevision $revision, array $sources): string
    {
        $content = [];

        foreach (self::CONTENT_REVISION_FIELDS as $field) {
            $content[$field] = $revision->getAttribute($field);
        }

        $snapshot = [];

        foreach ($sources as $source) {
            $item = [];

            foreach (self::CONTENT_SOURCE_FIELDS as $field) {
                $item[$field] = $source instanceof Model
                    ? $source->getAttribute($field)
                    : (is_array($source) ? ($source[$field] ?? null) : null);
            }

            // 排序键统一成整数：不同驱动（模型 cast / 原始行）可能给 string，
            // JSON 里 "0" 与 0 是不同字节，不能因此让同一份来源产生两个指纹。
            $item['sort_order'] = (int) ($item['sort_order'] ?? 0);

            $snapshot[] = $item;
        }

        usort(
            $snapshot,
            fn (array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0))
        );

        return $this->hashPayload([
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'type'                => 'content_revision',
            'content'             => $content,
            'sources'             => $snapshot,
        ]);
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function hash(string $type, array $fields, Model $model): string
    {
        $content = [];

        foreach ($fields as $field) {
            $content[$field] = $model->getAttribute($field);
        }

        return $this->hashPayload([
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'type'                => $type,
            'content'             => $content,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            $this->normalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * 递归 ksort + 日期时间统一 UTC。
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::createFromInterface($value)->utc()->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            ksort($value);

            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
