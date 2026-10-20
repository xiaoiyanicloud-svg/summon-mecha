<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Services\Ai\AiAnalyticsIngestService;

class AiAnalyticsController extends APIController
{
    public function __construct(private readonly AiAnalyticsIngestService $analytics) {}

    public function store(Request $request): JsonResponse
    {
        $events = (array) config('ai_analytics.allowed_events', []);
        $maxBatch = (int) config('ai_analytics.max_batch', 20);

        $validator = Validator::make($request->all(), [
            'events'                   => ['required', 'array', 'min:1', 'max:'.$maxBatch],
            'events.*.event_id'        => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'events.*.event_name'      => ['required', 'string', Rule::in($events)],
            'events.*.occurred_at'     => ['required', 'date'],
            'events.*.properties'      => ['sometimes', 'array', 'max:20'],
        ]);

        if ($validator->fails()) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '埋点参数不合法。',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        $customerId = auth()->guard('customer')->id();
        $guestKey = trim((string) $request->header('X-Guest-Key', ''));
        $accepted = $this->analytics->ingest(
            $customerId ? (int) $customerId : null,
            $guestKey !== '' ? mb_substr($guestKey, 0, 128) : null,
            (array) $request->input('events', []),
        );

        return response()->json([
            'success'  => true,
            'accepted' => $accepted,
        ])->header('Cache-Control', 'no-store, max-age=0');
    }
}
