<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;

/**
 * 主办方主体写入口（ADR-0001 §4.4 / G1-D05）。
 *
 * ── 边界 ──
 *   · `create()` 永远落 `pending`，调用方不得自带 `status` / `verified_by` / `verified_at`；
 *   · `update()` 只改资料字段，不允许直接改核验状态；
 *   · `verify()/reject()/suspend()` 是唯一的核验流转入口，全部在行锁内完成；
 *   · **不提供 delete**：主体被活动引用后物理删除会让历史主办权指向悬空（FK RESTRICT 也会拒绝）。
 *
 * ── 重新核验 ──
 *   已核验/已驳回/已暂停的主体一旦改动 `verification_reset_fields`
 *   （名称、角色、来源、公开资料、私有依据），说明核验事实已经变化：
 *   回 `pending` 并清空 `verified_by` / `verified_at`，但**保留 `verification_note`**
 *   作为上一轮核验记录，避免把审核意见一起抹掉。
 *
 * ── 错误口径 ──
 *   · 来源/角色/URL/证据缺失 → `EventSourceValidationException`（EVENT_SOURCE_REQUIRED）；
 *   · 调用方式错误（自带核验字段、未知管理员、非法状态流转、缺审核意见）→ InvalidArgumentException。
 */
class OrganizerService
{
    private const CREATE_FIELDS = [
        'name',
        'platform_role',
        'source_url',
        'public_materials',
        'private_evidence_ref',
        'verification_note',
    ];

    private const UPDATE_FIELDS = [
        'name',
        'platform_role',
        'source_url',
        'public_materials',
        'private_evidence_ref',
        'verification_note',
    ];

    /** 核验字段只能由 verify/reject/suspend 维护。 */
    private const REVIEW_FIELDS = [
        'status',
        'verified_by',
        'verified_at',
    ];

    public function __construct(
        private readonly EventSourceValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): EcosystemOrganizer
    {
        $forbidden = array_values(array_intersect(array_keys($attributes), self::REVIEW_FIELDS));

        if ($forbidden !== []) {
            throw new InvalidArgumentException(sprintf(
                '创建主办方时不允许传入核验字段：%s；新主体一律为 pending。',
                implode(', ', $forbidden),
            ));
        }

        $this->assertOnlyFields($attributes, self::CREATE_FIELDS, '创建');

        $name = $attributes['name'] ?? null;
        $this->assertName($name);
        $attributes['name'] = trim($name);

        $this->assertPlatformRoleRequired($attributes['platform_role'] ?? null);

        if (array_key_exists('source_url', $attributes)) {
            $attributes['source_url'] = $this->normalizeSourceUrl($attributes['source_url']);
        }

        $this->assertOptionalTextFields($attributes);

        // 强制结论：新主体未经核验。
        $attributes['status'] = 'pending';
        $attributes['verified_by'] = null;
        $attributes['verified_at'] = null;

        return EcosystemOrganizer::create($attributes);
    }

    /**
     * 只更新资料字段；已核验主体验证敏感字段变更后自动回 pending。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(EcosystemOrganizer $organizer, array $attributes): EcosystemOrganizer
    {
        $this->assertOnlyFields($attributes, self::UPDATE_FIELDS, '更新');

        if (array_key_exists('name', $attributes)) {
            $this->assertName($attributes['name']);
            $attributes['name'] = trim($attributes['name']);
        }

        if (array_key_exists('platform_role', $attributes)) {
            $this->assertPlatformRoleRequired($attributes['platform_role']);
        }

        if (array_key_exists('source_url', $attributes)) {
            $attributes['source_url'] = $this->normalizeSourceUrl($attributes['source_url']);
        }

        $this->assertOptionalTextFields($attributes);

        return DB::transaction(function () use ($organizer, $attributes): EcosystemOrganizer {
            // 以库内当前状态为准：调用方手里的模型可能是核验前的旧快照，
            // 直接读它的 status 会漏掉“刚刚被 verify/reject/suspend”的流转。
            $locked = EcosystemOrganizer::query()
                ->whereKey($organizer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->fill($attributes);

            $resetFields = array_values((array) config('ecosystem_organizers.verification_reset_fields', []));
            $currentStatus = (string) $locked->status;

            $shouldReset = in_array($currentStatus, ['verified', 'rejected', 'suspended'], true)
                && $resetFields !== []
                && $locked->isDirty($resetFields);

            if ($shouldReset) {
                $locked->status = 'pending';
                $locked->verified_by = null;
                $locked->verified_at = null;
                // verification_note 刻意保留：它是上一轮核验/驳回/暂停的记录。
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked;
        });
    }

    /**
     * 核验通过：pending / rejected / suspended → verified。
     */
    public function verify(int $organizerId, int $adminId, ?string $note = null): EcosystemOrganizer
    {
        return DB::transaction(function () use ($organizerId, $adminId, $note): EcosystemOrganizer {
            $organizer = $this->lockForReview($organizerId);

            $this->assertAdminExists($adminId);

            if (! in_array($organizer->status, ['pending', 'rejected', 'suspended'], true)) {
                throw new InvalidArgumentException(sprintf(
                    '主办方 %d 当前状态为 %s，不允许核验通过。',
                    $organizerId,
                    (string) $organizer->status,
                ));
            }

            $this->assertHasEvidence($organizer);

            $organizer->status = 'verified';
            $organizer->verified_by = $adminId;
            $organizer->verified_at = now()->utc();

            if ($note !== null) {
                $organizer->verification_note = $note;
            }

            $organizer->save();

            return $organizer;
        });
    }

