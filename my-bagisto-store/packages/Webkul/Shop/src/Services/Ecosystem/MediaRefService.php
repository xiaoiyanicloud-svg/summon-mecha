<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemMediaRef;
use Webkul\Shop\Models\EventRevision;

/**
 * revision 媒体引用写入口（ADR-0001 §4.9 / §4.10 / G1-D06 / G1-06）。
 *
 * 边界：
 *   · 只有 `event_revision` / `content_revision` 两种 owner，且父 revision
 *     必须仍是 `draft`；非 draft 由模型守卫与本文服务双重拒绝；
 *   · 一个 revision 最多 `config('ecosystem_media.max_refs_per_revision')` 条引用；
 *   · 每条被引用的媒体自身必须 `status=approved`（未批准媒体不得进入草稿引用）；
 *   · `sort_order` 一律按输入顺序重排为 0..n-1，不信任调用方传值；
 *   · 替换是**整组替换**：先删旧引用，再按新顺序创建，初始 `review_status=pending`；
 *   · 替换后按新的媒体集合重新净化父 revision 正文（G1-07）并写
 *     `sanitizer_version`：正文里引用了不在新集合内的图片必须被删掉，
 *     否则「撤下媒体」后正文仍留着 `data-media-id`；
 *   · 替换 / 引用审核后都重算 `media_manifest_hash` 与父 revision 的
 *     `content_fingerprint` —— 资讯修订计算指纹时必须带上 `content_sources`，
 *     否则“只换来源不改正文”会绕过指纹核验。
 *
 * 引用审核（reviewRef）与媒体自身审核（MediaService::approve）是两条独立轴：
 * 引用可以在媒体已批准后单独驳回，但引用要转为 approved 时媒体必须仍 approved。
 */
class MediaRefService
{
    /**
     * 引用输入允许的键。`sort_order` 接受但会被服务端按输入顺序覆盖；
     * `id` / `owner_type` / `owner_id` / `review_status` 属于服务端字段，不得透传。
     *
     * @var array<int, string>
     */
    public const INPUT_FIELDS = [
        'media_id',
        'role',
        'alt_text',
        'sort_order',
    ];

    /**
     * 引用审核目标状态：只允许 pending -> approved / rejected。
     *
     * @var array<int, string>
     */
    private const REVIEW_TARGETS = ['approved', 'rejected'];

