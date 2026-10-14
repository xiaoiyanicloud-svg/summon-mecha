<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Shop\Models\Notification;

/**
 * 通知中心 —— 用户侧(v3.4 步骤 4)。
 */
class NotificationController extends APIController
{
    /**
     * 我的通知列表(默认只给最近 50 条)。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $rows = Notification::query()
            ->where('recipient_type', 'customer')
            ->where('recipient_id', $customer->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'unread' => $rows->whereNull('read_at')->count(),
            'data'   => $rows->map(fn (Notification $n) => [
                'id'        => $n->id,
                'type'      => $n->type,
                'title'     => $n->title,
                'content'   => $n->content,
                'payload'   => $n->payload,
                'channel'   => $n->channel,
                'read'      => $n->read_at !== null,
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
            return response()->json(['message' => '通知不存在。'], 404);
        }

        if (! $n->read_at) {
            $n->read_at = now();
            $n->save();
        }

        return response()->json(['message' => '已读。']);
    }
}
