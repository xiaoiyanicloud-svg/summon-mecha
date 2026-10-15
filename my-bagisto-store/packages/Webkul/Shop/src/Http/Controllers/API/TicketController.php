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
            // A9 · category 收敛成枚举(此前只 max:64,前端传什么都落库,后台"类别"列变成自由文本)
            'category' => ['nullable', 'in:'.implode(',', array_keys(SupportTicket::CATEGORY_LABELS))],
            'content'  => ['required', 'string', 'max:2000'],
            'skill_id' => ['nullable', 'integer'],
            // A9 · 接附件:ticket_message.attachments 列一直在,store 却完全不接收。
            //   ⚠ 这里收的是**链接**(与事故报案 evidence 同一姿态):平台暂无面向用户的
            //     对象存储 + 内容安全扫描,直接开一个无人看守的文件上传口子,风险大于收益。
            //     等 COS + msgSecCheck 就绪后再把"文件"补上,那时改这里的语义。
            'attachments'   => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['string', 'max:500'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);

        // A4 · 授权边界:skill_id 必须落在**当前客户**的授权里。
        //   与 device_id 的校验同源(见 IncidentController)。不校验的话,
        //   "工单/事故挂到无关技能上"就没有任何一道门 —— 而事故证据链正是按 skill_id 固化的。
        if (! empty($payload['skill_id']) && ! $this->ownsSkill($customer->id, (int) $payload['skill_id'])) {
            return $this->fail(ApiErrorCode::SKILL_NOT_OWNED, '该技能不在你的技能列表中。', 422);
        }

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
     * @param  array{category?:string|null,content:string,skill_id?:int|null,attachments?:array|null}  $payload
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
                        'category'        => $payload['category'] ?? SupportTicket::CATEGORY_OTHER,
                        'priority'        => 'normal',
                        'status'          => SupportTicket::STATUS_OPEN,
                        'sla_due_at'      => now()->addHours(24),
                    ]);

                    TicketMessage::create([
                        'ticket_id'   => $ticket->id,
                        'actor_type'  => 'customer',
                        'actor_id'    => $customerId,
                        'content'     => $payload['content'],
                        // A9 · 首条留言带上附件链接(设备无则 null,列是 JSON)
                        'attachments' => ! empty($payload['attachments']) ? array_values($payload['attachments']) : null,
                        'created_at'  => now(),
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
     * 该客户是否持有此技能(product_id)的授权。A4。
     */
    protected function ownsSkill(int $customerId, int $productId): bool
    {
        return DB::table('skill_entitlements')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->exists();
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

        // B-10:若这张工单已被定性为事故,如实告诉当事人(编号 + 状态)。
        //   此前工单与事故零互引,用户"投诉完就没下文",看不到平台把它转成了事故处理。
        $incident = \Webkul\Shop\Models\Incident::query()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => [
                'id'        => $ticket->id,
                'ticket_no' => $ticket->ticket_no,
                'category'  => $ticket->category,
                'status'    => $ticket->status,
                'incident'  => $incident ? [
                    'id'             => $incident->id,
                    'incident_no'    => $incident->incident_no,
                    'severity'       => $incident->severity,
                    'severity_label' => $incident->severityLabel(),
                    'status'         => $incident->status,
                    'status_label'   => $incident->statusLabel(),
                ] : null,
                'messages'  => $ticket->messages->map(fn (TicketMessage $m) => [
                    'id'          => $m->id,
                    'actor_type'  => $m->actor_type,
                    'content'     => $m->content,
                    'attachments' => $m->attachments ?? [],
                    'created_at'  => optional($m->created_at)->toDateTimeString(),
                ])->values(),
            ],
        ]);
    }
}