    public function __construct(
        private readonly ContentFingerprint $fingerprints,
        private readonly MediaManifest $manifest,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /**
     * 整组替换某个 draft revision 的媒体引用。
     *
     * @param  array<int, array<string, mixed>>  $refs  按展示顺序传入
     * @return array<int, EcosystemMediaRef>
     *
     * @throws MediaValidationException 父 revision 不存在 / 非 draft、媒体不存在或未批准、
     *                                  role / alt_text 非法、超过引用上限、输入含越权字段
     */
    public function replaceForRevision(string $ownerType, int $ownerId, array $refs): array
    {
        $ownerType = $this->assertOwnerType($ownerType);
        $this->assertOwnerId($ownerId);

        $refs = array_values($refs);
        $maxRefs = (int) config('ecosystem_media.max_refs_per_revision', 20);

        if (count($refs) > $maxRefs) {
            throw new MediaValidationException(sprintf(
                '一个 revision 最多引用 %d 个媒体，收到 %d 个。',
                $maxRefs,
                count($refs),
            ));
        }

        $normalized = $this->normalizeRefs($refs);

        return DB::transaction(function () use ($ownerType, $ownerId, $normalized): array {
            // 统一锁顺序：先 revision，再 media / refs，避免与 reviewRef 互相死锁。
            $revision = $this->lockDraftRevision($ownerType, $ownerId);
            $media = $this->lockApprovedMedia($normalized);

            // draft 守卫下整组替换：旧引用直接删除，新引用按输入顺序重建。
            foreach ($this->refsFor($ownerType, $ownerId) as $existing) {
                $existing->delete();
            }

            $created = [];

            foreach ($normalized as $index => $item) {
                $ref = EcosystemMediaRef::create([
                    'media_id'      => $item['media_id'],
                    'owner_type'    => $ownerType,
                    'owner_id'      => $ownerId,
                    'role'          => $item['role'],
                    'sort_order'    => $index,
                    'alt_text'      => $item['alt_text'],
                    'review_status' => 'pending',
                ]);

                $ref->setRelation('media', $media[$item['media_id']]);
                $created[] = $ref;
            }

            $this->syncRevisionManifest(
                $revision,
                $ownerType,
                array_values(array_unique(array_map(
                    static fn (array $item): int => $item['media_id'],
                    $normalized,
                ))),
            );

            return $created;
        });
    }

    /**
     * 某 revision 下「已被引用且媒体自身 approved」的 media ID。
     *
     * 只看 `ecosystem_media.status`，不看引用自身的 `review_status`：
     * 该方法回答的是“这些媒体对象是否仍可作为该 revision 的供给”，
     * 公开可见性仍由 MediaAccessService 另行要求引用 approved。
     *
     * @return array<int, int>
     */
    public function approvedMediaIdsForRevision(string $ownerType, int $ownerId): array
    {
        $ownerType = $this->assertOwnerType($ownerType);
        $this->assertOwnerId($ownerId);

        return EcosystemMediaRef::query()
            ->join('ecosystem_media', 'ecosystem_media.id', '=', 'ecosystem_media_refs.media_id')
            ->where('ecosystem_media_refs.owner_type', $ownerType)
            ->where('ecosystem_media_refs.owner_id', $ownerId)
            ->where('ecosystem_media.status', 'approved')
            ->orderBy('ecosystem_media_refs.sort_order')
            ->orderBy('ecosystem_media_refs.id')
            ->pluck('ecosystem_media_refs.media_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * pending -> approved / rejected。
     *
     * approve 时媒体必须仍 `status=approved`（媒体可能在上传后、引用审核前被隔离）。
     * 引用审核同样改变 media_manifest_hash，因此同步重算父 revision 的指纹；
     * 父 revision 非 draft 时模型守卫会拒绝，不留下滞后指纹。
     *
     * @throws MediaValidationException 引用不存在、目标状态非法、媒体未批准、父 revision 非 draft
     * @throws MediaTransitionException 引用不是 pending
     * @throws InvalidArgumentException 后台账号无效
     */
    public function reviewRef(int $refId, string $status, int $adminId): EcosystemMediaRef
    {
        if ($refId <= 0) {
            throw new MediaValidationException('要审核的媒体引用 ID 必须是正整数。');
        }

        if (! in_array($status, self::REVIEW_TARGETS, true)) {
            throw new MediaValidationException(sprintf(
                '引用审核只允许目标状态 %s，收到「%s」。',
                implode(' / ', self::REVIEW_TARGETS),
                $status,
            ));
        }

        $this->assertAdminExists($adminId);

        return DB::transaction(function () use ($refId, $status): EcosystemMediaRef {
            $ref = EcosystemMediaRef::query()->find($refId);

            if ($ref === null) {
                throw new MediaValidationException(sprintf('媒体引用 %d 不存在。', $refId));
            }

            $ownerType = $this->assertOwnerType((string) $ref->owner_type);

            // 锁顺序与 replaceForRevision 保持一致：revision -> ref。
            $revision = $this->lockDraftRevision($ownerType, (int) $ref->owner_id);

            $locked = EcosystemMediaRef::query()
                ->whereKey($refId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new MediaValidationException(sprintf('媒体引用 %d 不存在。', $refId));
            }

            if ((string) $locked->review_status !== 'pending') {
                throw new MediaTransitionException(sprintf(
                    '媒体引用 %d 当前 review_status=%s，只能从 pending 转为 approved / rejected。',
                    $refId,
                    (string) $locked->review_status,
                ));
            }

            if ($status === 'approved') {
                $media = EcosystemMedia::query()
                    ->whereKey($locked->media_id)
                    ->lockForUpdate()
                    ->first();

                if ($media === null || (string) $media->status !== 'approved') {
                    throw new MediaValidationException(sprintf(
                        '媒体引用 %d 的媒体 %s 未批准，不能批准该引用。',
                        $refId,
                        (string) $locked->media_id,
                    ));
                }
            }

            $locked->review_status = $status;
            $locked->save();

            $this->syncRevisionManifest($revision, $ownerType);

            return $locked->refresh()->load('media');
        });
    }

    private function assertOwnerType(string $ownerType): string
    {
        $allowed = array_values((array) config('ecosystem_media.owner_types', []));

        if (! in_array($ownerType, $allowed, true)) {
            throw new MediaValidationException(sprintf(
                '媒体引用的 owner_type 必须是 %s 之一，收到「%s」。',
                implode(' / ', $allowed),
                $ownerType,
            ));
        }

        return $ownerType;
    }

    private function assertOwnerId(int $ownerId): void
    {
        if ($ownerId <= 0) {
            throw new MediaValidationException('媒体引用的 owner_id 必须是正整数。');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array{media_id: int, role: string, alt_text: ?string, sort_order: int}>
     */
    private function normalizeRefs(array $refs): array
    {
        $roles = array_values((array) config('ecosystem_media.roles', []));
        $normalized = [];

        // 服务端按输入顺序重排 0..n-1，不信任调用方传来的 sort_order。
        foreach ($refs as $index => $ref) {
            if (! is_array($ref)) {
                throw new MediaValidationException(sprintf('第 %d 条媒体引用必须是数组。', $index));
            }

            $forbidden = array_values(array_diff(array_keys($ref), self::INPUT_FIELDS));

            if ($forbidden !== []) {
                throw new MediaValidationException(sprintf(
                    '第 %d 条媒体引用不允许传入字段：%s；owner / sort_order / review_status 由服务端维护（sort_order 仅接受但会重排）。',
                    $index,
                    implode(', ', $forbidden),
                ));
            }

            $mediaId = $this->normalizeMediaId($ref['media_id'] ?? null, $index);
            $role = $ref['role'] ?? null;

            if (! is_string($role) || ! in_array($role, $roles, true)) {
                throw new MediaValidationException(sprintf(
                    '第 %d 条媒体引用的 role 必须是 %s 之一，收到「%s」。',
                    $index,
                    implode(' / ', $roles),
                    is_scalar($role) ? (string) $role : gettype($role),
                ));
            }

            $normalized[] = [
                'media_id'   => $mediaId,
                'role'       => $role,
                'alt_text'   => $this->normalizeAltText($ref['alt_text'] ?? null, $index),
                'sort_order' => $index,
            ];
        }

        return $normalized;
    }

    private function normalizeMediaId(mixed $value, int $index): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        throw new MediaValidationException(sprintf(
            '第 %d 条媒体引用的 media_id 必须是正整数。',
            $index,
        ));
    }

    private function normalizeAltText(mixed $value, int $index): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new MediaValidationException(sprintf(
                '第 %d 条媒体引用的 alt_text 必须是字符串或 NULL。',
                $index,
            ));
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 255) {
            throw new MediaValidationException(sprintf(
                '第 %d 条媒体引用的 alt_text 不得超过 255 个字符。',
                $index,
            ));
        }

        return $value;
    }

    /**
     * @return EventRevision|ContentRevision
     */
    private function lockDraftRevision(string $ownerType, int $ownerId): EventRevision|ContentRevision
    {
        $revision = $ownerType === 'event_revision'
            ? EventRevision::query()->whereKey($ownerId)->lockForUpdate()->first()
            : ContentRevision::query()->whereKey($ownerId)->lockForUpdate()->first();

        if ($revision === null) {
            throw new MediaValidationException(sprintf(
                '媒体引用归属的 %s #%d 不存在。',
                $ownerType,
                $ownerId,
            ));
        }

        if ((string) $revision->review_state !== 'draft') {
            throw new MediaValidationException(sprintf(
                '%s #%d 当前 review_state=%s，只有 draft 可以写入媒体引用。',
                $ownerType,
                $ownerId,
                (string) $revision->review_state,
            ));
        }

        return $revision;
    }

    /**
     * 锁定并校验所有被引用媒体；任何一条不存在或未批准即整体拒绝。
     *
     * @param  array<int, array{media_id: int, role: string, alt_text: ?string, sort_order: int}>  $refs
     * @return array<int, EcosystemMedia> keyBy id
     */
    private function lockApprovedMedia(array $refs): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $ref): int => $ref['media_id'],
            $refs,
        )));

        if ($ids === []) {
            return [];
        }

        $media = EcosystemMedia::query()
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (EcosystemMedia $item): int => (int) $item->getKey())
            ->all();

        foreach ($ids as $id) {
            if (! isset($media[$id])) {
                throw new MediaValidationException(sprintf('媒体 %d 不存在。', $id));
            }

            if ((string) $media[$id]->status !== 'approved') {
                throw new MediaValidationException(sprintf(
                    '媒体 %d 当前 status=%s，只有 approved 媒体可以加入 revision 引用。',
                    $id,
                    (string) $media[$id]->status,
                ));
            }
        }

        return $media;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, EcosystemMediaRef>
     */
    private function refsFor(string $ownerType, int $ownerId)
    {
        return EcosystemMediaRef::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * 重算 media_manifest_hash，并用它重算父 revision 的 content_fingerprint。
     *
     * `$allowedMediaIds !== null` 表示这是整组替换：先按新集合重新净化正文，
     * 并写入当前 `sanitizer_version`。引用审核（reviewRef）不重新净化 ——
     * 媒体集合本身没变，正文不需要二次处理。
     *
     * 资讯修订的指纹必须同时包含 `content_sources`（ADR §4.8 / G1-05），
     * 因此这里显式读取库内来源，而不是只拿 revision 行。
     *
     * @param  array<int, int>|null  $allowedMediaIds
     */
    private function syncRevisionManifest(
        EventRevision|ContentRevision $revision,
        string $ownerType,
        ?array $allowedMediaIds = null,
    ): void {
        if ($allowedMediaIds !== null) {
            if ($revision instanceof EventRevision) {
                $revision->detail_html = $this->sanitizer->sanitize(
                    $revision->detail_html,
                    $allowedMediaIds,
                );
            } else {
                $revision->body_html = $this->sanitizer->sanitize(
                    $revision->body_html,
                    $allowedMediaIds,
                );
            }

            $revision->sanitizer_version = $this->sanitizer->version();
        }

        $refs = $this->refsFor($ownerType, (int) $revision->getKey());
        $refs->load('media');

        $revision->media_manifest_hash = $this->manifest->hash($refs->all());

        $revision->content_fingerprint = $revision instanceof EventRevision
            ? $this->fingerprints->eventRevision($revision)
            : $this->fingerprints->contentRevision(
                $revision,
                $revision->sources()->orderBy('sort_order')->get()->all(),
            );

        $revision->save();
    }

    private function assertAdminExists(int $adminId): void
    {
        if ($adminId <= 0 || ! DB::table('admins')->where('id', $adminId)->exists()) {
            throw new InvalidArgumentException(sprintf(
                '媒体引用审核需要存在的后台账号，admin_id=%d 无效。',
                $adminId,
            ));
        }
    }
}
