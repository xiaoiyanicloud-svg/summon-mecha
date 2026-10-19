<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Models\EventRuleVersion;

/**
 * 内容指纹（ADR-0001 §4.3 / §4.5 / G1-04）。
 *
 * 指纹用于提交审核、批准与发布前的内容一致性核验：
 *   · 算法固定为 `sha256(规范化 JSON)`，只输出 64 位小写十六进制；
 *   · 规范化 = 递归 ksort；DATETIME 一律换算成 UTC 的 `Y-m-d H:i:s`；
 *   · 负载带 `fingerprint_version`（`config('ecosystem_revisions.fingerprint_version')`），
 *     算法或字段口径变更时必须递增，否则新旧指纹不可比。
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

    public function eventRevision(EventRevision $revision): string
    {
        return $this->hash('event_revision', self::EVENT_REVISION_FIELDS, $revision);
    }

    public function ruleVersion(EventRuleVersion $rule): string
    {
        return $this->hash('event_rule_version', self::RULE_VERSION_FIELDS, $rule);
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

        $payload = $this->normalize([
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'type'                => $type,
            'content'             => $content,
        ]);

        return hash('sha256', (string) json_encode(
            $payload,
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
