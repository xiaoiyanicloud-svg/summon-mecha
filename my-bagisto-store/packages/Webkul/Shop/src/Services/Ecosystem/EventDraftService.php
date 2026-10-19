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

/**
 * 活动 G1 草稿创建入口（G1 Admin 新建活动）。
 *
 * 为什么需要独立服务，而不是让 Admin 控制器 `Event::create()`：
 *   · `events` 同时承载旧赛事展示列与 G1 可信发布列，直接 fill 会让调用方
 *     有机会写入 `publish_status` / `disposition` / 指针 / 来源快照等
 *     只能由状态机维护的字段；
 *   · 新活动必须是**草稿壳**：没有 revision，因此无法直接发布；所有公开结论
 *     只能由后续 revision + 审核 + 发布状态机产生；
 *   · 审计必须与创建同事务（`logOrFail`），否则“活动存在但没有留痕”会成为
 *     无法追溯的入口。
 *
 * 旧展示列（type / status / city / location / organizer / hot / teams /
 * icon / bg_color / sort / date）只做**兼容默认值**，不接受调用方透传，
 * 也不进入公开 DTO。G1 公开读取只认 revision 与发布轴。
 */
class EventDraftService
{
    /**
     * 调用方可写的字段。发布轴、处置轴、revision 指针、行版本与审计字段
     * 一律由服务端钉死。
     *
     * @var array<int, string>
     */
    public const CREATE_FIELDS = [
        'name',
        'kind',
        'participation_mode',
        'hosting_status',
        'timezone',
        'starts_at',
        'ends_at',
        'source_type',
        'platform_role',
        'organizer_id',
    ];

    /**
     * 旧赛事展示列的安全默认值（仅兼容旧后台/旧列表，不做公开 DTO）。
     *
     * @var array<string, mixed>
     */
    private const LEGACY_DEFAULTS = [
        'type'     => '生态活动',
        'status'   => 'upcoming',
        'city'     => null,
        'location' => null,
        'hot'      => 0,
        'teams'    => 0,
        'icon'     => '',
        'bg_color' => '#E8F0FE',
        'sort'     => 0,
        'date'     => null,
    ];

    public function __construct(
        private readonly EventSourceValidator $sourceValidator,
        private readonly PublicationAudit $audit,
    ) {}

    /**
     * 创建一条 G1 活动草稿壳。
     *
     * 初始结论固定：`publish_status=draft`、`disposition=normal`、
     * `row_version=0`、三个 revision 指针与发布时间全部 NULL。
     * 因此新建后的活动没有可发布内容，不能直接发布。
     *
     * @param  array<string, mixed>  $data  只允许 CREATE_FIELDS 中的键
     *
     * @throws InvalidArgumentException 缺必填字段、越权字段、非法枚举
     * @throws EventSourceValidationException 来源三字段不完整 / 主办方不存在或角色不一致
     * @throws EventTimeValidationException 时区或起止时间非法
     */
    public function createDraft(int $adminId, array $data): Event
    {
        $this->assertOnlyCreateFields($data);
        $this->assertAdminExists($adminId);

        $name = $this->assertName($data['name'] ?? null);
        $kind = $this->assertKind($data['kind'] ?? null);
        $participationMode = $this->assertParticipationMode($data['participation_mode'] ?? null);
        $hostingStatus = $this->assertHostingStatus($data['hosting_status'] ?? null);
        $timezone = $this->assertTimezone($data['timezone'] ?? null);

        $startsAt = $this->localTime($data['starts_at'] ?? null, $timezone, 'starts_at');
        $endsAt = $this->localTime($data['ends_at'] ?? null, $timezone, 'ends_at');
        $this->assertTimeRange($startsAt, $endsAt);

        $sourceType = $this->normalizeNullableString($data['source_type'] ?? null, 'source_type');
        $platformRole = $this->normalizeNullableString($data['platform_role'] ?? null, 'platform_role');
        $organizerId = $this->normalizeOrganizerId($data['organizer_id'] ?? null);

        if ($sourceType === null || $platformRole === null || $organizerId === null) {
            throw new EventSourceValidationException(
                ApiErrorCode::EVENT_SOURCE_REQUIRED,
                '新建活动必须同时填写 source_type / platform_role / organizer_id。',
            );
        }

        // 草稿边界：枚举、组合、主办方存在性 / 角色一致 / 非 rejected|suspended。
        $this->sourceValidator->assertDraftValues($sourceType, $platformRole, $organizerId);

        $organizer = EcosystemOrganizer::query()->findOrFail($organizerId);

        return DB::transaction(function () use (
            $adminId,
            $name,
            $kind,
            $participationMode,
            $hostingStatus,
            $timezone,
            $startsAt,
            $endsAt,
            $sourceType,
            $platformRole,
            $organizerId,
            $organizer,
        ): Event {
            $event = new Event;

            $event->fill(self::LEGACY_DEFAULTS);

            // 旧自由文本主办方只做兼容展示；权威关系是 organizer_id。
            $event->organizer = (string) $organizer->name;

            $event->name = $name;
            $event->kind = $kind;
            $event->participation_mode = $participationMode;
            $event->hosting_status = $hostingStatus;
            $event->timezone = $timezone;
            $event->starts_at = $startsAt;
            $event->ends_at = $endsAt;

            $event->source_type = $sourceType;
            $event->platform_role = $platformRole;
            $event->organizer_id = $organizerId;

            // 服务端结论：新活动永远是草稿、正常处置、无任何公开/候选指针。
            $event->publish_status = 'draft';
            $event->disposition = 'normal';
            $event->published_revision_id = null;
            $event->working_revision_id = null;
            $event->approved_revision_id = null;
            $event->scheduled_publish_at = null;
            $event->first_published_at = null;
            $event->published_at = null;
            $event->row_version = 0;

            $event->save();

            $this->audit->record(
                'event',
                (string) config('ecosystem_publishing.audit_actions.event.create'),
                $adminId,
                (int) $event->getKey(),
                [
                    'to'          => 'draft',
                    'reason_code' => (string) config('ecosystem_publishing.reason_codes.created'),
                ],
            );

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertOnlyCreateFields(array $data): void
    {
        $forbidden = array_values(array_diff(array_keys($data), self::CREATE_FIELDS));

        if ($forbidden !== []) {
            throw new InvalidArgumentException(sprintf(
                '创建活动草稿时不允许传入字段：%s；发布轴、处置轴、指针、行版本、旧展示列与审计字段只能由服务端维护。',
                implode(', ', $forbidden),
            ));
        }
    }

    private function assertAdminExists(int $adminId): void
    {
        if ($adminId <= 0 || ! DB::table('admins')->where('id', $adminId)->exists()) {
            throw new InvalidArgumentException(sprintf('创建活动草稿需要存在的后台账号，admin_id=%d 无效。', $adminId));
        }
    }

    private function assertName(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('活动名称不能为空。');
        }

        $value = trim($value);

        if (mb_strlen($value) > 255) {
            throw new InvalidArgumentException('活动名称不得超过 255 个字符。');
        }

        return $value;
    }

    private function assertKind(mixed $value): string
    {
        $allowed = array_values((array) config('ecosystem_publishing.event_kinds', []));

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'kind 必须是 %s 之一，收到「%s」。',
                implode(' / ', $allowed),
                is_scalar($value) ? (string) $value : gettype($value),
            ));
        }

