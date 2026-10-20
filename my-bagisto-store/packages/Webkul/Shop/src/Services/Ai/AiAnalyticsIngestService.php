<?php

namespace Webkul\Shop\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\AiAnalyticsEvent;

class AiAnalyticsIngestService
{
    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function ingest(?int $customerId, ?string $guestKey, array $events): int
    {
        $allowed = array_flip((array) config('ai_analytics.allowed_events', []));
        $deviceHash = $guestKey ? hash('sha256', $guestKey) : null;
        $now = now();
        $rows = [];

        foreach ($events as $event) {
            $name = (string) ($event['event_name'] ?? '');
            if (! isset($allowed[$name])) {
                continue;
            }

            $eventId = (string) ($event['event_id'] ?? '');
            if ($eventId === '') {
                continue;
            }

            $properties = $this->sanitizeProperties((array) ($event['properties'] ?? []));
            $encoded = $properties === [] ? null : json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== null && strlen($encoded) > 16000) {
                continue;
            }

            try {
                $occurredAt = Carbon::parse((string) ($event['occurred_at'] ?? $now->toIso8601String()));
            } catch (\Throwable) {
                $occurredAt = $now;
            }

            if ($occurredAt->greaterThan($now->copy()->addMinutes(5))) {
                $occurredAt = $now;
            }

            $rows[] = [
                'event_id'    => mb_substr($eventId, 0, 64),
                'customer_id' => $customerId,
                'device_hash' => $deviceHash,
                'event_name'  => $name,
                'properties'  => $encoded,
                'occurred_at' => $occurredAt,
                'created_at'  => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        return AiAnalyticsEvent::query()->insertOrIgnore($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $days): array
    {
        $days = max(1, min(365, $days));
        $since = now()->subDays($days);

        $counts = AiAnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->select('event_name', DB::raw('COUNT(*) as total'))
            ->groupBy('event_name')
            ->orderByDesc('total')
            ->pluck('total', 'event_name')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $daily = AiAnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('DATE(occurred_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->day => (int) $row->total])
            ->all();

        $funnel = [];
        foreach (['ai_page_view', 'ai_prompt_submit', 'ai_first_token', 'ai_reply_done'] as $event) {
            $funnel[$event] = $counts[$event] ?? 0;
        }

        return [
            'days'   => $days,
            'since'  => $since->toIso8601String(),
            'counts' => $counts,
            'daily'  => $daily,
            'funnel' => $funnel,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitizeProperties(array $properties): array
    {
        $safe = [];

        foreach (array_slice($properties, 0, 20, true) as $key => $value) {
            $key = mb_substr((string) $key, 0, 64);

            if (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 200);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
