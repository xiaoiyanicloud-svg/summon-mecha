<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     *
     * ── A2 · 两个曾经会 500 的入口 ──
     *   ① **单号撞号**:单号曾是 `'T'.now()->format('ymdHis').$customer->id` ——
     *      同一客户同一秒点两下(弱网重试/双击是最常见的真实行为)就撞 `uk_support_ticket_no` → 500。
     *      现在号里带随机后缀,且唯一索引冲突会被捕获后重试。
     *   ② **重复提交**:客户端可带 `Idempotency-Key`,同一个键第二次直接返回首次结果,不再多开一张单。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'category' => ['nullable', 'string', 'max:64'],
            'content'  => ['required', 'string', 'max:2000'],
            'skill_id' => ['nullable', 'integer'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);

        // 幂等快路径:键已存在 → 直接返回首次结果(不发 201,因为这次什么都没创建)
        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($customer->id, $idempotencyKey);

            if ($existing) {
                return $this->ticketResponse($existing, replayed: true);
            }
        }

        [$ticket, $replayed] = $this->createTicket($customer->id, $payload, $idempotencyKey);

        return $this->ticketResponse($ticket, $replayed);
    }

    /**
     * 建单 + 首条留言(同一事务)。撞唯一键时重试。
     *
     * 重试能覆盖两类冲突:
     *   · `uk_support_ticket_no` —— 单号随机后缀撞了(极小概率),换一个号再来;
     *   · `uk_ticket_customer_idem` —— 并发的同键请求已经落了单,取回它按幂等返回。
     *
     * @param  array{category?:string|null,content:string,skill_id?:int|null}  $payload
     * @return array{0:SupportTicket,1:bool}  [工单, 是否为幂等回放]
     */
    protected function createTicket(int $customerId, array $payload, ?string $idempotencyKey): array
    {
        $attempts = 0;

        while (true) {
            try {
                $ticket = DB::transaction(function () use ($customerId, $payload, $idempotencyKey) {
                    $ticket = SupportTicket::create([
                        'ticket_no'       => $this->generateTicketNo($customerId),
                        'idempotency_key' => $idempotencyKey,
                        'customer_id'     => $customerId,
                        'skill_id'        => $payload['skill_id'] ?? null,
                        'channel'         => 'app',
                        'category'        => $payload['category'] ?? 'other',
                        'priority'        => 'normal',
                        'status'          => SupportTicket::STATUS_OPEN,
                        'sla_due_at'      => now()->addHours(24),
                    ]);

                    TicketMessage::create([
                        'ticket_id'  => $ticket->id,
                        'actor_type' => 'customer',
                        'actor_id'   => $customerId,
                        'content'    => $payload['content'],
                        'created_at' => now(),
                    ]);

                    return $ticket;
                });

                return [$ticket, false];
            } catch (QueryException $e) {
                if (! $this->isDuplicateKey($e) || ++$attempts >= 5) {
                    throw $e;
                }

                // 幂等键冲突 = 并发的同键请求已经建好了 → 取回它,按幂等返回
                if ($idempotencyKey !== null) {
                    $existing = $this->findByIdempotencyKey($customerId, $idempotencyKey);

                    if ($existing) {
                        return [$existing, true];
                    }
                }

                // 否则是单号撞了 → 下一轮换一个号
            }
        }
    }

    protected function ticketResponse(SupportTicket $ticket, bool $replayed): JsonResponse
    {
        $body = [
            'message' => '工单已提交。',
            'data'    => ['id' => $ticket->id, 'ticket_no' => $ticket->ticket_no],
        ];

        if ($replayed) {
            $body['idempotent'] = true;
        }

        // 首次创建 201;幂等回放 200(这次并没有新建资源)
        return response()->json($body, $replayed ? 200 : 201);
    }

    /**
     * 生成工单号。秒级时间戳(便于人工按时间检索)+ 客户号 + 随机后缀。
     *
     * ⚠ 随机后缀**不是**唯一性保证(唯一索引才是),它只是把同秒碰撞的概率压到可忽略,
     *   让"兜底重试"这条路径几乎不会走到。长度:`T`+12+客户号+`-`+6 ≤ 40,列够放。
     */
    protected function generateTicketNo(int $customerId): string
    {
        return 'T'.now()->format('ymdHis').$customerId.'-'.Str::upper(Str::random(6));
    }

    protected function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        // 存 sha256:键是客户端自造的不透明令牌,原文对我们没有意义,
        // 哈希后长度固定、内容可控,不用为"客户端传了超长/含特殊字符的键"做额外防御。
        return hash('sha256', trim($key));
    }

    protected function findByIdempotencyKey(int $customerId, string $hashedKey): ?SupportTicket
    {
        return SupportTicket::query()
            ->where('customer_id', $customerId)
            ->where('idempotency_key', $hashedKey)
            ->first();
    }

    /**
     * 唯一键冲突(MySQL 1062 / SQLite 唯一约束)。
     */
    protected function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
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
