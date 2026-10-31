<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\Shop\Models\SupportAgentState;
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
            ->withCount([
                'messages as unread_count' => fn ($query) => $query
                    // 内部备注不是给用户的消息，既不算未读，也不能出现在摘要里。
                    ->whereNotIn('actor_type', [TicketMessage::ACTOR_CUSTOMER, TicketMessage::ACTOR_NOTE])
                    ->whereNull('read_at'),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        // 用户可见的最后一条消息（跳过内部备注）。用一次批量查询而不是关系，
        // 因为 latestOfMany 的 where 会作用在外层结果上，备注恰好是最后一条时会被整条吞掉。
        $lastMessages = $rows->isEmpty()
            ? collect()
            : TicketMessage::query()
                ->whereIn('ticket_id', $rows->pluck('id'))
                ->where('actor_type', '<>', TicketMessage::ACTOR_NOTE)
                ->orderByDesc('id')
                ->get()
                ->unique('ticket_id')
                ->keyBy('ticket_id');

        return response()->json([
            'data' => $rows->map(function (SupportTicket $t) use ($lastMessages) {
                $lastMessage = $lastMessages->get($t->id);

                return [
                    'id'             => $t->id,
                    'ticket_no'      => $t->ticket_no,
                    'category'       => $t->category,
                    'category_label' => $t->categoryLabel(),
                    'service_mode'   => $t->service_mode,
                    'status'         => $t->status,
                    'status_label'   => $t->serviceStatusLabel(),
                    'held'           => $t->isHeld(),
                    'unread_count'   => (int) $t->unread_count,
                    'rating'         => $t->rating,
                    'queued_at'      => optional($t->queued_at)->toDateTimeString(),
                    'accepted_at'    => optional($t->accepted_at)->toDateTimeString(),
                    'created_at'     => optional($t->created_at)->toDateTimeString(),
                    'updated_at'     => optional($t->updated_at)->toDateTimeString(),
                    'last_message'   => $lastMessage ? $this->messagePayload($lastMessage, $t->updated_at) : null,
                ];
            })->values(),
        ]);
    }

    /**
     * 开一张工单（可带首条留言）；若该客户已有进行中的会话，则把消息续接进去。
     *
     * ── 一个客户一张进行中会话（2026-10-27 拍板） ──
     *   此前每点一次发送就新建一张单，同一客户可以同时挂 4 张，后台左栏一个客户占四行。
     *   现在 `createOrResumeTicket()` 会先找该客户 open/processing 的会话：
     *   有则续写，无则新建。响应里 `data.resumed=true` 表示这次没有新建工单。
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
            'attachments.*' => ['required'],
            'service_mode'  => ['nullable', 'in:'.SupportTicket::SERVICE_ASYNC.','.SupportTicket::SERVICE_ONLINE],
        ]);

        $payload['attachments'] = $this->normalizeAttachments($payload['attachments'] ?? []);

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

        [$ticket, $replayed, $resumed] = $this->createOrResumeTicket($customer->id, $payload, $idempotencyKey);

        return $this->ticketResponse($ticket, $replayed, $resumed);
    }

    /**
     * 发起在线咨询。独立入口让前端不必自己声明可信的在线模式。
     */
    public function online(Request $request): JsonResponse
    {
        $request->merge(['service_mode' => SupportTicket::SERVICE_ONLINE]);

        return $this->store($request);
    }

    /**
     * 当前在线客服能力。只把 60 秒内有心跳且主动开启接待的客服算作在线。
     */
    public function onlineStatus(): JsonResponse
    {
        $agents = $this->onlineAgentCount();
        $queued = SupportTicket::query()
            ->where('service_mode', SupportTicket::SERVICE_ONLINE)
            ->where('status', SupportTicket::STATUS_OPEN)
            ->count();

        return response()->json([
            'data' => [
                'online'           => $agents > 0,
                'agents_online'    => $agents,
                'queue_size'       => $queued,
                'heartbeat_ttl'    => SupportAgentState::ONLINE_TTL_SECONDS,
                'fallback_message' => $agents > 0
                    ? '客服在线，发送问题后会进入实时队列。'
                    : '当前没有客服在线，仍可提交在线咨询并排队，客服上线后会接入处理。',
            ],
        ]);
    }

    /**
     * 用户「正在输入」心跳。
     *
     * 只写 8 秒 TTL 的缓存，不落库：输入中是一个瞬时状态，写表会让每次按键
     * 都变成一次数据库写入，而这个信号的价值只有几秒。
     */
    public function typing(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $ticket = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在。', 404);
        }

        if ($ticket->status !== SupportTicket::STATUS_CLOSED) {
            $name = trim((string) ($customer->first_name ?? '').' '.(string) ($customer->last_name ?? ''));

            Cache::put(
                SupportTicket::typingCacheKey($ticket->id, 'customer'),
                ['id' => $customer->id, 'name' => $name !== '' ? $name : '用户'],
                now()->addSeconds(SupportTicket::TYPING_TTL_SECONDS)
            );
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * 客服是否正在输入（由后台的输入心跳写入）。
     */
    protected function staffIsTyping(int $ticketId): bool
    {
        return Cache::has(SupportTicket::typingCacheKey($ticketId, 'staff'));
    }

    /**
     * 增量同步：只返回 after_message_id 之后的新消息与会话状态。
     */
    public function updates(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();
        $afterId = max(0, (int) $request->query('after_message_id', 0));

        $ticket = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在。', 404);
        }

        $messages = $ticket->messages()
            ->where('actor_type', '<>', TicketMessage::ACTOR_NOTE)
            ->where('id', '>', $afterId)
            ->get()
            ->map(fn (TicketMessage $message) => $this->messagePayload($message, $ticket->created_at))
            ->values();

        return response()->json([
            'data' => array_merge($this->ticketStatePayload($ticket), [
                'messages'        => $messages,
                // 用户侧的“最后一条”只算可见消息：内部备注不能把游标推过用户还没看到的内容。
                'last_message_id' => $ticket->messages()
                    ->where('actor_type', '<>', TicketMessage::ACTOR_NOTE)
                    ->max('id'),
                // 已读回执会变（先送达、后已读），但消息本身不会重新出现在 after_message_id 之后。
                // 单独下发一份「用户消息的已读状态」，前端据此更新已有气泡。
                'receipts'        => $ticket->messages()
                    ->where('actor_type', TicketMessage::ACTOR_CUSTOMER)
                    ->whereNotNull('read_at')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get(['id', 'read_at'])
                    ->map(fn (TicketMessage $message) => [
                        'id'      => $message->id,
                        'read_at' => optional($message->read_at)->toDateTimeString(),
                    ])
                    ->values(),
                'server_time'     => now()->toDateTimeString(),
            ]),
        ]);
    }

    /**
     * 会话满意度评价（CSAT）。
     *
     * 只允许对该客户自己的、已结单（已解决/已关闭）且从未评价过的会话打一次分。
     * 允许改评会让「当时那次服务的真实感受」失真，所以第二次直接 409。
     */
    public function rate(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'tags'    => ['nullable', 'array', 'max:6'],
            'tags.*'  => ['string', 'in:'.implode(',', array_keys(SupportTicket::RATING_TAG_LABELS))],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);

        $ticket = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在。', 404);
        }

        if ($ticket->rated_at !== null) {
            return $this->fail(ApiErrorCode::CONFLICT, '这张会话已经评价过了。', 409);
        }

        if (! $ticket->isRateable()) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, '会话结束后才能评价。', 422);
        }

        $comment = isset($payload['comment']) ? trim((string) $payload['comment']) : '';

        $ticket->forceFill([
            'rating'         => (int) $payload['rating'],
            'rating_tags'    => array_values(array_unique($payload['tags'] ?? [])),
            'rating_comment' => $comment !== '' ? $comment : null,
            'rated_at'       => now(),
        ])->save();

        return response()->json([
            'message' => '感谢评价，我们会把这条反馈带到复盘里。',
            'data'    => $this->ticketStatePayload($ticket->refresh()),
        ]);
    }

    /**
     * 用户已读客服/系统消息。
     */
    public function read(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $ticket = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在。', 404);
        }

        $count = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->whereNotIn('actor_type', [TicketMessage::ACTOR_CUSTOMER, TicketMessage::ACTOR_NOTE])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['marked_read' => $count]]);
    }

    /**
     * 用户主动结束会话。重复结束保持幂等。
     */
    public function end(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $ticket = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在。', 404);
        }

        if ($ticket->status !== SupportTicket::STATUS_CLOSED) {
            DB::transaction(function () use ($ticket) {
                $ticket->status = SupportTicket::STATUS_CLOSED;
                $ticket->ended_at = now();
                $ticket->held_at = null;
                $ticket->held_reason = null;
                $ticket->save();

                TicketMessage::create([
                    'ticket_id'  => $ticket->id,
                    'actor_type' => 'system',
                    'actor_id'   => null,
                    'content'    => '用户已结束本次会话。',
                    'created_at' => now(),
                    'read_at'    => now(),
                ]);
            });
        }

        return response()->json([
            'data' => [
                'id'           => $ticket->id,
                'status'       => SupportTicket::STATUS_CLOSED,
                'status_label' => '已关闭',
                'ended_at'     => optional($ticket->ended_at)->toDateTimeString(),
            ],
        ]);
    }

    /**
     * 用户在既有会话里继续发言。
     *
     * `resolved` 的单允许继续发送并自动重新打开；`closed` 是明确终止态，
     * 必须新建会话。这样用户不必为补充一句截图再开一张重复工单。
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $ticket = SupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在。', 404);
        }

        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            return $this->fail(ApiErrorCode::CONFLICT, '该会话已关闭，请新建会话。', 409);
        }

        $payload = $request->validate([
            'content'       => ['required', 'string', 'max:2000'],
            'attachments'   => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['required'],
            'service_mode'  => ['nullable', 'in:'.SupportTicket::SERVICE_ASYNC.','.SupportTicket::SERVICE_ONLINE],
        ]);

        $payload['attachments'] = $this->normalizeAttachments($payload['attachments'] ?? []);

        // 已解决会话要重新打开之前，先看该客户是否已有另一张进行中会话。
        // 有的话把这次补充转投过去 —— 否则会同时出现「重开的旧单 + 新单」两张 active，
        // 又把“一个客户一张进行中”的约束打破。
        if ($ticket->status === SupportTicket::STATUS_RESOLVED) {
            $active = SupportTicket::query()
                ->where('customer_id', $customer->id)
                ->where('id', '<>', $ticket->id)
                ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            if ($active) {
                $forwarded = DB::transaction(function () use ($active, $customer, $payload) {
                    $locked = SupportTicket::lockForUpdate()->find($active->id);

                    if (! $locked || ! in_array($locked->status, [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING], true)) {
                        return null;
                    }

                    return $this->appendCustomerMessage(
                        $locked,
                        $customer->id,
                        trim($payload['content']),
                        $payload['attachments'] ?? null
                    );
                });

                if ($forwarded) {
                    Cache::forget(SupportTicket::typingCacheKey($active->id, 'customer'));

                    return response()->json([
                        'message' => '你有一个进行中的会话，消息已发送到该会话。',
                        'data'    => [
                            'ticket_id'          => $active->id,
                            'redirect_ticket_id' => $active->id,
                            'status'             => $active->status,
                            'message'            => $this->messagePayload($forwarded),
                        ],
                    ], 201);
                }
            }
        }

        $message = DB::transaction(function () use ($ticket, $customer, $payload) {
            $message = TicketMessage::create([
                'ticket_id'   => $ticket->id,
                'actor_type'  => 'customer',
                'actor_id'    => $customer->id,
                'content'     => trim($payload['content']),
                'attachments' => ! empty($payload['attachments']) ? array_values($payload['attachments']) : null,
                'created_at'  => now(),
            ]);

            $ticket->last_customer_message_at = now();
            $ticket->ended_at = null;

            // 用户回复 = 球回到客服这边，挂起自动解除。
            // 不解除的话，会话会永远留在「已挂起」里，SLA 也永远不开始计算。
            $ticket->held_at = null;
            $ticket->held_reason = null;

            if ($ticket->status === SupportTicket::STATUS_RESOLVED) {
                $ticket->sla_due_at = now()->addHours(24);

                if ($ticket->service_mode === SupportTicket::SERVICE_ONLINE && ! $ticket->assignee_id) {
                    $ticket->status = SupportTicket::STATUS_OPEN;
                    $ticket->queued_at = now();
                } else {
                    $ticket->status = SupportTicket::STATUS_PROCESSING;
                }
            }

            // 无论状态是否变化都要 save：updated_at 驱动会话列表按最近发言排序。
            $ticket->save();

            return $message;
        });

        // 发出消息后立刻收掉「正在输入」，不用等 TTL 自然过期。
        Cache::forget(SupportTicket::typingCacheKey($ticket->id, 'customer'));

        return response()->json([
            'message' => '消息已发送。',
            'data'    => [
                'ticket_id' => $ticket->id,
                'status'    => $ticket->status,
                'message'   => $this->messagePayload($message),
            ],
        ], 201);
    }

    /**
     * 建单或续接到当前进行中的会话（同一事务）。
     *
     * 2026-10-27 拍板：**一个客户同时只允许一张 open/processing 会话**。
     * 客户再点一次“发送”时，如果已有进行中的会话，就把消息续写进去，
     * 不再开第二张单 —— 否则后台左栏一个客户会占多行，客服在几十人咨询时
     * 分不清哪些是同一个人的同一个问题。
     *
     * 并发控制：先锁客户行，再查进行中会话。两个并发请求不会同时判断“没有进行中”
     * 然后各建一张单。撞唯一键时仍按原逻辑重试/幂等回放。
     *
     * @param  array{category?:string|null,content:string,skill_id?:int|null,attachments?:array<int,string|array<string,mixed>>|null,service_mode?:string|null}  $payload
     * @return array{0:SupportTicket,1:bool,2:bool}  [工单, 是否幂等回放, 是否续接已有会话]
     */
    protected function createOrResumeTicket(int $customerId, array $payload, ?string $idempotencyKey): array
    {
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($customerId, $payload, $idempotencyKey) {
                    // 锁客户行：同一客户的“查进行中 + 建单”必须串行，否则并发双开。
                    DB::table('customers')->where('id', $customerId)->lockForUpdate()->first();

                    // 锁内再查一次幂等键：并发同键请求只有一个能走到建单。
                    if ($idempotencyKey !== null) {
                        $existing = $this->findByIdempotencyKey($customerId, $idempotencyKey);

                        if ($existing) {
                            return [$existing, true, false];
                        }
                    }

                    $active = SupportTicket::query()
                        ->where('customer_id', $customerId)
                        ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
                        ->orderByDesc('updated_at')
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();

                    if ($active) {
                        return [$this->appendToActiveTicket($active, $customerId, $payload), false, true];
                    }

                    $ticket = SupportTicket::create([
                        'ticket_no'       => $this->generateTicketNo($customerId),
                        'idempotency_key' => $idempotencyKey,
                        'customer_id'     => $customerId,
                        'skill_id'        => $payload['skill_id'] ?? null,
                        'channel'         => 'app',
                        'service_mode'    => $payload['service_mode'] ?? SupportTicket::SERVICE_ASYNC,
                        'category'        => $payload['category'] ?? SupportTicket::CATEGORY_OTHER,
                        'priority'        => 'normal',
                        'status'          => SupportTicket::STATUS_OPEN,
                        'sla_due_at'      => now()->addHours(24),
                        'queued_at'       => ($payload['service_mode'] ?? SupportTicket::SERVICE_ASYNC) === SupportTicket::SERVICE_ONLINE ? now() : null,
                        'last_customer_message_at' => now(),
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

                    return [$ticket, false, false];
                });
            } catch (QueryException $e) {
                if (! $this->isDuplicateKey($e) || ++$attempts >= 5) {
                    throw $e;
                }

                // 幂等键冲突 = 并发的同键请求已经建好了 → 取回它,按幂等返回
                if ($idempotencyKey !== null) {
                    $existing = $this->findByIdempotencyKey($customerId, $idempotencyKey);

                    if ($existing) {
                        return [$existing, true, false];
                    }
                }

                // 否则是单号撞了 → 下一轮换一个号
            }
        }
    }

    /**
     * 把首条消息续接到进行中的会话。
     *
     * 通道升级只发生在**还没人接待的留言单**上：用户这次选了在线客服，
     * 而旧单还是一张没人动的留言，升级成在线并重新进队才符合“现在要人工”的意图。
     * 已经有人接待的留言单不升级 —— 否则会把它变成“必须在线客服才能回复”，
     * 把正在处理的人挡在门外。
     *
     * @param  array{content:string,attachments?:array|null,service_mode?:string|null}  $payload
     */
    protected function appendToActiveTicket(SupportTicket $ticket, int $customerId, array $payload): SupportTicket
    {
        $requestedOnline = ($payload['service_mode'] ?? SupportTicket::SERVICE_ASYNC) === SupportTicket::SERVICE_ONLINE;

        if ($requestedOnline
            && $ticket->service_mode === SupportTicket::SERVICE_ASYNC
            && $ticket->status === SupportTicket::STATUS_OPEN
            && ! $ticket->assignee_id) {
            $ticket->service_mode = SupportTicket::SERVICE_ONLINE;
            $ticket->queued_at = $ticket->queued_at ?: now();
        }

        $this->appendCustomerMessage($ticket, $customerId, $payload['content'], $payload['attachments'] ?? null);

        return $ticket;
    }

    /**
     * 追加一条客户消息并同步会话时间/挂起状态。
     *
     * store 的续接、reply 的“转投当前会话”共用这一份写入逻辑，
     * 避免两处对 held_at / last_customer_message_at 的口径不一致。
     *
     * @param  array<int, string|array<string,mixed>>|null  $attachments
     */
    protected function appendCustomerMessage(SupportTicket $ticket, int $customerId, string $content, ?array $attachments = null): TicketMessage
    {
        $message = TicketMessage::create([
            'ticket_id'   => $ticket->id,
            'actor_type'  => 'customer',
            'actor_id'    => $customerId,
            'content'     => $content,
            'attachments' => ! empty($attachments) ? array_values($attachments) : null,
            'created_at'  => now(),
        ]);

        $ticket->last_customer_message_at = now();
        $ticket->ended_at = null;
        // 用户补充了信息，挂起自动解除（与 reply() 同一口径）。
        $ticket->held_at = null;
        $ticket->held_reason = null;
        // 必须 save：updated_at 驱动会话列表排序，也让后台“最久未回复”重新计算。
        $ticket->save();

        return $message;
    }

    protected function ticketResponse(SupportTicket $ticket, bool $replayed, bool $resumed = false): JsonResponse
    {
        $body = [
            'message' => $resumed
                ? '已续接到你当前的会话。'
                : ($ticket->service_mode === SupportTicket::SERVICE_ONLINE ? '在线咨询已发起。' : '工单已提交。'),
            'data'    => [
                'id'           => $ticket->id,
                'ticket_no'    => $ticket->ticket_no,
                'service_mode' => $ticket->service_mode,
                'status'       => $ticket->status,
                'status_label' => $ticket->serviceStatusLabel(),
                'resumed'      => $resumed,
            ],
        ];

        if ($replayed) {
            $body['idempotent'] = true;
        }

        // 新建 201；续接/幂等回放 200（这次没有新建工单）
        return response()->json($body, ($replayed || $resumed) ? 200 : 201);
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

        $incident = \Webkul\Shop\Models\Incident::query()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->first();

        $data = array_merge($this->ticketStatePayload($ticket), [
            'incident' => $incident ? [
                'id'             => $incident->id,
                'incident_no'    => $incident->incident_no,
                'severity'       => $incident->severity,
                'severity_label' => $incident->severityLabel(),
                'status'         => $incident->status,
                'status_label'   => $incident->statusLabel(),
            ] : null,
            'messages' => $ticket->messages()
                ->where('actor_type', '<>', TicketMessage::ACTOR_NOTE)
                ->get()
                ->map(fn (TicketMessage $message) => $this->messagePayload($message, $ticket->created_at))
                ->values(),
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * 会话状态快照。详情与增量同步共用同一份字段，避免两个接口各说一套状态。
     *
     * @return array<string, mixed>
     */
    protected function ticketStatePayload(SupportTicket $ticket): array
    {
        return [
            'id'                       => $ticket->id,
            'ticket_no'                => $ticket->ticket_no,
            'category'                 => $ticket->category,
            'category_label'           => $ticket->categoryLabel(),
            'service_mode'             => $ticket->service_mode,
            'status'                   => $ticket->status,
            'status_label'             => $ticket->serviceStatusLabel(),
            'held'                     => $ticket->isHeld(),
            'held_at'                  => optional($ticket->held_at)->toDateTimeString(),
            // 输入中状态不落库：8 秒没有新的按键就自动消失，避免客服关掉页面后
            // 用户端一直显示「正在输入」。
            'staff_typing'             => $this->staffIsTyping($ticket->id),
            'can_reply'                => $ticket->status !== SupportTicket::STATUS_CLOSED,
            'agent'                    => $this->agentPayload($ticket->assignee_id),
            'queued_at'                => optional($ticket->queued_at)->toDateTimeString(),
            'accepted_at'              => optional($ticket->accepted_at)->toDateTimeString(),
            'ended_at'                 => optional($ticket->ended_at)->toDateTimeString(),
            'last_customer_message_at' => optional($ticket->last_customer_message_at)->toDateTimeString(),
            'last_staff_message_at'    => optional($ticket->last_staff_message_at)->toDateTimeString(),
            'sla_due_at'               => optional($ticket->sla_due_at)->toDateTimeString(),
            'rating'                   => $ticket->rating,
            'rating_tags'              => $ticket->rating_tags ?? [],
            'rating_comment'           => $ticket->rating_comment,
            'rated_at'                 => optional($ticket->rated_at)->toDateTimeString(),
            'can_rate'                 => $ticket->isRateable(),
            'online_agents'            => $this->onlineAgentCount(),
        ];
    }

    /**
     * @return array{id:int,name:string}|null
     */
    protected function agentPayload(?int $adminId): ?array
    {
        if (! $adminId) {
            return null;
        }

        $admin = \Webkul\User\Models\Admin::query()
            ->select('id', 'name')
            ->find($adminId);

        return $admin ? [
            'id'   => $admin->id,
            'name' => $admin->name ?: '平台客服',
        ] : [
            'id'   => $adminId,
            'name' => '平台客服',
        ];
    }

    protected function onlineAgentCount(): int
    {
        return SupportAgentState::query()->fresh()->count();
    }

    /**
     * 客服消息附件既兼容历史字符串链接，也支持结构化商品卡。
     * 商品卡只接受白名单字段，图片仅允许 http(s) 或站内相对地址，避免把任意协议塞进 img/src。
     *
     * @param  array<int, mixed>  $attachments
     * @return array<int, string|array<string, mixed>>
     */
    protected function normalizeAttachments(array $attachments): array
    {
        $normalized = [];

        foreach (array_values($attachments) as $index => $attachment) {
            if (is_string($attachment)) {
                $link = trim($attachment);

                if ($link === '' || mb_strlen($link) > 500) {
                    throw ValidationException::withMessages([
                        'attachments.'.$index => '附件链接不能为空且不能超过 500 个字符。',
                    ]);
                }

                $normalized[] = $link;
                continue;
            }

            if (! is_array($attachment) || ($attachment['type'] ?? null) !== 'product') {
                throw ValidationException::withMessages([
                    'attachments.'.$index => '附件格式不正确。',
                ]);
            }

            if (isset($attachment['price']) && is_numeric($attachment['price'])) {
                $attachment['price'] = (string) $attachment['price'];
            }

            $data = Validator::make($attachment, [
                'type'       => ['required', 'in:product'],
                'product_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:products,id'],
                'name'       => ['required', 'string', 'max:255'],
                'sku'        => ['nullable', 'string', 'max:128'],
                'price'      => ['nullable', 'string', 'max:32'],
                'image'      => ['nullable', 'string', 'max:1000'],
                'kind'       => ['nullable', 'in:rent,buy,accessory,skill'],
            ])->validate();

            $name = trim((string) $data['name']);
            if ($name === '') {
                throw ValidationException::withMessages([
                    'attachments.'.$index.'.name' => '商品名称不能为空。',
                ]);
            }

            $image = trim((string) ($data['image'] ?? ''));
            if ($image !== '' && ! preg_match('#^(https?://|/)#i', $image)) {
                $image = '';
            }

            $normalized[] = array_filter([
                'type'       => 'product',
                'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : null,
                'name'       => $name,
                'sku'        => trim((string) ($data['sku'] ?? '')),
                'price'      => trim((string) ($data['price'] ?? '')),
                'image'      => $image,
                'kind'       => $data['kind'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    protected function messagePayload(TicketMessage $message, ?\DateTimeInterface $fallback = null): array
    {
        $createdAt = $message->created_at ?? $fallback;

        return [
            'id'          => $message->id,
            'actor_type'  => $message->actor_type,
            'content'     => $message->content,
            'attachments' => $message->attachments ?? [],
            // 历史消息曾因 created_at 不可填而落库为空；展示时回退到工单时间，避免整段对话没有时间轴。
            'created_at'  => $createdAt?->toDateTimeString(),
            'read_at'     => optional($message->read_at)->toDateTimeString(),
        ];
    }
}