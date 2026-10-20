<?php

namespace Webkul\Shop\Services\Ecosystem;

/**
 * G4-01 · 公开列表游标。
 *
 * 目标：在「按时间倒序 + id 决胜」的列表里做到跨页不重不漏，
 * 不依赖 OFFSET，也不把数据库自增 ID 暴露成可枚举的偏移量。
 *
 * 游标内容（base64url JSON）：
 *   v  版本，当前固定 1
 *   t  排序时间（UTC `Y-m-d H:i:s`；无时间为 null）
 *   id 决胜键
 *   f  规范化筛选条件的 sha256 前 16 位
 *
 * 安全边界：
 *   · 游标不是授权凭证，篡改它只能改变翻页位置，不能越过公开可见性；
 *   · 所有值都会再次进入参数绑定，不做字符串拼接；
 *   · 筛选条件变化时 `f` 不匹配，明确报错而不是悄悄从头开始。
 */
final class PublicListCursor
{
    public const VERSION = 1;

    /** 未设置排序时间时使用的 sentinel；SQL 侧按 NULLS LAST 处理。 */
    public const NULL_SORT = null;

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function encode(?string $sortValue, int $id, array $filters): string
    {
        $payload = [
            'v'  => self::VERSION,
            't'  => $sortValue,
            'id' => $id,
            'f'  => self::filterHash($filters),
        ];

        return self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{sort: string|null, id: int}
     *
     * @throws PublicCursorException
     */
    public static function decode(string $cursor, array $filters): array
    {
        $cursor = trim($cursor);

        if ($cursor === '' || strlen($cursor) > 512) {
            throw PublicCursorException::invalid();
        }

        $raw = self::base64UrlDecode($cursor);

        if ($raw === null) {
            throw PublicCursorException::invalid();
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || (int) ($payload['v'] ?? 0) !== self::VERSION) {
            throw PublicCursorException::invalid();
        }

        $id = $payload['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw PublicCursorException::invalid();
        }

        $id = (int) $id;

        if ($id <= 0) {
            throw PublicCursorException::invalid();
        }

        $sort = $payload['t'] ?? null;

        if ($sort !== null) {
            if (! is_string($sort) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sort) !== 1) {
                throw PublicCursorException::invalid();
            }
        }

        $expected = self::filterHash($filters);

        if (! is_string($payload['f'] ?? null) || ! hash_equals($expected, (string) $payload['f'])) {
            throw PublicCursorException::filterMismatch();
        }

        return ['sort' => $sort, 'id' => $id];
    }

    /**
     * 筛选条件先排序键，保证同一组条件无论参数顺序如何都得到同一指纹。
     *
     * @param  array<string, mixed>  $filters
     */
    public static function filterHash(array $filters): string
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $normalized[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        ksort($normalized);

        return substr(hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