    /**
     * 驳回：仅 pending → rejected；必须写明理由。
     */
    public function reject(int $organizerId, int $adminId, string $note): EcosystemOrganizer
    {
        return DB::transaction(function () use ($organizerId, $adminId, $note): EcosystemOrganizer {
            $organizer = $this->lockForReview($organizerId);

            $this->assertAdminExists($adminId);

            if ($organizer->status !== 'pending') {
                throw new InvalidArgumentException(sprintf(
                    '主办方 %d 当前状态为 %s，只有 pending 可以驳回。',
                    $organizerId,
                    (string) $organizer->status,
                ));
            }

            $this->assertNoteProvided($note, '驳回');

            $organizer->status = 'rejected';
            $organizer->verified_by = $adminId;
            $organizer->verified_at = now()->utc();
            $organizer->verification_note = $note;
            $organizer->save();

            return $organizer;
        });
    }

    /**
     * 暂停：仅 verified → suspended；必须写明理由。
     */
    public function suspend(int $organizerId, int $adminId, string $note): EcosystemOrganizer
    {
        return DB::transaction(function () use ($organizerId, $adminId, $note): EcosystemOrganizer {
            $organizer = $this->lockForReview($organizerId);

            $this->assertAdminExists($adminId);

            if ($organizer->status !== 'verified') {
                throw new InvalidArgumentException(sprintf(
                    '主办方 %d 当前状态为 %s，只有 verified 可以暂停。',
                    $organizerId,
                    (string) $organizer->status,
                ));
            }

            $this->assertNoteProvided($note, '暂停');

            $organizer->status = 'suspended';
            $organizer->verified_by = $adminId;
            $organizer->verified_at = now()->utc();
            $organizer->verification_note = $note;
            $organizer->save();

            return $organizer;
        });
    }

    private function lockForReview(int $organizerId): EcosystemOrganizer
    {
        return EcosystemOrganizer::query()
            ->whereKey($organizerId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertAdminExists(int $adminId): void
    {
        $exists = DB::table('admins')->where('id', $adminId)->exists();

        if (! $exists) {
            throw new InvalidArgumentException(sprintf('核验人（admin %d）不存在。', $adminId));
        }
    }

    private function assertHasEvidence(EcosystemOrganizer $organizer): void
    {
        $fields = array_values((array) config(
            'ecosystem_organizers.evidence_fields',
            ['public_materials', 'private_evidence_ref']
        ));

        foreach ($fields as $field) {
            $value = $organizer->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                return;
            }
        }

        throw new EventSourceValidationException(
            ApiErrorCode::EVENT_SOURCE_REQUIRED,
            '核验通过前必须至少填写一项依据（公开资料或私有依据）。',
        );
    }

    private function assertNoteProvided(string $note, string $action): void
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException(sprintf('%s操作必须填写核验说明。', $action));
        }
    }

    private function assertName(mixed $name): void
    {
        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('主办方名称不能为空。');
        }

        if (mb_strlen(trim($name)) > 191) {
            throw new InvalidArgumentException('主办方名称不得超过 191 个字符。');
        }
    }

    private function assertPlatformRoleRequired(mixed $platformRole): void
    {
        if (! is_string($platformRole) || $platformRole === '') {
            throw new EventSourceValidationException(
                ApiErrorCode::EVENT_SOURCE_REQUIRED,
                '主办方平台角色不能为空。',
            );
        }

        $this->validator->assertPlatformRole($platformRole);
    }

    private function normalizeSourceUrl(mixed $sourceUrl): ?string
    {
        if ($sourceUrl === null) {
            return null;
        }

        if (! is_string($sourceUrl)) {
            throw new InvalidArgumentException('来源 URL 必须是字符串。');
        }

        if ($sourceUrl === '') {
            return null;
        }

        $this->validator->assertSourceUrl($sourceUrl);

        return $sourceUrl;
    }

    /**
     * TEXT / VARCHAR(255) 资料字段的类型与长度护栏，避免把数组或超长值交给 SQL。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertOptionalTextFields(array $attributes): void
    {
        $limits = [
            'public_materials'     => null,
            'private_evidence_ref' => 255,
            'verification_note'    => null,
        ];

        foreach ($limits as $field => $maxLength) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                continue;
            }

            if (! is_string($attributes[$field])) {
                throw new InvalidArgumentException(sprintf('%s 必须是字符串或 NULL。', $field));
            }

            if ($maxLength !== null && mb_strlen($attributes[$field]) > $maxLength) {
                throw new InvalidArgumentException(sprintf('%s 不得超过 %d 个字符。', $field, $maxLength));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $allowed
     */
    private function assertOnlyFields(array $attributes, array $allowed, string $action): void
    {
        $unknown = array_values(array_diff(array_keys($attributes), $allowed));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s主办方时不接受字段：%s；只允许：%s。',
                $action,
                implode(', ', $unknown),
                implode(', ', $allowed),
            ));
        }
    }
}
