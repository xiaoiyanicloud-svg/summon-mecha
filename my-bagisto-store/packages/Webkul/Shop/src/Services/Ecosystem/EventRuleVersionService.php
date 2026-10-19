<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRuleVersion;

/**
 * 活动规则版本草稿写入口（ADR-0001 §4.5 / G1-04 / G1-D04）。
 *
 * 边界：
 *   · 只做 draft 的创建与编辑；批准/废止/替代属于后续审核流程；
 *   · `version_no` 在活动行锁内 `max + 1`，同活动内不复用；
 *   · `body_html` 与 `external_url` 至少一项 —— 只有外链许可时不得复制全文；
 *   · `file_media_id` 本批必须为 NULL（`ecosystem_media` 归 G1-06）；
 *   · 指纹由服务端计算，调用方传入 `content_fingerprint` / `status` / `version_no`
 *     一律拒绝，否则“声明内容没变”会绕过审核。
 *
 * `authority_language` 按 BCP 47 形态校验（如 `zh-CN`、`en`），
 * 列宽 10，因此同时限制长度，避免数据库截断后语义漂移。
 */
class EventRuleVersionService
{
    /**
     * 调用方可写的规则内容字段（与 §4.5 内容列一致，排除指纹与状态字段）。
     *
     * @var array<int, string>
     */
    public const CONTENT_FIELDS = [
        'title',
        'authority_language',
        'body_html',
        'external_url',
        'file_media_id',
        'effective_from',
        'change_summary',
    ];

    /**
     * @var array<int, string>
     */
    private const REQUIRED_TEXT_FIELDS = [
        'title',
        'authority_language',
        'change_summary',
    ];

    /** BCP 47 语言标签：主语言 2–8 位字母，后续子标签 1–8 位字母数字。 */
    private const LANGUAGE_TAG_PATTERN = '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/';

    public function __construct(
        private readonly EventSourceValidator $sourceValidator,
        private readonly ContentFingerprint $fingerprints,
    ) {}

    /**
     * @param  array<string, mixed>  $data  只允许 CONTENT_FIELDS 中的键
     */
    public function createDraft(Event $event, int $adminId, array $data): EventRuleVersion
    {
        $this->assertOnlyContentFields($data, '创建规则版本');
        $this->assertAdminExists($adminId);

        if ($event->getKey() === null) {
            throw new InvalidArgumentException('创建规则版本前必须先保存活动。');
        }

        return DB::transaction(function () use ($event, $adminId, $data): EventRuleVersion {
            $lockedEvent = Event::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = $this->normalizeContentAttributes($data);

            $this->assertFileMediaNull($attributes);

            $rule = new EventRuleVersion;
            $rule->fill($attributes);

            $rule->event_id = (int) $lockedEvent->getKey();
            $rule->version_no = $this->nextVersionNo($lockedEvent);
            $rule->created_by = $adminId;
            $rule->status = 'draft';
            $rule->file_media_id = null;

            $this->assertContent($rule);

            $rule->content_fingerprint = $this->fingerprints->ruleVersion($rule);
            $rule->save();

            return $rule;
        });
    }

