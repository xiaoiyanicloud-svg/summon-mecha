<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 活动修订草稿写入口（ADR-0001 §4.3 / G1-04 / G1-D04）。
 *
 * 本批只做 **draft 的创建与编辑**：
 *   · 创建：在活动行锁内取 `revision_no = max + 1`，指纹由服务端计算；
 *   · 编辑：只允许内容字段，按 `expectedFingerprint` 做 CAS，重新校验并重算指纹；
 *   · 审核/发布/outbox/指针切换属于 G1-08，本类不实现。
 *
 * 为什么指纹必须服务端算：
 *   调用方传指纹等于允许它“声明内容没变”，审核与发布前的指纹核验就失去意义。
 *   同理，`content_fingerprint`、审核字段、`revision_no`、`created_by` 一律拒绝透传。
 *
 * G1-07 起 `detail_html` 在服务端净化：
 *   · 图片只允许引用本 revision 已审核的媒体（`data-media-id`）；
 *   · `sanitizer_version` 由服务端写成真实净化版本，调用方传值一律拒绝；
 *   · `media_manifest_hash` 由 MediaRefService 维护，编辑时保留既有值，不清空。
 *
 * 来源快照必须与活动当前三字段一致：
 *   revision 是公开内容的快照，但“来源是谁”这一事实来自 `events`（G1-D01/D05）。
 *   允许快照写另一套来源，会让审核通过的内容与活动归属脱节。
 */
