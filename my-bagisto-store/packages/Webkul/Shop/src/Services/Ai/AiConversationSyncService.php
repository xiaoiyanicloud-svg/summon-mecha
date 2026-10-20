<?php

namespace Webkul\Shop\Services\Ai;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\AiConversation;

class AiConversationSyncService
{
    /**
     * Merge client state into the server snapshot. The latest client_updated_at wins;
     * tombstones remain authoritative for 90 days so an offline stale device cannot
     * resurrect a conversation that another device already deleted.
     *
     * @param  array<int, array<string, mixed>>  $conversations
     * @return array{conversations: array<int, array<string, mixed>>, server_time: string}
     */
    public function sync(int $customerId, array $conversations): array
    {
        DB::transaction(function () use ($customerId, $conversations): void {
            foreach ($conversations as $incoming) {
                $this->mergeOne($customerId, $incoming);
            }

            AiConversation::onlyTrashed()
                ->where('customer_id', $customerId)
                ->where('deleted_at', '<', now()->subDays((int) config('ai_conversations.tombstone_days', 90)))
                ->forceDelete();
        });

        return [
            'conversations' => $this->snapshot($customerId),
            'server_time'   => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(int $customerId): array
    {
        $cutoff = now()->subDays((int) config('ai_conversations.tombstone_days', 90));

        return AiConversation::withTrashed()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('deleted_at')->orWhere('deleted_at', '>=', $cutoff);
            })
            ->orderByDesc('client_updated_at')
            ->limit((int) config('ai_conversations.max_conversations', 50) * 2)
            ->get()
            ->map(fn (AiConversation $conversation): array => [
                'client_id'         => $conversation->client_id,
                'title'             => $conversation->title,
                'messages'          => $conversation->deleted_at ? [] : ($conversation->messages ?: []),
                'client_updated_at' => (int) $conversation->client_updated_at,
                'deleted_at'        => $conversation->deleted_at?->toIso8601String(),
                'updated_at'        => $conversation->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    private function mergeOne(int $customerId, array $incoming): void
    {
        $clientId = (string) $incoming['client_id'];
        $incomingUpdatedAt = (int) $incoming['client_updated_at'];
        $deleted = (bool) ($incoming['deleted'] ?? false);

        $conversation = AiConversation::withTrashed()
            ->where('customer_id', $customerId)
            ->where('client_id', $clientId)
            ->lockForUpdate()
            ->first();

        if ($conversation) {
            $serverUpdatedAt = (int) $conversation->client_updated_at;

            if ($serverUpdatedAt > $incomingUpdatedAt) {
                return;
            }

            // On a tie, deletion wins over stale active state.
            if ($serverUpdatedAt === $incomingUpdatedAt && $conversation->trashed() && ! $deleted) {
                return;
            }
        } else {
            $conversation = new AiConversation([
                'customer_id' => $customerId,
                'client_id'   => $clientId,
            ]);
        }

        $conversation->title = mb_substr(trim((string) ($incoming['title'] ?? '')), 0, 191);
        $conversation->messages = $deleted ? [] : $this->normalizeMessages((array) ($incoming['messages'] ?? []));
        $conversation->client_updated_at = $incomingUpdatedAt;
        $conversation->deleted_at = $deleted ? ($conversation->deleted_at ?: now()) : null;
        $conversation->save();
    }

    /**
     * Keep only durable conversation fields. Transient generation flags,
     * playback state and raw reasoning are intentionally not synced.
     *
     * @param  array<int, mixed>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMessages(array $messages): array
    {
        $maxMessages = (int) config('ai_conversations.max_messages', 100);
        $maxChars = (int) config('ai_conversations.max_message_chars', 5000);

        return array_values(array_map(function ($message) use ($maxChars): array {
            $message = is_array($message) ? $message : [];

            return [
                'role'        => ($message['role'] ?? '') === 'ai' ? 'ai' : 'user',
                'text'        => mb_substr((string) ($message['text'] ?? ''), 0, $maxChars),
                'scene'       => $this->safeArray($message['scene'] ?? null),
                'machines'    => $this->safeArray($message['machines'] ?? []),
                'skills'      => $this->safeArray($message['skills'] ?? []),
                'accessories' => $this->safeArray($message['accessories'] ?? []),
                'followUps'   => $this->safeArray($message['followUps'] ?? []),
            ];
        }, array_slice($messages, -$maxMessages)));
    }

    /**
     * @return array<mixed>|null
     */
    private function safeArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false || strlen($json) > 12000 ? null : $value;
    }
}