        return $value;
    }

    private function assertParticipationMode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $allowed = array_values((array) config('ecosystem_revisions.participation_modes', []));

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'participation_mode 必须是 %s 之一，收到「%s」。',
                implode(' / ', $allowed),
                is_scalar($value) ? (string) $value : gettype($value),
            ));
        }

        return $value;
    }

    private function assertHostingStatus(mixed $value): string
    {
        $known = array_values((array) config('ecosystem_publishing.event_hosting_statuses', []));

        if (! is_string($value) || ! in_array($value, $known, true)) {
            throw new InvalidArgumentException(sprintf(
                'hosting_status 必须是 %s 之一，收到「%s」。',
                implode(' / ', $known),
                is_scalar($value) ? (string) $value : gettype($value),
            ));
        }

        // B-06 未签署：postponed / rescheduled / canceled 的写能力关闭。
        $allowed = array_values((array) config('ecosystem_publishing.review_allowed_hosting_statuses', []));

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'hosting_status=%s 尚未开放（B-06 未签署）；当前只允许 %s。',
                $value,
                implode(' / ', $allowed),
            ));
        }

        return $value;
    }

    private function assertTimezone(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new EventTimeValidationException(sprintf(
                'timezone「%s」不是合法的 IANA 时区名称（不得使用 +08:00 这类偏移）。',
                is_scalar($value) ? (string) $value : gettype($value),
            ));
        }

        return $value;
    }

    private function normalizeNullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('字段 %s 必须是字符串或 NULL。', $field));
        }

        return $value;
    }

    private function normalizeOrganizerId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('organizer_id 必须是正整数。');
    }

    private function localTime(mixed $value, string $timezone, string $field): ?Carbon
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
            return Carbon::parse($value, $timezone)->utc();
        } catch (\Throwable) {
            throw new EventTimeValidationException(sprintf('字段 %s 的时间「%s」无法解析。', $field, $value));
        }
    }

    private function assertTimeRange(?Carbon $startsAt, ?Carbon $endsAt): void
    {
        if (($startsAt === null) !== ($endsAt === null)) {
            throw new EventTimeValidationException('starts_at 与 ends_at 必须同时为空（待定）或同时填写。');
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lt($startsAt)) {
            throw new EventTimeValidationException('ends_at 不得早于 starts_at。');
        }
    }
}