class EventRevisionService
{
    /**
     * 调用方可写的修订内容字段（与 §4.3 内容列一致，排除指纹与审核字段）。
     *
     * `media_manifest_hash` / `sanitizer_version` 保留在表内，是为了让误传时
     * 能给出明确拒绝，而不是被静默丢弃；最终值仍由服务端钉死。
     *
     * @var array<int, string>
     */
    public const CONTENT_FIELDS = [
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
     * @var array<int, string>
     */
    private const REQUIRED_TEXT_FIELDS = [
        'name',
        'timezone',
        'source_type',
        'platform_role',
        'organizer_name_snapshot',
        'source_name',
        'change_summary',
    ];

    /**
     * @var array<int, string>
     */
    private const TIME_FIELDS = ['starts_at', 'ends_at', 'source_verified_at'];

    /**
     * @var array<int, string>
     */
    private const OPTIONAL_TEXT_FIELDS = [
        'summary',
        'detail_html',
        'city',
        'location_text',
        'participation_mode',
        'authorization_basis',
        'authorization_evidence_ref',
        'rule_summary',
    ];

    public function __construct(
        private readonly EventSourceValidator $sourceValidator,
        private readonly ContentFingerprint $fingerprints,
        private readonly MediaRefService $mediaRefs,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  只允许 CONTENT_FIELDS 中的键
     * @param  array<int, int|string>  $allowedMediaIds  detail_html 中允许引用的媒体 ID
     *
     * @throws EventSourceValidationException 活动缺来源，或修订来源与活动不一致
     * @throws EventTimeValidationException 时区/起止时间非法
     * @throws InvalidArgumentException 缺必填字段、越权字段、非法枚举、正文超长
     */
    public function createDraft(Event $event, int $adminId, array $data, array $allowedMediaIds = []): EventRevision
    {
        $this->assertOnlyContentFields($data, '创建活动修订');
        $this->assertAdminExists($adminId);

        if ($event->getKey() === null) {
            throw new InvalidArgumentException('创建活动修订前必须先保存活动。');
        }

        return DB::transaction(function () use ($event, $adminId, $data, $allowedMediaIds): EventRevision {
            $lockedEvent = Event::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = $this->normalizeContentAttributes($data, $allowedMediaIds);

            $revision = new EventRevision;
            $revision->fill($attributes);

            // 服务端字段一律覆盖调用方输入（即使调用方没传，也显式钉死）。
            $revision->event_id = (int) $lockedEvent->getKey();
            $revision->revision_no = $this->nextRevisionNo($lockedEvent);
            $revision->created_by = $adminId;
            $revision->review_state = 'draft';
            $revision->media_manifest_hash = null;
            $revision->sanitizer_version = $this->sanitizer->version();

            $this->assertContent($lockedEvent, $revision, true);

            $revision->content_fingerprint = $this->fingerprints->eventRevision($revision);
            $revision->save();

            return $revision;
        });
    }

    /**
     * 只允许改内容字段；必须仍是 draft；指纹不符即冲突。
     *
     * @param  array<string, mixed>  $data  只允许 CONTENT_FIELDS 中的键
     * @param  array<int, int|string>|null  $allowedMediaIds
     *         NULL 时按该 revision 当前已审核的媒体引用自动取集合
     *
     * @throws RevisionImmutableException 修订已离开 draft
     * @throws RevisionConflictException 调用方基于的指纹已过期
     */
    public function updateDraft(
        EventRevision $revision,
        string $expectedFingerprint,
        array $data,
        ?array $allowedMediaIds = null,
    ): EventRevision {
        $this->assertOnlyContentFields($data, '更新活动修订');

        if ($revision->getKey() === null) {
            throw new InvalidArgumentException('更新活动修订前 revision 必须已保存。');
        }

        return DB::transaction(function () use ($revision, $expectedFingerprint, $data, $allowedMediaIds): EventRevision {
            $locked = EventRevision::query()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->review_state !== 'draft') {
                throw new RevisionImmutableException(sprintf(
                    '活动修订 %s 当前 review_state=%s，只有 draft 可以编辑；请创建 revision_no + 1。',
                    (string) $locked->getKey(),
                    (string) $locked->review_state,
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

            $event = Event::query()->findOrFail($locked->event_id);

            $mediaIds = $allowedMediaIds
                ?? $this->mediaRefs->approvedMediaIdsForRevision('event_revision', (int) $locked->getKey());

            $attributes = $this->normalizeContentAttributes($data, $mediaIds);

            // 只有本次显式触碰来源/主办方字段时才要求快照等于主体当前名称；
            // 其他内容编辑保留历史快照，不能因为主体后来改名就阻塞草稿。
            $assertOrganizerSnapshot = array_intersect(
                array_keys($data),
                ['source_type', 'platform_role', 'organizer_id', 'organizer_name_snapshot'],
            ) !== [];

            // 先在副本上校验“合并后的完整内容”，避免半套更新落库；
            // clone 不触发模型 updating 守卫，守卫仍由下面的 save() 兜底。
            $candidate = clone $locked;
            $candidate->fill($attributes);
            $candidate->sanitizer_version = $this->sanitizer->version();

            $this->assertContent($event, $candidate, $assertOrganizerSnapshot);

            $locked->fill($attributes);
            // 媒体集合指纹由 MediaRefService 维护；编辑正文不得清空。
            $locked->sanitizer_version = $this->sanitizer->version();
            $locked->content_fingerprint = $this->fingerprints->eventRevision($locked);
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
                '%s时不允许传入字段：%s；指纹、版本号、审核与创建人字段只能由服务端维护。',
                $action,
                implode(', ', $forbidden),
            ));
        }
    }

    private function assertAdminExists(int $adminId): void
    {
        if ($adminId <= 0 || ! DB::table('admins')->where('id', $adminId)->exists()) {
            throw new InvalidArgumentException(sprintf('创建活动修订需要存在的后台账号，admin_id=%d 无效。', $adminId));
        }
    }

    private function nextRevisionNo(Event $event): int
    {
        $max = EventRevision::query()
            ->where('event_id', $event->getKey())
            ->max('revision_no');

        return ($max === null ? 0 : (int) $max) + 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int|string>  $allowedMediaIds
     * @return array<string, mixed>
     */
    private function normalizeContentAttributes(array $data, array $allowedMediaIds): array
    {
        $attributes = [];

        foreach ($data as $field => $value) {
            if (in_array($field, self::TIME_FIELDS, true)) {
                $attributes[$field] = $this->normalizeTime($value, $field);

                continue;
            }

            if ($field === 'organizer_id') {
                $attributes[$field] = $this->normalizeNullableInt($value, $field);

                continue;
            }

            if (in_array($field, ['source_url', 'external_registration_url'], true)) {
                $attributes[$field] = $this->normalizeOptionalUrl($value, $field);

                continue;
            }

            if ($field === 'detail_html') {
                $attributes[$field] = $this->sanitizer->sanitize(
                    $this->normalizeOptionalText($value, $field),
                    $allowedMediaIds,
                );

                continue;
            }

            if ($field === 'media_manifest_hash') {
                if ($value !== null) {
                    throw new InvalidArgumentException(
                        'media_manifest_hash 由 MediaRefService 维护，调用方不得写入。'
                    );
                }

                // NULL 表示“不动”；编辑时保留库内既有值，创建时显式置 NULL。
                continue;
            }

            if ($field === 'sanitizer_version') {
                if ($value !== null) {
                    throw new InvalidArgumentException(
                        'sanitizer_version 由服务端按实际净化结果写入，调用方不得传入。'
                    );
                }

                continue;
            }

            if (in_array($field, self::OPTIONAL_TEXT_FIELDS, true)) {
                $attributes[$field] = $this->normalizeOptionalText($value, $field);

                continue;
            }

            $attributes[$field] = $value;
        }

        return $attributes;
    }

    private function normalizeTime(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::createFromInterface($value)->utc();
        }

        if (! is_string($value)) {
            throw new EventTimeValidationException(sprintf('字段 %s 只接受时间字符串或日期对象。', $field));
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            throw new EventTimeValidationException(sprintf('字段 %s 的时间「%s」无法解析。', $field, $value));
        }
    }

    private function normalizeNullableInt(mixed $value, string $field): ?int
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

        throw new InvalidArgumentException(sprintf('字段 %s 必须是整数或 NULL。', $field));
    }

    private function normalizeOptionalText(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('字段 %s 必须是字符串或 NULL。', $field));
        }

        return trim($value) === '' ? null : $value;
    }

