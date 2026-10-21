<?php

namespace Webkul\Shop\Services\Scenes;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;

/** 工作时间按服务地日历计算，实际分钟以 UTC 累计，跨周末/节假日不偷算。 */
class SceneBusinessCalendar
{
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'timezone' => 'required|string|max:80', 'weekly' => 'required|array|min:1|max:7',
            'weekly.*' => 'array|max:8', 'weekly.*.*' => 'array:start,end',
            'weekly.*.*.start' => 'required|date_format:H:i', 'weekly.*.*.end' => 'required|date_format:H:i',
            'holidays' => 'present|array|max:370', 'holidays.*' => 'required|date_format:Y-m-d|distinct',
            'overrides' => 'present|array|max:370', 'overrides.*' => 'array|max:8',
            'overrides.*.*' => 'array:start,end', 'overrides.*.*.start' => 'required|date_format:H:i', 'overrides.*.*.end' => 'required|date_format:H:i',
            'first_response_minutes' => 'required|integer|min:1|max:10080', 'escalation_minutes' => 'required|integer|min:1|max:20160',
            'owner_id' => 'nullable|integer|min:1', 'escalation_owner_id' => 'nullable|integer|min:1',
        ])->validate();
        if (! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true) || $data['escalation_minutes'] < $data['first_response_minutes']) {
            throw new SceneWorkflowException('SCENE_CALENDAR_INVALID', '日历时区无效，或升级时限早于首次响应时限。');
        }
        foreach ($data['weekly'] as $day => $intervals) {
            if (! in_array((string) $day, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                throw new SceneWorkflowException('SCENE_CALENDAR_INVALID', '工作周键必须为 ISO 星期 1 至 7。');
            }
            $this->checkIntervals($intervals);
        }
        if (! array_filter($data['weekly'])) {
            throw new SceneWorkflowException('SCENE_CALENDAR_INVALID', '工作周至少包含一个工作时段。');
        }
        foreach ($data['overrides'] as $day => $intervals) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $day);
            if (! $date || $date->format('Y-m-d') !== $day) {
                throw new SceneWorkflowException('SCENE_CALENDAR_INVALID', '特殊工作日期无效。');
            }
            $this->checkIntervals($intervals);
        }

        return $data;
    }

    public function deadline(string $utcStart, int $minutes, array $calendar): string
    {
        if ($minutes < 1 || $minutes > 20160) {
            throw new SceneWorkflowException('SCENE_CALENDAR_INVALID', '响应工作分钟数无效。');
        }
        $calendar = $this->validate($calendar);
        $zone = new DateTimeZone($calendar['timezone']);
        $start = new DateTimeImmutable($utcStart, new DateTimeZone('UTC'));
        $remaining = $minutes * 60;
        $day = $start->setTimezone($zone)->setTime(0, 0);
        for ($count = 0; $count < 740; $count++, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $intervals = $calendar['overrides'][$date] ?? (in_array($date, $calendar['holidays'], true) ? [] : ($calendar['weekly'][$day->format('N')] ?? []));
            foreach ($intervals as $interval) {
                $from = $this->localTime($date, $interval['start'], $zone);
                $until = $this->localTime($date, $interval['end'], $zone);
                $cursor = max($start->getTimestamp(), $from->getTimestamp());
                $available = max(0, $until->getTimestamp() - $cursor);
                if ($remaining <= $available) {
                    return (new DateTimeImmutable('@'.($cursor + $remaining)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
                $remaining -= $available;
            }
        }
        throw new SceneWorkflowException('SCENE_CALENDAR_UNREACHABLE', '未来两年日历没有足够工作时间，不能承诺响应时限。', 409);
    }

    private function checkIntervals(array $intervals): void
    {
        $last = null;
        foreach ($intervals as $interval) {
            if ($interval['end'] <= $interval['start'] || ($last !== null && $interval['start'] < $last)) {
                throw new SceneWorkflowException('SCENE_CALENDAR_INVALID', '工作时段须升序、不重叠，且不能跨日。');
            }
            $last = $interval['end'];
        }
    }

    private function localTime(string $date, string $time, DateTimeZone $zone): DateTimeImmutable
    {
        $text = $date.' '.$time;
        $value = new DateTimeImmutable($text, $zone);
        if ($value->format('Y-m-d H:i') !== $text) {
            throw new SceneWorkflowException('SCENE_CALENDAR_DST_INVALID', '工作时段落在不存在的夏令时时间，请配置当日特殊时段。', 409);
        }
        // 重复的墙上时间存在两个可能 offset，要求日期覆盖而不暗选任意一个。
        $utc = $value->getTimestamp();
        foreach ($zone->getTransitions($utc - 86400, $utc + 86400) ?: [] as $transition) {
            $alternate = new DateTimeImmutable('@'.($utc + $value->getOffset() - $transition['offset']));
            if ($alternate->getTimestamp() !== $utc && $alternate->setTimezone($zone)->format('Y-m-d H:i') === $text) {
                throw new SceneWorkflowException('SCENE_CALENDAR_DST_INVALID', '工作时段边界落在重复的夏令时时间，请避开该边界。', 409);
            }
        }

        return $value;
    }
}
