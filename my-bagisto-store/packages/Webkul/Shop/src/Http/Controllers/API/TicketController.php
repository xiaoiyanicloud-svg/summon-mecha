<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

/**
 * 客服工单 —— 用户侧(v3.4 步骤 7)。
 *
 * 用户提工单(装不上/下发失败/退款等),平台/开发者处理。本次实现"用户开单 + 查看 + 追加留言";
 * 平台侧处理界面属第 10 步(平台端后台)。
 */
class TicketController extends APIController
{
    /**
     * 我的工单列表。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $rows = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (SupportTicket $t) => [
                'id'         => $t->id,
                'ticket_no'  => $t->ticket_no,
                'category'   => $t->category,
                'status'     => $t->status,
                'created_at' => optional($t->created_at)->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * 开一张工单(可带首条留言)。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'category' => ['nullable', 'string', 'max:64'],
            'content'  => ['required', 'string', 'max:2000'],
            'skill_id' => ['nullable', 'integer'],
        ]);

        $ticket = SupportTicket::create([
            'ticket_no'    => 'T'.now()->format('ymdHis').$customer->id,
            'customer_id'  => $customer->id,
            'skill_id'     => $payload['skill_id'] ?? null,
            'channel'      => 'app',
            'category'     => $payload['category'] ?? 'other',
            'priority'     => 'normal',
            'status'       => SupportTicket::STATUS_OPEN,
            'sla_due_at'   => now()->addHours(24),
        ]);

        TicketMessage::create([
            'ticket_id'  => $ticket->id,
            'actor_type' => 'customer',
            'actor_id'   => $customer->id,
            'content'    => $payload['content'],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => '工单已提交。',
            'data'    => ['id' => $ticket->id, 'ticket_no' => $ticket->ticket_no],
        ], 201);
    }

    /**
     * 工单详情(含消息流)。
     */
    public function show(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $ticket = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在。', 404);
        }

        return response()->json([
            'data' => [
                'id'        => $ticket->id,
                'ticket_no' => $ticket->ticket_no,
                'category'  => $ticket->category,
                'status'    => $ticket->status,
                'messages'  => $ticket->messages->map(fn (TicketMessage $m) => [
                    'id'         => $m->id,
                    'actor_type' => $m->actor_type,
                    'content'    => $m->content,
                    'created_at' => optional($m->created_at)->toDateTimeString(),
                ])->values(),
            ],
        ]);
    }
}
