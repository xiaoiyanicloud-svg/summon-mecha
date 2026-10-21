<?php

namespace Webkul\Shop\Services\Scenes;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;

class SceneTime
{
    /** 实际发生时间必须自带 offset；禁止依赖服务器时区或默许 PHP 修正非法日期。 */
    public static function occurredAt(string $text): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $text)) {
            throw new SceneWorkflowException('SCENE_EVENT_TIME_INVALID', '实际发生时间须为含 UTC offset 的 ISO 8601 秒级格式。');
        }
        $normalized = preg_replace('/Z$/', '+00:00', $text);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i:sP') !== $normalized || abs($date->getOffset()) > 14 * 3600
            || $date->getTimestamp() > now('UTC')->timestamp) {
            throw new SceneWorkflowException('SCENE_EVENT_TIME_INVALID', '实际发生时间无效或晚于当前时间。');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function schedule(array $input): array
    {
        $data = Validator::make($input, [
            'timezone'              => 'required|string|max:80', 'starts_at' => 'required|string|max:40', 'ends_at' => 'required|string|max:40',
            'buffer_before_minutes' => 'required|integer|min:0|max:10080', 'buffer_after_minutes' => 'required|integer|min:0|max:10080',
        ])->validate();
        if (! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new SceneWorkflowException('SCENE_TIMEZONE_INVALID', '请提供服务地的 IANA 时区。');
        }
        $zone = new DateTimeZone($data['timezone']);
        $parse = static function ($text) use ($zone) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $text)) {
                throw new SceneWorkflowException('SCENE_TIME_OFFSET_REQUIRED', '时间须使用含 UTC offset 的 ISO 8601 秒级格式。');
            }
            $normalized = preg_replace('/Z$/', '+00:00', $text);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
            $errors = DateTimeImmutable::getLastErrors();
            if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->setTimezone($zone)->format('Y-m-d\TH:i:sP') !== $normalized) {
                throw new SceneWorkflowException('SCENE_LOCAL_TIME_INVALID', '时间不存在、格式无效或 offset 与服务地时区不匹配。');
            }

            return $date->setTimezone(new DateTimeZone('UTC'));
        };
        $start = $parse($data['starts_at']);
        $end = $parse($data['ends_at']);
        if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 366 * 86400) {
            throw new SceneWorkflowException('SCENE_TIME_RANGE_INVALID', '结束时间须晚于开始时间，单次服务不超过 366 天。');
        }

        return $data + [
            'service_starts_at' => $start->format('Y-m-d H:i:s'), 'service_ends_at' => $end->format('Y-m-d H:i:s'),
            'occupies_from'     => $start->modify('-'.$data['buffer_before_minutes'].' minutes')->format('Y-m-d H:i:s'),
            'occupies_until'    => $end->modify('+'.$data['buffer_after_minutes'].' minutes')->format('Y-m-d H:i:s'),
        ];
    }
}
