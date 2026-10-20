<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Services\Ai\AiConversationSyncService;

class AiConversationController extends APIController
{
    public function __construct(private readonly AiConversationSyncService $sync) {}

    public function sync(Request $request): JsonResponse
    {
        $customerId = (int) auth()->guard('customer')->id();
        if ($customerId < 1) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $maxConversations = (int) config('ai_conversations.max_conversations', 50);
        $maxMessages = (int) config('ai_conversations.max_messages', 100);
        $maxChars = (int) config('ai_conversations.max_message_chars', 5000);

        $validator = Validator::make($request->all(), [
            'conversations'                         => ['present', 'array', 'max:'.$maxConversations],
            'conversations.*.client_id'             => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'conversations.*.title'                 => ['nullable', 'string', 'max:191'],
            'conversations.*.messages'              => ['present', 'array', 'max:'.$maxMessages],
            'conversations.*.messages.*.role'       => ['required', 'string', Rule::in(['user', 'ai'])],
            'conversations.*.messages.*.text'       => ['nullable', 'string', 'max:'.$maxChars],
            'conversations.*.client_updated_at'     => ['required', 'integer', 'min:1', 'max:'.(now()->addMinutes(5)->getTimestampMs())],
            'conversations.*.deleted'               => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '对话同步参数不合法。',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        $conversations = (array) $request->input('conversations', []);
        $payloadBytes = strlen((string) json_encode($conversations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($payloadBytes > (int) config('ai_conversations.sync_payload_bytes', 1048576)) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '对话同步内容过大。',
                ['errors' => ['conversations' => ['单次同步内容超过上限。']]],
            );
        }

        $result = $this->sync->sync($customerId, $conversations);

        return response()->json([
            'success' => true,
            'message' => '同步完成。',
            'data'    => $result,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