    private function normalizeOptionalUrl(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new EventSourceValidationException(
                ApiErrorCode::EVENT_SOURCE_REQUIRED,
                sprintf('字段 %s 必须是 URL 字符串或 NULL。', $field),
            );
        }

        $this->sourceValidator->assertSourceUrl($value);

        return $value;
    }

    /**
     * @param  bool  $assertOrganizerSnapshot  是否要求快照等于主体当前名称
     *
     * 创建时必须校验（否则新版本一开始就把主办权写成别人）；
     * 编辑时仅在本次显式写入来源/主办方相关字段时校验 ——
     * 历史快照本就应保留“当时的名称”，主体改名不应阻塞无关内容编辑。
     */
    private function assertContent(Event $event, EventRevision $revision, bool $assertOrganizerSnapshot): void
    {
        foreach (self::REQUIRED_TEXT_FIELDS as $field) {
            $value = $revision->getAttribute($field);

            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('活动修订缺少必填字段 %s。', $field));
            }
        }

        $organizerId = $revision->organizer_id === null ? null : (int) $revision->organizer_id;

        $this->assertEventSourceMatches($event, $revision->source_type, $revision->platform_role, $organizerId);

        // 复用 G1-03 的枚举 / 组合 / 主体存在性 / 主体状态校验。
        $this->sourceValidator->assertDraftValues(
            $revision->source_type,
            $revision->platform_role,
            $organizerId,
        );

        if ($organizerId !== null) {
            $organizer = EcosystemOrganizer::query()->find($organizerId);

            if ($organizer === null) {
                throw new EventSourceValidationException(
                    ApiErrorCode::EVENT_SOURCE_REQUIRED,
                    sprintf('主办方 %d 不存在。', $organizerId),
                );
            }

            // 快照一旦与当前主体名称不一致，历史版本就会把“谁主办”写成另一个主体。
            // 但编辑期的比对只在本次显式写入来源/主办方字段时进行（见方法注释）。
            if ($assertOrganizerSnapshot && (string) $revision->organizer_name_snapshot !== (string) $organizer->name) {
                throw new EventSourceValidationException(
                    ApiErrorCode::EVENT_SOURCE_REQUIRED,
                    sprintf(
                        'organizer_name_snapshot「%s」与主办方 %d 当前名称「%s」不一致。',
                        (string) $revision->organizer_name_snapshot,
                        $organizerId,
                        (string) $organizer->name,
                    ),
                );
            }
        }

        $this->assertTimezone($revision->timezone);
        $this->assertTimeRange($revision);
        $this->assertParticipationMode($revision->participation_mode);
        $this->assertAuthorizationBasis($revision->authorization_basis);
        $this->assertUrls($revision);
    }

    private function assertEventSourceMatches(
        Event $event,
        ?string $sourceType,
        ?string $platformRole,
        ?int $organizerId,
    ): void {
        if ($event->source_type === null || $event->platform_role === null || $event->organizer_id === null) {
            throw new EventSourceValidationException(
                ApiErrorCode::EVENT_SOURCE_REQUIRED,
                '活动尚未完整配置 source_type / platform_role / organizer_id，不能创建修订快照。',
            );
        }

        $eventOrganizerId = (int) $event->organizer_id;

        if (
            (string) $event->source_type !== (string) $sourceType
            || (string) $event->platform_role !== (string) $platformRole
            || $organizerId === null
            || $organizerId !== $eventOrganizerId
        ) {
            throw new EventSourceValidationException(
                ApiErrorCode::EVENT_SOURCE_REQUIRED,
                sprintf(
                    '修订来源必须与活动当前来源一致：活动为 %s / %s / #%d。',
                    (string) $event->source_type,
                    (string) $event->platform_role,
                    $eventOrganizerId,
                ),
            );
        }
    }

    private function assertTimezone(?string $timezone): void
    {
        if ($timezone === null || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new EventTimeValidationException(sprintf(
                'timezone「%s」不是合法的 IANA 时区名称（不得使用 +08:00 这类偏移）。',
                (string) $timezone,
            ));
        }
    }

    private function assertTimeRange(EventRevision $revision): void
    {
        $startsAt = $revision->starts_at;
        $endsAt = $revision->ends_at;

        if ($startsAt === null && $endsAt === null) {
            return;
        }

        if ($startsAt === null || $endsAt === null) {
            throw new EventTimeValidationException('starts_at 与 ends_at 必须同时为空（待定）或同时填写。');
        }

        if ($endsAt->lt($startsAt)) {
            throw new EventTimeValidationException('ends_at 不得早于 starts_at。');
        }
    }

    private function assertParticipationMode(?string $mode): void
    {
        if ($mode === null) {
            return;
        }

        $allowed = array_values((array) config('ecosystem_revisions.participation_modes', ['offline', 'online', 'hybrid']));

        if (! in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('未知的 participation_mode「%s」。', $mode));
        }
    }

    private function assertAuthorizationBasis(?string $basis): void
    {
        if ($basis === null) {
            return;
        }

        $allowed = array_values((array) config('ecosystem_revisions.authorization_bases', []));

        if (! in_array($basis, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('未知的 authorization_basis「%s」。', $basis));
        }
    }

    private function assertUrls(EventRevision $revision): void
    {
        if ($revision->source_url !== null) {
            $this->sourceValidator->assertSourceUrl($revision->source_url);
        }

        if ($revision->external_registration_url !== null) {
            $this->sourceValidator->assertSourceUrl($revision->external_registration_url);
        }
    }
}