    /**
     * 只允许改内容字段；必须仍是 draft；指纹不符即冲突。
     *
     * @param  array<string, mixed>  $data  只允许 CONTENT_FIELDS 中的键
     */
    public function updateDraft(EventRuleVersion $rule, string $expectedFingerprint, array $data): EventRuleVersion
    {
        $this->assertOnlyContentFields($data, '更新规则版本');

        if ($rule->getKey() === null) {
            throw new InvalidArgumentException('更新规则版本前 version 必须已保存。');
        }

        return DB::transaction(function () use ($rule, $expectedFingerprint, $data): EventRuleVersion {
            $locked = EventRuleVersion::query()
                ->whereKey($rule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->status !== 'draft') {
                throw new RevisionImmutableException(sprintf(
                    '规则版本 %s 当前 status=%s，只有 draft 可以编辑；请创建 version_no + 1。',
                    (string) $locked->getKey(),
                    (string) $locked->status,
                ));
            }

            $actualFingerprint = (string) $locked->content_fingerprint;

            if (! hash_equals($actualFingerprint, $expectedFingerprint)) {
                throw new RevisionConflictException(
                    $expectedFingerprint,
                    $actualFingerprint,
                    (int) $locked->getKey(),
                );
            }

            $attributes = $this->normalizeContentAttributes($data);

            $this->assertFileMediaNull($attributes);

            $candidate = clone $locked;
            $candidate->fill($attributes);
            $candidate->file_media_id = null;

            $this->assertContent($candidate);

            $locked->fill($attributes);
            $locked->file_media_id = null;
            $locked->content_fingerprint = $this->fingerprints->ruleVersion($locked);
            $locked->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertOnlyContentFields(array $data, string $action): void
    {
        $forbidden = array_values(array_diff(array_keys($data), self::CONTENT_FIELDS));

        if ($forbidden !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s时不允许传入字段：%s；指纹、版本号、状态与创建人字段只能由服务端维护。',
                $action,
                implode(', ', $forbidden),
            ));
        }
    }

    private function assertAdminExists(int $adminId): void
    {
        if ($adminId <= 0 || ! DB::table('admins')->where('id', $adminId)->exists()) {
            throw new InvalidArgumentException(sprintf('创建规则版本需要存在的后台账号，admin_id=%d 无效。', $adminId));
        }
    }

    private function nextVersionNo(Event $event): int
    {
        $max = EventRuleVersion::query()
            ->where('event_id', $event->getKey())
            ->max('version_no');

        return ($max === null ? 0 : (int) $max) + 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeContentAttributes(array $data): array
    {
        $attributes = [];

        foreach ($data as $field => $value) {
            if ($field === 'effective_from') {
                $attributes[$field] = $this->normalizeTime($value);

                continue;
            }

            if ($field === 'file_media_id') {
                $attributes[$field] = $this->normalizeNullableInt($value);

                continue;
            }

            if ($field === 'external_url') {
                $attributes[$field] = $this->normalizeOptionalUrl($value);

                continue;
            }

            if ($field === 'body_html') {
                $attributes[$field] = $this->normalizeOptionalText($value);

                continue;
            }

            $attributes[$field] = $value;
        }

        return $attributes;
    }

    private function normalizeTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::createFromInterface($value)->utc();
        }

        if (! is_string($value)) {
            throw new EventTimeValidationException('effective_from 只接受时间字符串或日期对象。');
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            throw new EventTimeValidationException(sprintf('effective_from 的时间「%s」无法解析。', $value));
        }
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('file_media_id 必须是整数或 NULL。');
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('body_html 必须是字符串或 NULL。');
        }

        return trim($value) === '' ? null : $value;
    }

    private function normalizeOptionalUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new EventSourceValidationException(
                ApiErrorCode::EVENT_SOURCE_REQUIRED,
                'external_url 必须是 URL 字符串或 NULL。',
            );
        }

        $this->sourceValidator->assertSourceUrl($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertFileMediaNull(array $attributes): void
    {
        if (array_key_exists('file_media_id', $attributes) && $attributes['file_media_id'] !== null) {
            throw new InvalidArgumentException('本批不写规则文件，file_media_id 必须为 NULL（G1-06）。');
        }
    }

    private function assertContent(EventRuleVersion $rule): void
    {
        foreach (self::REQUIRED_TEXT_FIELDS as $field) {
            $value = $rule->getAttribute($field);

            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('规则版本缺少必填字段 %s。', $field));
            }
        }

        $hasBody = is_string($rule->body_html) && trim($rule->body_html) !== '';
        $hasExternalUrl = is_string($rule->external_url) && trim($rule->external_url) !== '';

        if (! $hasBody && ! $hasExternalUrl) {
            throw new InvalidArgumentException('规则版本必须提供 body_html 或 external_url 至少一项。');
        }

        if ($rule->external_url !== null) {
            $this->sourceValidator->assertSourceUrl($rule->external_url);
        }

        $this->assertLanguageTag($rule->authority_language);

        if ($rule->file_media_id !== null) {
            throw new InvalidArgumentException('本批不写规则文件，file_media_id 必须为 NULL（G1-06）。');
        }
    }

    private function assertLanguageTag(?string $language): void
    {
        // 必填与非空已由 REQUIRED_TEXT_FIELDS 检查。
        if (mb_strlen((string) $language) > 10 || preg_match(self::LANGUAGE_TAG_PATTERN, (string) $language) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'authority_language「%s」不是合法的 BCP 47 语言标签（如 zh-CN、en）。',
                (string) $language,
            ));
        }
    }
}
