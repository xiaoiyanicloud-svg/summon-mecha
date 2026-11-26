<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\Notification;

/**
 * 通知中心 —— 用户侧(v3.4 步骤 4 升级：支持分类过滤、游标加载、一键全部已读)。
 */
class NotificationController extends APIController
{
    /**
     * 我的通知列表(默认最新 50 条，支持 ?category= 与 ?before_id=)。
     */
    public function index(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $baseQuery = Notification::query()
            ->where('recipient_type', 'customer')
            ->where('recipient_id', $customer->id);

        $unreadTotal = (clone $baseQuery)->whereNull('read_at')->count();

        $query = clone $baseQuery;
        $category = trim((string) $request->query('category', ''));
        if ($category !== '' && $category !== 'all') {
            $query->where(function ($q) use ($category) {
                if ($category === 'order') {
                    $q->where('type', 'like', '%order%')
                        ->orWhere('type', 'like', '%payment%')
                        ->orWhere('type', 'like', '%refund%')
                        ->orWhere('type', 'like', '%after_sale%')
                        ->orWhere('type', 'like', '%invoice%');
                } elseif (in_array($category, ['device_skill', 'deploy'], true)) {
                    $q->where('type', 'like', '%skill%')
                        ->orWhere('type', 'like', '%deploy%')
                        ->orWhere('type', 'like', '%trial%')
                        ->orWhere('type', 'like', '%device%')
                        ->orWhere('type', 'like', '%agent%');
                } elseif (in_array($category, ['ecosystem', 'cooperation'], true)) {
                    $q->where('type', 'like', '%event%')
                        ->orWhere('type', 'like', '%registration%')
                        ->orWhere('type', 'like', '%competition%')
                        ->orWhere('type', 'like', '%coop%')
                        ->orWhere('type', 'like', '%supplier%');
                } elseif ($category === 'system') {
                    $q->where('type', 'like', '%ticket%')
                        ->orWhere('type', 'like', '%incident%')
                        ->orWhere('type', 'like', '%system%');
                }
            });
        }

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $beforeId = (int) $request->query('before_id', 0);
        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        $limit = min(50, max(1, (int) $request->query('limit', 50)));
        $rows = $query->orderByDesc('id')->limit($limit)->get();
        $nextCursor = $rows->count() === $limit ? $rows->last()?->id : null;

        return response()->json([
            'unread'      => $unreadTotal,
            'next_cursor' => $nextCursor,
            'meta'        => [
                'unread_count' => $unreadTotal,
                'has_more'     => $nextCursor !== null,
                'next_cursor'  => $nextCursor,
            ],
            'data'        => $rows->map(fn (Notification $n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'category'   => $this->inferCategory($n->type),
                'title'      => $n->title,
                'content'    => $n->content,
                'payload'    => $n->payload,
                'target_url' => $this->resolveTargetUrl($n),
                'channel'    => $n->channel,
                'read'       => $n->read_at !== null,
                'created_at' => optional($n->created_at)->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * 标记一条通知已读。
     */
    public function markRead(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $n = Notification::query()
            ->where('recipient_type', 'customer')
            ->where('recipient_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $n) {
            return $this->fail(ApiErrorCode::NOTIFICATION_NOT_FOUND, '通知不存在。', 404);
        }

        if (! $n->read_at) {
            $n->read_at = now();
            $n->save();
        }

        return response()->json(['message' => '已读。']);
    }

    /**
     * POST /api/notifications/read-all —— 一键标记全部未读通知为已读。
     */
    public function markAllRead(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $updated = Notification::query()
            ->where('recipient_type', 'customer')
            ->where('recipient_id', $customer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => '已全部标记为已读。',
            'data'    => ['updated' => (int) $updated],
        ]);
    }

    protected function inferCategory(?string $type): string
    {
        $t = strtolower((string) $type);
        if (str_contains($t, 'order') || str_contains($t, 'payment') || str_contains($t, 'refund') || str_contains($t, 'after_sale') || str_contains($t, 'invoice')) {
            return 'order';
        }
        if (str_contains($t, 'skill') || str_contains($t, 'deploy') || str_contains($t, 'trial') || str_contains($t, 'device') || str_contains($t, 'agent')) {
            return 'device_skill';
        }
        if (str_contains($t, 'event') || str_contains($t, 'registration') || str_contains($t, 'competition') || str_contains($t, 'coop')) {
            return 'ecosystem';
        }

        return 'system';
    }

    protected function resolveTargetUrl(Notification $n): ?string
    {
        $payload = is_array($n->payload) ? $n->payload : [];
        $type = strtolower((string) $n->type);

        if (! empty($payload['task_no'])) {
            return '/pages/mine/deploy?taskNo='.rawurlencode((string) $payload['task_no']);
        }
        if (! empty($payload['ticket_id'])) {
            return '/pages/mine/ticket-detail?id='.(int) $payload['ticket_id'];
        }
        if (! empty($payload['incident_id'])) {
            return '/pages/mine/incident-detail?id='.(int) $payload['incident_id'];
        }
        if (! empty($payload['after_sale_id']) || str_contains($type, 'after_sale') || str_contains($type, 'refund')) {
            return '/pages/mine/after-sales';
        }
        if (! empty($payload['order_id'])) {
            return '/pages/order/order-detail?id='.(int) $payload['order_id'];
        }
        if (str_contains($type, 'coop') || str_contains($type, 'supplier')) {
            return str_contains($type, 'supplier') ? '/pages/mine/supplier?tab=history' : '/pages/mine/cooperate?tab=history';
        }
        if (str_contains($type, 'registration') || str_contains($type, 'competition') || str_contains($type, 'event')) {
            return '/pages/ecosystem/my-registrations';
        }
        if (str_contains($type, 'skill') || str_contains($type, 'trial') || str_contains($type, 'deploy')) {
            return '/pages/mine/skills';
        }
        if (str_contains($type, 'device') || str_contains($type, 'agent')) {
            return '/pages/mine/devices';
        }

        return null;
    }
}
