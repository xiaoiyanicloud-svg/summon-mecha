<?php

namespace Webkul\Shop\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * UTC DATETIME 专用 cast（ADR-0001 G1-D04）。
 *
 * 背景：应用默认时区是 Asia/Shanghai（`config/app.php`），而 G1 的时间字段
 * （outbox `available_at` / `lease_until`，活动 `starts_at` / `ends_at` 等）
 * 约定**以 UTC 落 DATETIME**。MySQL DATETIME 不带时区，用 Eloquent 默认的
 * `datetime` cast 读取时会被当作上海时间解释 —— 同一个“2026-10-19 10:00:00”
 * 会凭空差 8 小时（28800 秒）。
 *
 * 本 cast 把语义钉死：
 *   · get：库内字符串**明确按 UTC 解析**，返回 UTC 时区的 Carbon，NULL 仍是 NULL；
 *   · set：NULL / 空串落 NULL；DateTimeInterface 按其自身时区换算为 UTC；
 *           不带时区的字符串按应用默认时区解释后换算为 UTC；
 *           最终统一格式化为 `Y-m-d H:i:s` 落库。
 *
 * ⚠ 只用于“UTC 存储”字段。不要拿它去改既有旧列（例如 events.date 是自由文本）。
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->utc();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! $value instanceof DateTimeInterface && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'UtcDateTime 只接受 DateTimeInterface 或字符串，字段 %s 收到 %s。',
                $key,
                get_debug_type($value),
            ));
        }

        // 字符串不带偏移时由 Carbon 使用应用默认时区（Asia/Shanghai）解释，
        // 然后显式换算到 UTC；带偏移的字符串按其自身偏移换算。
        $dateTime = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);

        return $dateTime->utc()->format('Y-m-d H:i:s');
    }
}
