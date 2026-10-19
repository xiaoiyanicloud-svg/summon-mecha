<?php

namespace Webkul\Shop\Services\Ecosystem;

use InvalidArgumentException;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemMediaRef;

/**
 * 媒体集合指纹（ADR-0001 §4.3 / §4.7 / §4.9 / §4.10 / G1-06）。
 *
 * 指纹用于把「某个 revision 引用了哪些媒体、以什么顺序 / 角色展示」
 * 固化成一个可比较的 sha256：
 *   · 参与字段：`media_id`、`media.sha256`、`role`、`sort_order`、
 *     `alt_text`、`review_status`；
 *   · 算法与 ContentFingerprint 一致：递归 ksort + `fingerprint_version`
 *     （`config('ecosystem_revisions.fingerprint_version')`）+ 规范化 JSON；
 *   · **不读取文件字节**。`media.sha256` 取自 `ecosystem_media` 元数据，
 *     本类最多补一次按 ID 的元数据查询，绝不打开私有盘文件。
 *
 * 为什么必须包含 review_status：
 *   引用审核状态变化会改变“这份媒体集是否可公开”的事实。若指纹只看文件与
 *   顺序，审核后替换 / 撤下引用的动作不会产生新指纹，批准时绑定的
 *   `media_manifest_hash` 就失去意义。
 *
 * 输入可以是 `EcosystemMediaRef` 模型（`media` 关系已加载或未加载均可），
 * 也可以是等价的数组：
 *   ['media_id' => 1, 'media_sha256' => '…', 'role' => 'cover',
 *    'sort_order' => 0, 'alt_text' => null, 'review_status' => 'pending']
 * 数组也接受 `sha256` 或 `media['sha256']` 作为 `media_sha256` 的别名。
 */
class MediaManifest
{
    /**
     * 参与指纹的引用字段（白名单）。
     *
     * @var array<int, string>
     */
    public const REF_FIELDS = [
        'media_id',
        'media_sha256',
        'role',
        'sort_order',
        'alt_text',
        'review_status',
    ];

    /**
     * @param  array<int, EcosystemMediaRef|array<string, mixed>>  $refs
     *
     * @throws InvalidArgumentException 引用缺少 media_id / media sha256 或字段类型非法
     */
    public function hash(array $refs): string
    {
        $snapshot = [];

        foreach (array_values($refs) as $index => $ref) {
            $snapshot[] = $this->normalizeRef($ref, $index);
        }

        // sort_order 是展示顺序的唯一权威；同一 revision 内由服务端保证
        // 0..n-1 不重复。这里再用 media_id 兜底，避免调用方传入重复序时
        // 因 PHP 非稳定排序产生两个不同的指纹。
        usort($snapshot, function (array $left, array $right): int {
            return [$left['sort_order'], $left['media_id']] <=> [$right['sort_order'], $right['media_id']];
        });

        return hash('sha256', (string) json_encode(
            $this->normalize([
                'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
                'type'                => 'media_manifest',
                'refs'                => $snapshot,
            ]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRef(mixed $ref, int $index): array
    {
        if ($ref instanceof EcosystemMediaRef) {
            $media = $ref->relationLoaded('media') ? $ref->getRelation('media') : null;

            $attributes = [
                'media_id'      => $ref->getAttribute('media_id'),
                'media_sha256'  => $media instanceof EcosystemMedia ? $media->sha256 : null,
                'role'          => $ref->getAttribute('role'),
                'sort_order'    => $ref->getAttribute('sort_order'),
                'alt_text'      => $ref->getAttribute('alt_text'),
                'review_status' => $ref->getAttribute('review_status'),
            ];
        } elseif (is_array($ref)) {
            $media = $ref['media'] ?? null;

            $attributes = [
                'media_id'      => $ref['media_id'] ?? null,
                'media_sha256'  => $ref['media_sha256']
                    ?? $ref['sha256']
                    ?? (is_array($media) ? ($media['sha256'] ?? null) : null),
                'role'          => $ref['role'] ?? null,
                'sort_order'    => $ref['sort_order'] ?? null,
                'alt_text'      => $ref['alt_text'] ?? null,
                'review_status' => $ref['review_status'] ?? null,
            ];
        } else {
            throw new InvalidArgumentException(sprintf(
                '第 %d 条媒体引用必须是 %s 或数组。',
                $index,
                EcosystemMediaRef::class,
            ));
        }

        $mediaId = $this->normalizePositiveInt($attributes['media_id'], 'media_id', $index);
        $sha256 = $this->normalizeSha256($attributes['media_sha256'], $mediaId, $index);

        $role = $attributes['role'];

        if (! is_string($role) || trim($role) === '') {
            throw new InvalidArgumentException(sprintf('第 %d 条媒体引用缺少 role。', $index));
        }

        $reviewStatus = $attributes['review_status'];

        if (! is_string($reviewStatus) || trim($reviewStatus) === '') {
            throw new InvalidArgumentException(sprintf('第 %d 条媒体引用缺少 review_status。', $index));
        }

        $altText = $attributes['alt_text'];

        if ($altText !== null && ! is_string($altText)) {
            throw new InvalidArgumentException(sprintf('第 %d 条媒体引用的 alt_text 必须是字符串或 NULL。', $index));
        }

        return [
            'media_id'      => $mediaId,
            'media_sha256'  => $sha256,
            'role'          => $role,
            'sort_order'    => $this->normalizeSortOrder($attributes['sort_order'], $index),
            'alt_text'      => $altText,
            'review_status' => $reviewStatus,
        ];
    }

    private function normalizePositiveInt(mixed $value, string $field, int $index): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf(
            '第 %d 条媒体引用的 %s 必须是正整数。',
            $index,
            $field,
        ));
    }

    private function normalizeSortOrder(mixed $value, int $index): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf(
            '第 %d 条媒体引用的 sort_order 必须是非负整数。',
            $index,
        ));
    }

    /**
     * 优先用调用方提供的 sha256；缺失时只补一次元数据查询（不读文件）。
     */
    private function normalizeSha256(mixed $value, int $mediaId, int $index): string
    {
        if ($value === null || $value === '') {
            $value = EcosystemMedia::query()->whereKey($mediaId)->value('sha256');
        }

        if (! is_string($value) || preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '第 %d 条媒体引用（media_id=%d）缺少合法的 media.sha256。',
                $index,
                $mediaId,
            ));
        }

        return $value;
    }

    /**
     * 递归 ksort，保证同一组引用在不同数组顺序下得到同一份字节。
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
