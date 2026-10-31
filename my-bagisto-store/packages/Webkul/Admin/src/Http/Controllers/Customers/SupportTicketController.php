<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Customers\SupportTicketDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SupportAgentState;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;
use Webkul\Shop\Services\Incident\IncidentService;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * 客服工单受理台（v3.4 步骤 10 的平台端侧）。
 *
 * 在此之前：用户侧能开单（`POST /api/tickets`，SLA 24h 已写库），
 * 但**平台端没有列表、没有详情、没有回复入口** —— 工单进了一个没人看的池子。
 * `TicketController` 的注释当时也如实写了"平台侧处理界面属第 10 步"。
 *
 * 职责边界（本轮）：
 *   - **平台受理**：平台客服接单、回复、改状态、指派给自己的同事。
 *   - **不派给开发者**：`support_ticket.developer_id` 字段在，但"把工单转给技能作者、
 *     作者在开发者门户里回复"这条链还没做（属第 9 步后段）。所以这里不做"转派给开发者"，
 *     免得出现一个按钮点了没反应的假功能。
 */
class SupportTicketController extends Controller
{
    public function __construct(
        protected OperationLogger $oplog,
        protected IncidentService $incidents,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(SupportTicketDataGrid::class)->process();
        }

        return view('admin::customers.tickets.index');
    }

    public function show(int $id)
    {
        $ticket = SupportTicket::with(['messages'])->find($id);

        if (! $ticket) {
            session()->flash('error', '工单不存在。');

            return redirect()->route('admin.customers.tickets.index');
        }

        $customer = $ticket->customer_id
            ? \Webkul\Customer\Models\Customer::find($ticket->customer_id)
            : null;

        // B-10:若本工单已被定性为事故,在详情页给出互链
        $incident = $ticket->incident;
        $currentAdmin = auth()->guard('admin')->user();
        $currentAdminId = $currentAdmin->id;
        $currentAdminName = $currentAdmin->name ?: '平台客服';
        $agentState = SupportAgentState::where('admin_id', $currentAdminId)->first();
        $onlineAgents = SupportAgentState::query()->fresh()->count();
        $inbox = $this->customerInbox($currentAdminId);
        $stats = $this->dashboardStats();
        $assigneeName = $ticket->assignee_id
            ? \Webkul\User\Models\Admin::query()->select('name')->find($ticket->assignee_id)?->name
            : null;
        $customerName = trim((string) ($customer?->first_name ?? '').' '.(string) ($customer?->last_name ?? ''));
        $customerName = $customerName !== '' ? $customerName : ($customer?->email ?? '游客');
        $historyTickets = $ticket->customer_id
            ? SupportTicket::query()
                ->where('customer_id', $ticket->customer_id)
                ->where('id', '<>', $ticket->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
            : collect();
        $agents = $this->assignableAgents($currentAdminId);
        // 在线会话只展示真正能接的客服；否则下拉框里全是 disabled 选项，看起来像坏了一个空白框。
        $transferableAgents = array_values(array_filter(
            $agents,
            fn (array $agent) => $ticket->service_mode !== SupportTicket::SERVICE_ONLINE || ! empty($agent['can_accept'])
        ));
        $quickReplies = $this->quickReplyGroups($ticket->category);
        $quickReplyStats = $this->quickReplyStats(7);

        $data = compact(
            'ticket',
            'customer',
            'incident',
            'agentState',
            'onlineAgents',
            'currentAdminId',
            'currentAdminName',
            'inbox',
            'stats',
            'assigneeName',
            'customerName',
            'historyTickets',
            'agents',
            'transferableAgents',
            'quickReplies',
            'quickReplyStats'
        );

        // embed=1：只回工作台主体，供左栏点击时无刷新替换；不套后台 layout。
        if (request()->boolean('embed')) {
            return response()
                ->view('admin::customers.tickets.partials.workspace', $data)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return view('admin::customers.tickets.show', $data);
    }

    /**
     * 工作台入口：没有指定工单时，自动跳到最该处理的一张会话。
     *
     * 顺序：我的接待 → 在线排队 → 留言待处理。没有可处理会话时回工单列表，
     * 不渲染一个空白的三栏工作台。
     */
    public function workbench(Request $request)
    {
        $adminId = auth()->guard('admin')->user()->id;
        $excludeId = (int) $request->query('exclude', 0);

        $ticket = SupportTicket::query()
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
            // 挂起中的会话在等客户补充，不该出现在「下一条待处理」里。
            ->whereNull('held_at')
            ->when($excludeId > 0, fn ($query) => $query->where('id', '<>', $excludeId))
            ->where(function ($query) use ($adminId) {
                $query->where('assignee_id', $adminId)
                    ->orWhere(function ($unassigned) {
                        $unassigned->whereNull('assignee_id')
                            ->where(function ($actionable) {
                                $actionable->where(function ($online) {
                                    $online->where('service_mode', SupportTicket::SERVICE_ONLINE)
                                        ->where('status', SupportTicket::STATUS_OPEN);
                                })->orWhere(function ($async) {
                                    $async->where('service_mode', SupportTicket::SERVICE_ASYNC)
                                        ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING]);
                                });
                            });
                    });
            })
            ->orderByRaw('CASE
                WHEN assignee_id = ? THEN 0
                WHEN service_mode = ? AND status = ? THEN 1
                ELSE 2
            END', [$adminId, SupportTicket::SERVICE_ONLINE, SupportTicket::STATUS_OPEN])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($ticket) {
            return redirect()->route('admin.customers.tickets.show', $ticket->id);
        }

        session()->flash('success', '当前没有待处理的客服会话。');

        return redirect()->route('admin.customers.tickets.index');
    }

    /**
     * 左侧客户收件箱（HTML 片段）。工作台每 3 秒拉一次，避免整页刷新。
     *
     * 注意：筛选/搜索控件在 show.blade 的静态区域，这里只替换“客户行”，
     * 否则每 3 秒重绘会把客服正在输入的搜索词和选中筛选一起冲掉。
     */
    public function conversations(Request $request)
    {
        $adminId = auth()->guard('admin')->user()->id;
        $inbox = $this->customerInbox($adminId);
        $activeId = (int) $request->query('ticket_id', 0);

        $html = view('admin::customers.tickets.partials.conversation-list', [
            'inbox'    => $inbox,
            'activeId' => $activeId,
        ])->render();

        return response()->json([
            'data' => [
                'html'          => $html,
                'counts'        => $inbox['counts'],
                'stats'         => $this->dashboardStats(),
                'online_agents' => SupportAgentState::query()->fresh()->count(),
                'server_time'   => now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * 今日客服指标：新会话 / 已结单 / 待回复 / 超时 / 平均首响 / 满意度。
     *
     * 这些数字就是「今天客服到底怎么样」的答案。此前工作台只有一个残留的未读数，
     * 没有任何量化口径 —— 出了问题只能靠回忆。
     *
     * @return array<string, mixed>
     */
    protected function dashboardStats(): array
    {
        $todayStart = now()->startOfDay();

        $newToday = SupportTicket::query()->where('created_at', '>=', $todayStart)->count();

        // 今日结单：用 updated_at 近似「状态最后一次变化」，不额外加列。
        $closedToday = SupportTicket::query()
            ->whereIn('status', [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED])
            ->where('updated_at', '>=', $todayStart)
            ->count();

        // 待回复：还开着的单里，客户最后一句比客服最后一句更晚（或客服从未回过）。
        // 挂起中的单是「等客户补充」，不算客服欠回复。
        $waitingReply = SupportTicket::query()
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
            ->whereNull('held_at')
            ->where(function ($query) {
                $query->whereNull('last_staff_message_at')
                    ->orWhereColumn('last_customer_message_at', '>', 'last_staff_message_at');
            })
            ->count();

        $overdue = SupportTicket::query()
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
            ->whereNull('held_at')
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->count();

        $held = SupportTicket::query()
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
            ->whereNotNull('held_at')
            ->count();

        // 平均首响：今日开单里，第一条客服/技术消息距建单的平均秒数。
        $firstReplies = DB::table('ticket_message')
            ->selectRaw('ticket_id, MIN(created_at) as first_at')
            ->whereIn('actor_type', ['admin', 'developer'])
            ->groupBy('ticket_id');

        $avgFirstResponse = DB::table('support_ticket as t')
            ->joinSub($firstReplies, 'f', 'f.ticket_id', '=', 't.id')
            ->where('t.created_at', '>=', $todayStart)
            // 首响理论上不可能早于建单；GREATEST 兜住历史脏数据，别让指标变成负数。
            ->selectRaw('AVG(GREATEST(TIMESTAMPDIFF(SECOND, t.created_at, f.first_at), 0)) as avg_seconds')
            ->value('avg_seconds');

        $rating = SupportTicket::query()
            ->whereNotNull('rated_at')
            ->where('rated_at', '>=', $todayStart)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as rating_count')
            ->first();

        return [
            'new_today'       => $newToday,
            'closed_today'    => $closedToday,
            'waiting_reply'   => $waitingReply,
            'overdue'         => $overdue,
            'held'            => $held,
            'first_response'  => $avgFirstResponse === null ? null : (int) round($avgFirstResponse),
            'rating_avg'      => $rating && $rating->avg_rating !== null ? round((float) $rating->avg_rating, 1) : null,
            'rating_count'    => (int) ($rating->rating_count ?? 0),
        ];
    }

    /**
     * 客户收件箱：一个客户一行。
     *
     * 2026-10-27 拍板：同一客户同时只允许一张进行中会话（用户侧已改）。
     * 这里仍按客户聚合，是为了兜住存量/异常：即使同一客户还有多张 active，
     * 也只占一行，未读数、等待时长跨会话合并；具体单号在右侧「客户历史」里看。
     *
     * 排序：**最久未回复的排最前**（客服最该先处理），其余按最近活跃倒序。
     *
     * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    protected function customerInbox(int $adminId): array
    {
        $tickets = SupportTicket::query()
            ->with('lastMessage')
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        if ($tickets->isEmpty()) {
            return [
                'rows'   => [],
                'counts' => ['total' => 0, 'mine' => 0, 'waiting' => 0, 'unread' => 0, 'queue' => 0, 'async' => 0, 'held' => 0],
            ];
        }

        $customerIds = $tickets->pluck('customer_id')->filter()->unique()->values();
        $customers = $customerIds->isEmpty()
            ? collect()
            : \Webkul\Customer\Models\Customer::query()->whereIn('id', $customerIds)->get()->keyBy('id');

        $unread = TicketMessage::query()
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->where('actor_type', TicketMessage::ACTOR_CUSTOMER)
            ->whereNull('read_at')
            ->selectRaw('ticket_id, count(*) as aggregate')
            ->groupBy('ticket_id')
            ->pluck('aggregate', 'ticket_id');

        $assigneeIds = $tickets->pluck('assignee_id')->filter()->unique()->values();
        $assigneeNames = $assigneeIds->isEmpty()
            ? collect()
            : Admin::query()->whereIn('id', $assigneeIds)->pluck('name', 'id');

        $priorityRank = ['urgent' => 3, 'high' => 2, 'normal' => 1, 'low' => 0];

        // 客户为空的历史脏数据按单号各自成组，不能把游客全都并到一行。
        $groups = $tickets->groupBy(fn (SupportTicket $ticket) => $ticket->customer_id
            ? 'c'.$ticket->customer_id
            : 't'.$ticket->id);

        $rows = [];

        foreach ($groups as $group) {
            // $tickets 已按 updated_at/id 倒序，组内第一条就是该客户最近活跃的会话。
            $list = $group->values();
            /** @var SupportTicket $primary */
            $primary = $list->first();

            $customer = $primary->customer_id ? $customers->get($primary->customer_id) : null;
            $name = trim((string) ($customer?->first_name ?? '').' '.(string) ($customer?->last_name ?? ''));
            $name = $name !== '' ? $name : ($customer?->email ?? '游客');

            $unreadCount = $list->sum(fn (SupportTicket $ticket) => (int) ($unread[$ticket->id] ?? 0));

            // 等待时长：该客户所有进行中会话里，最早一条“客户最后发言晚于客服最后回复”的时间。
            $waitingSince = null;
            foreach ($list as $ticket) {
                if ($ticket->last_customer_message_at === null) {
                    continue;
                }

                $unanswered = $ticket->last_staff_message_at === null
                    || $ticket->last_customer_message_at->gt($ticket->last_staff_message_at);

                if ($unanswered && ($waitingSince === null || $ticket->last_customer_message_at->lt($waitingSince))) {
                    $waitingSince = $ticket->last_customer_message_at;
                }
            }

            $waitingMinutes = $waitingSince === null
                ? null
                : (int) floor(max(0, now()->getTimestamp() - $waitingSince->getTimestamp()) / 60);

            $assigneeLabel = $list->pluck('assignee_id')->filter()->unique()
                ->map(fn ($id) => $assigneeNames[$id] ?? '#'.$id)
                ->implode('、');

            $highestPriority = $list
                ->sortByDesc(fn (SupportTicket $ticket) => $priorityRank[$ticket->priority] ?? 0)
                ->first()
                ->priority;

            $rows[] = [
                'ticket_id'      => $primary->id,
                'ticket_no'      => $primary->ticket_no,
                'customer_id'    => $primary->customer_id,
                'customer_name'  => $name,
                'customer_email' => $customer?->email,
                'search'         => mb_strtolower(implode(' ', array_filter(array_merge(
                    [$name, $customer?->email],
                    $list->pluck('ticket_no')->all()
                )))),
                'unread'         => $unreadCount,
                'preview'        => $this->conversationPreview($primary->lastMessage),
                'updated_label'  => $this->shortTimeLabel($primary->updated_at),
                'waiting_minutes'=> $waitingMinutes,
                'waiting_label'  => $waitingMinutes === null ? '' : $this->waitLabel($waitingMinutes),
                'status'         => $primary->status,
                'status_label'   => $primary->agentStatusLabel(),
                'service_mode'   => $primary->service_mode,
                'category_label' => $primary->categoryLabel(),
                'priority'       => $highestPriority,
                'priority_label' => SupportTicket::PRIORITY_LABELS[$highestPriority] ?? $highestPriority,
                'assignee_label' => $assigneeLabel !== '' ? $assigneeLabel : '未指派',
                'rating'         => $primary->rating,
                'is_overdue'     => $list->contains(fn (SupportTicket $ticket) => $ticket->isOverdue()),
                'is_held'        => $list->contains(fn (SupportTicket $ticket) => $ticket->isHeld()),
                'is_queue'       => $list->contains(fn (SupportTicket $ticket) => $ticket->service_mode === SupportTicket::SERVICE_ONLINE
                    && $ticket->status === SupportTicket::STATUS_OPEN
                    && ! $ticket->assignee_id
                    && ! $ticket->isHeld()),
                'has_async'      => $list->contains(fn (SupportTicket $ticket) => $ticket->service_mode === SupportTicket::SERVICE_ASYNC),
                'is_mine'        => $list->contains(fn (SupportTicket $ticket) => (int) $ticket->assignee_id === $adminId),
                'ticket_count'   => $list->count(),
                'tickets'        => $list->map(fn (SupportTicket $ticket) => [
                    'id'           => $ticket->id,
                    'ticket_no'    => $ticket->ticket_no,
                    'status_label' => $ticket->agentStatusLabel(),
                    'is_held'      => $ticket->isHeld(),
                ])->values()->all(),
                'updated_ts'     => $primary->updated_at?->getTimestamp() ?? 0,
            ];
        }

        // 最久未回复优先；没有待回复的按最近活跃倒序。
        usort($rows, function (array $a, array $b) {
            if ($a['waiting_minutes'] === null && $b['waiting_minutes'] === null) {
                return $b['updated_ts'] <=> $a['updated_ts'];
            }

            if ($a['waiting_minutes'] === null) {
                return 1;
            }

            if ($b['waiting_minutes'] === null) {
                return -1;
            }

            return $b['waiting_minutes'] <=> $a['waiting_minutes'];
        });

        $counts = [
            'total'   => count($rows),
            'mine'    => count(array_filter($rows, fn ($row) => $row['is_mine'])),
            'waiting' => count(array_filter($rows, fn ($row) => $row['waiting_minutes'] !== null)),
            'unread'  => count(array_filter($rows, fn ($row) => $row['unread'] > 0)),
            'queue'   => count(array_filter($rows, fn ($row) => $row['is_queue'])),
            'async'   => count(array_filter($rows, fn ($row) => $row['has_async'])),
            'held'    => count(array_filter($rows, fn ($row) => $row['is_held'])),
        ];

        return ['rows' => $rows, 'counts' => $counts];
    }

    protected function waitLabel(int $minutes): string
    {
        if ($minutes < 1) {
            return '刚刚进入';
        }

        if ($minutes < 60) {
            return '等待 '.$minutes.' 分钟';
        }

        if ($minutes < 1440) {
            return '等待 '.intdiv($minutes, 60).' 小时';
        }

        return '等待 '.intdiv($minutes, 1440).' 天';
    }

    protected function conversationPreview(?TicketMessage $message): string
    {
        if (! $message || ! $message->content) {
            return '暂无消息';
        }

        $prefix = [
            'admin'     => '客服：',
            'developer' => '技术：',
            'system'    => '系统：',
            'note'      => '备注：',
            'customer'  => '',
        ][$message->actor_type] ?? '';

        $text = preg_replace('/\s+/u', ' ', trim($message->content));

        return $prefix.mb_substr($text, 0, 42);
    }

    protected function shortTimeLabel(?\DateTimeInterface $time): string
    {
        if (! $time) {
            return '';
        }

        $minutes = (int) floor(max(0, now()->getTimestamp() - $time->getTimestamp()) / 60);

        if ($minutes < 1) {
            return '刚刚';
        }

        if ($minutes < 60) {
            return $minutes.' 分钟前';
        }

        if ($minutes < 1440) {
            return intdiv($minutes, 60).' 小时前';
        }

        return $time->format('m-d');
    }

    /**
     * 定性为事故(B-10)。
     *
     * 计划要求的链:**用户投诉 → 工单 → 若定性事故 → incident**。这里是把工单
     * 升级成一起事故的**唯一入口**:走与用户报案同一个 `IncidentService::report()`
     * (证据链照样在"这一刻"固化),并在 incident 侧记下肇事工单 `ticket_id`。
     *
     * 两条硬约束:
     *   ① 工单必须**有归属客户** —— `incident.customer_id` 是 NOT NULL,无主工单装不下;
     *   ② **不能重复定性** —— 一张工单最多对应一起事故,否则追溯链会分叉。
     */
    public function escalate(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        if (! $ticket->customer_id) {
            session()->flash('error', '该工单没有关联客户,无法定性为事故。');

            return redirect()->route('admin.customers.tickets.show', $id);
        }

        if ($ticket->incident) {
            session()->flash('error', '该工单已被定性为事故('.$ticket->incident->incident_no.'),不能重复定性。');

            return redirect()->route('admin.customers.tickets.show', $id);
        }

        $data = $request->validate([
            'severity'    => 'required|in:'.implode(',', array_keys(Incident::SEVERITY_LABELS)),
            'description' => 'required|string|max:2000',
            'occurred_at' => 'nullable|date|before_or_equal:now',
        ]);

        $incident = $this->incidents->report($ticket->customer_id, [
            // 工单上的 skill_id 就是商品 id(开单时校验过归属),直接带过去
            'skill_id'    => $ticket->skill_id,
            'severity'    => $data['severity'],
            'occurred_at' => $data['occurred_at'] ?? null,
            'description' => $data['description'],
            'ticket_id'   => $ticket->id,
        ]);

        // 工单侧留一条系统留言 + 状态推进 —— 否则工单看上去"没人管"了
        TicketMessage::create([
            'ticket_id'  => $ticket->id,
            'actor_type' => 'system',
            'actor_id'   => null,
            'content'    => "本工单已定性为事故，转事故追溯流程处理（事故编号 {$incident->incident_no}）。后续处理进展见事故记录。",
            'created_at' => now(),
        ]);

        if ($ticket->status === SupportTicket::STATUS_OPEN) {
            $ticket->status = SupportTicket::STATUS_PROCESSING;
        }
        $ticket->save();

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'ticket.escalate_incident',
            'support_ticket',
            $ticket->id,
            [
                'incident_id' => $incident->id,
                'incident_no' => $incident->incident_no,
                'severity'    => $incident->severity,
            ],
            $request->ip()
        );

        session()->flash('success', "已定性为事故 {$incident->incident_no}。");

        return redirect()->route('admin.customers.incidents.show', $incident->id);
    }

    /**
     * 平台方回复。支持普通表单与工作台 fetch；在线单必须由当前受理人或在线客服接入。
     */
    public function reply(Request $request, int $id)
    {
        $data = $request->validate([
            'content'         => 'required|string|max:2000',
            // 快捷话术的 key：点过哪条话术、最终有没有发出去，靠它统计采纳率。
            'quick_reply_key' => 'nullable|string|max:64',
        ]);

        $admin = auth()->guard('admin')->user();

        try {
            [$ticket, $message] = DB::transaction(function () use ($id, $admin, $data) {
                $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
                $isOnline = $ticket->service_mode === SupportTicket::SERVICE_ONLINE;

                if ($ticket->status === SupportTicket::STATUS_CLOSED) {
                    throw new \DomainException('该会话已关闭，不能继续回复。');
                }

                if ($isOnline && ! $this->agentIsFresh($admin->id)) {
                    throw new \DomainException('在线状态已过期，请重新上线后继续接待。');
                }

                if ($isOnline && $ticket->assignee_id && $ticket->assignee_id !== $admin->id) {
                    throw new \DomainException('该在线会话已由其他客服接入，不能代答。');
                }

                if ($isOnline && ! $ticket->assignee_id && ! $this->agentCanAccept($admin->id)) {
                    throw new \DomainException('请先上线接待，并确认当前接待数未达到上限。');
                }

                if (! $ticket->assignee_id) {
                    $ticket->assignee_id = $admin->id;
                }

                if ($isOnline && ! $ticket->accepted_at) {
                    $ticket->accepted_at = now();
                }

                $message = TicketMessage::create([
                    'ticket_id'  => $ticket->id,
                    'actor_type' => 'admin',
                    'actor_id'   => $admin->id,
                    'content'    => $data['content'],
                    'created_at' => now(),
                ]);

                if ($ticket->status === SupportTicket::STATUS_OPEN) {
                    $ticket->status = SupportTicket::STATUS_PROCESSING;
                }

                $ticket->last_staff_message_at = now();
                $ticket->ended_at = null;
                // 客服真的回复了 = 不再等客户补充，挂起自动解除。
                $ticket->held_at = null;
                $ticket->held_reason = null;
                $ticket->save();

                return [$ticket, $message];
            });
        } catch (\DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            session()->flash('error', $e->getMessage());

            return redirect()->route('admin.customers.tickets.show', $id);
        }

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $admin->id,
            'ticket.reply',
            'support_ticket',
            $ticket->id,
            ['status' => $ticket->status],
            $request->ip()
        );

        Cache::forget(SupportTicket::typingCacheKey($ticket->id, 'staff'));

        if (! empty($data['quick_reply_key'])) {
            $this->recordQuickReplyEvent('send', $data['quick_reply_key'], $admin->id, $ticket->id, $ticket->category);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '已回复。',
                'data'    => [
                    'ticket_id'    => $ticket->id,
                    'status'       => $ticket->status,
                    'status_label' => $ticket->agentStatusLabel(),
                    'message'      => [
                        'id'          => $message->id,
                        'actor_type'  => $message->actor_type,
                        'actor_id'    => $message->actor_id,
                        'content'     => $message->content,
                        'attachments' => $this->attachmentPayload($message->attachments ?? []),
                        'created_at'  => optional($message->created_at)->toDateTimeString(),
                        'read_at'     => null,
                    ],
                ],
            ], 201);
        }

        session()->flash('success', '已回复。');

        return redirect()->route('admin.customers.tickets.show', $ticket->id);
    }

    /**
     * 在线客服工作台脚本。放在包资源目录而不是 public，避免被 Bagisto 的 public/js 忽略规则漏提。
     */
    public function workbenchScript()
    {
        $path = base_path('packages/Webkul/Admin/src/Resources/assets/js/online-support-workbench.js');

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type'  => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * 客服上线/下线/心跳。心跳超过 60 秒未更新即自动失联。
     */
    public function presence(Request $request)
    {
        $data = $request->validate([
            'is_online'      => 'required|boolean',
            'max_concurrent' => 'nullable|integer|min:1|max:20',
        ]);

        $adminId = auth()->guard('admin')->user()->id;

        $state = SupportAgentState::updateOrCreate(
            ['admin_id' => $adminId],
            [
                'is_online'      => (bool) $data['is_online'],
                'max_concurrent' => $data['max_concurrent'] ?? 5,
                'last_seen_at'   => now(),
            ]
        );

        return response()->json([
            'data' => [
                'is_online'      => $state->isFresh(),
                'max_concurrent' => $state->max_concurrent,
                'last_seen_at'   => optional($state->last_seen_at)->toDateTimeString(),
                'online_agents'  => SupportAgentState::query()->fresh()->count(),
            ],
        ]);
    }

    /**
     * 原子认领在线会话。条件更新放在事务锁内，避免两个客服同时接入。
     */
    public function claim(Request $request, int $id)
    {
        $admin = auth()->guard('admin')->user();

        try {
            DB::transaction(function () use ($id, $admin, $request) {
                $ticket = SupportTicket::lockForUpdate()->findOrFail($id);

                if ($ticket->service_mode !== SupportTicket::SERVICE_ONLINE) {
                    throw new \DomainException('这不是在线会话。');
                }

                if ($ticket->status !== SupportTicket::STATUS_OPEN || $ticket->assignee_id) {
                    throw new \DomainException('该会话已被接入或不再排队。');
                }

                if (! $this->agentCanAccept($admin->id)) {
                    throw new \DomainException('请先上线接待，并确认当前接待数未达到上限。');
                }

                $ticket->assignee_id = $admin->id;
                $ticket->status = SupportTicket::STATUS_PROCESSING;
                $ticket->accepted_at = now();
                // 主动接入意味着重新开始处理，挂起不再成立。
                $ticket->held_at = null;
                $ticket->held_reason = null;
                $ticket->save();

                TicketMessage::create([
                    'ticket_id'  => $ticket->id,
                    'actor_type' => 'system',
                    'actor_id'   => null,
                    'content'    => "客服 {$admin->name} 已接入会话。",
                    'created_at' => now(),
                ]);

                $this->oplog->log(
                    OperationLog::ACTOR_ADMIN,
                    $admin->id,
                    'ticket.claim',
                    'support_ticket',
                    $ticket->id,
                    ['status' => $ticket->status],
                    $request->ip()
                );
            });
        } catch (\DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            session()->flash('error', $e->getMessage());

            return redirect()->route('admin.customers.tickets.show', $id);
        }

        $ticket = SupportTicket::findOrFail($id);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '已接入在线会话。',
                'data'    => [
                    'assignee_id'  => $ticket->assignee_id,
                    'status'       => $ticket->status,
                    'status_label' => $ticket->agentStatusLabel(),
                ],
            ]);
        }

        session()->flash('success', '已接入在线会话。');

        return redirect()->route('admin.customers.tickets.show', $id);
    }

    /**
     * 释放在线会话，放回队列。
     */
    public function release(Request $request, int $id)
    {
        $admin = auth()->guard('admin')->user();

        try {
            DB::transaction(function () use ($id, $admin, $request) {
                $ticket = SupportTicket::lockForUpdate()->findOrFail($id);

                if ($ticket->service_mode !== SupportTicket::SERVICE_ONLINE) {
                    throw new \DomainException('这不是在线会话。');
                }

                if ($ticket->assignee_id !== $admin->id) {
                    throw new \DomainException('只能释放自己已接入的会话。');
                }

                $ticket->assignee_id = null;
                $ticket->status = SupportTicket::STATUS_OPEN;
                $ticket->accepted_at = null;
                $ticket->queued_at = now();
                $ticket->save();

                TicketMessage::create([
                    'ticket_id'  => $ticket->id,
                    'actor_type' => 'system',
                    'actor_id'   => null,
                    'content'    => $ticket->isHeld()
                        ? '会话已释放，客户补充信息后会重新进入处理。'
                        : '会话已释放，正在等待其他客服接入。',
                    'created_at' => now(),
                ]);

                $this->oplog->log(
                    OperationLog::ACTOR_ADMIN,
                    $admin->id,
                    'ticket.release',
                    'support_ticket',
                    $ticket->id,
                    ['status' => $ticket->status],
                    $request->ip()
                );
            });
        } catch (\DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            session()->flash('error', $e->getMessage());

            return redirect()->route('admin.customers.tickets.show', $id);
        }

        $ticket = SupportTicket::findOrFail($id);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '会话已释放。',
                'data'    => [
                    'assignee_id'  => $ticket->assignee_id,
                    'status'       => $ticket->status,
                    'status_label' => $ticket->agentStatusLabel(),
                ],
            ]);
        }

        session()->flash('success', '会话已释放。');

        return redirect()->route('admin.customers.tickets.show', $id);
    }

    /**
     * 商品卡附件补上后台商品编辑链接；字符串链接附件保持原样。
     *
     * @param  array<int, mixed>  $attachments
     * @return array<int, mixed>
     */
    protected function attachmentPayload(array $attachments): array
    {
        $canEditProduct = bouncer()->hasPermission('catalog.products.edit');

        return collect($attachments)->map(function ($attachment) use ($canEditProduct) {
            if (! is_array($attachment) || ($attachment['type'] ?? null) !== 'product') {
                return $attachment;
            }

            $productId = (int) ($attachment['product_id'] ?? 0);
            $attachment['admin_url'] = ($canEditProduct && $productId > 0)
                ? route('admin.catalog.products.edit', $productId)
                : null;

            return $attachment;
        })->all();
    }

    /**
     * 后台 2 秒增量同步。
     */
    public function updates(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);
        $afterId = max(0, (int) $request->query('after_message_id', 0));
        $adminId = auth()->guard('admin')->user()->id;

        $messages = $ticket->messages()
            ->where('id', '>', $afterId)
            ->get()
            ->map(fn (TicketMessage $message) => [
                'id'          => $message->id,
                'actor_type'  => $message->actor_type,
                'actor_id'    => $message->actor_id,
                'content'     => $message->content,
                'attachments' => $this->attachmentPayload($message->attachments ?? []),
                'created_at'  => optional($message->created_at)->toDateTimeString(),
                'read_at'     => optional($message->read_at)->toDateTimeString(),
            ])
            ->values();

        $agent = $ticket->assignee_id
            ? \Webkul\User\Models\Admin::query()->select('id', 'name')->find($ticket->assignee_id)
            : null;

        return response()->json([
            'data' => [
                'id'            => $ticket->id,
                'status'        => $ticket->status,
                'status_label'  => $ticket->agentStatusLabel(),
                'service_mode'  => $ticket->service_mode,
                'assignee_id'   => $ticket->assignee_id,
                'assignee_name' => $agent?->name,
                'held'          => $ticket->isHeld(),
                'held_reason'   => $ticket->held_reason,
                // 用户正在输入：与用户端「客服正在输入」共用同一套 8 秒缓存心跳。
                'customer_typing' => Cache::has(SupportTicket::typingCacheKey($ticket->id, 'customer')),
                'can_reply'     => $ticket->status !== SupportTicket::STATUS_CLOSED
                    && (! $ticket->assignee_id || $ticket->assignee_id === $adminId),
                'messages'        => $messages,
                'last_message_id' => $ticket->messages()->max('id'),
                // 用户的已读状态不会产生新消息，必须单独下发才能把「未读」改成「已读」。
                'receipts'        => $ticket->messages()
                    ->whereIn('actor_type', TicketMessage::STAFF_ACTOR_TYPES)
                    ->whereNotNull('read_at')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get(['id', 'actor_type', 'read_at'])
                    ->map(fn (TicketMessage $message) => [
                        'id'          => $message->id,
                        'actor_type'  => $message->actor_type,
                        'read_at'     => optional($message->read_at)->toDateTimeString(),
                    ])
                    ->values(),
                'online_agents'   => SupportAgentState::query()->fresh()->count(),
                'server_time'     => now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * 后台已读用户消息。
     */
    public function read(int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $count = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('actor_type', 'customer')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['marked_read' => $count]]);
    }

    /**
     * 会话转接：把当前会话交给另一位客服。
     *
     * 规则（2026-10-27 拍板）：
     *   · 在线会话只能转给**当前在线且未达并发上限**的客服 —— 转给离线的人，
     *     会话会挂在一个不会回复的人名下，比不转更糟；
     *   · 留言工单可转给任意启用中的后台账号（不受在线限制）；
     *   · 只有当前受理人（或尚未指派的会话）能转，不能从同事手里抢。
     */
    public function transfer(Request $request, int $id)
    {
        $data = $request->validate([
            'assignee_id' => 'required|integer|exists:admins,id',
        ]);

        $admin = auth()->guard('admin')->user();

        try {
            [$ticket, $target] = DB::transaction(function () use ($id, $admin, $data, $request) {
                $ticket = SupportTicket::lockForUpdate()->findOrFail($id);

                if ($ticket->status === SupportTicket::STATUS_CLOSED) {
                    throw new \DomainException('已关闭的会话不能转接。');
                }

                if ((int) $data['assignee_id'] === (int) $admin->id) {
                    throw new \DomainException('不能转接给自己。');
                }

                $target = Admin::query()->where('status', 1)->find($data['assignee_id']);

                if (! $target) {
                    throw new \DomainException('目标客服不存在或已停用。');
                }

                if ($ticket->assignee_id && (int) $ticket->assignee_id !== (int) $admin->id) {
                    throw new \DomainException('该会话当前由其他客服接待，不能转接。');
                }

                if ($ticket->service_mode === SupportTicket::SERVICE_ONLINE) {
                    if (! $this->agentIsFresh((int) $target->id)) {
                        throw new \DomainException('目标客服当前不在线，无法接转在线会话。');
                    }

                    if (! $this->agentCanAccept((int) $target->id)) {
                        throw new \DomainException('目标客服的接待数已达上限。');
                    }

                    if ($ticket->status === SupportTicket::STATUS_OPEN) {
                        $ticket->status = SupportTicket::STATUS_PROCESSING;
                        $ticket->accepted_at = now();
                    }
                }

                $fromName = $admin->name ?: '平台客服';
                $targetName = $target->name ?: '平台客服';
                $previousAssignee = $ticket->assignee_id;

                $ticket->assignee_id = $target->id;
                $ticket->save();

                // 用户看得见转接：换人不是后台的内部动作，否则用户会觉得「客服怎么突然不说话了」。
                TicketMessage::create([
                    'ticket_id'  => $ticket->id,
                    'actor_type' => TicketMessage::ACTOR_SYSTEM,
                    'actor_id'   => null,
                    'content'    => "客服 {$fromName} 已将会话转接给 {$targetName}。",
                    'created_at' => now(),
                ]);

                $this->oplog->log(
                    OperationLog::ACTOR_ADMIN,
                    $admin->id,
                    'ticket.transfer',
                    'support_ticket',
                    $ticket->id,
                    ['from' => $previousAssignee, 'to' => $target->id],
                    $request->ip()
                );

                return [$ticket, $target];
            });
        } catch (\DomainException $e) {
            return $this->transferFailure($request, $id, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '会话已转接。',
                'data'    => [
                    'assignee_id'   => $ticket->assignee_id,
                    'assignee_name' => $target->name ?: '平台客服',
                    'status'        => $ticket->status,
                    'status_label'  => $ticket->agentStatusLabel(),
                ],
            ]);
        }

        session()->flash('success', '会话已转接。');

        return redirect()->route('admin.customers.tickets.show', $ticket->id);
    }

    /**
     * 挂起：把会话移出活跃队列，等待客户补充信息。
     *
     * 挂起是 `held_at` 标记，不改 status —— 这样 DataGrid、SLA、结单判断
     * 都不需要为第五个状态改一遍。
     */
    public function hold(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:200',
        ]);

        $admin = auth()->guard('admin')->user();

        try {
            $ticket = DB::transaction(function () use ($id, $admin, $data, $request) {
                $ticket = SupportTicket::lockForUpdate()->findOrFail($id);

                if (in_array($ticket->status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED], true)) {
                    throw new \DomainException('已结单的会话不能挂起。');
                }

                if ($ticket->assignee_id && (int) $ticket->assignee_id !== (int) $admin->id) {
                    throw new \DomainException('该会话由其他客服接待，不能挂起。');
                }

                $wasHeld = $ticket->isHeld();

                if (! $ticket->assignee_id) {
                    $ticket->assignee_id = $admin->id;
                }

                $ticket->held_at = now();
                $ticket->held_reason = $data['reason'] ?? $ticket->held_reason;

                if ($ticket->service_mode === SupportTicket::SERVICE_ONLINE
                    && $ticket->status === SupportTicket::STATUS_OPEN) {
                    $ticket->status = SupportTicket::STATUS_PROCESSING;
                    $ticket->accepted_at = now();
                }

                $ticket->save();

                if (! $wasHeld) {
                    TicketMessage::create([
                        'ticket_id'  => $ticket->id,
                        'actor_type' => TicketMessage::ACTOR_SYSTEM,
                        'actor_id'   => null,
                        'content'    => '客服已暂时挂起本次会话，正在等待你的补充信息。如问题仍在，请直接回复。',
                        'created_at' => now(),
                    ]);
                }

                $this->oplog->log(
                    OperationLog::ACTOR_ADMIN,
                    $admin->id,
                    'ticket.hold',
                    'support_ticket',
                    $ticket->id,
                    ['reason' => $ticket->held_reason],
                    $request->ip()
                );

                return $ticket;
            });
        } catch (\DomainException $e) {
            return $this->transferFailure($request, $id, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '会话已挂起。',
                'data'    => [
                    'held'         => true,
                    'held_reason'  => $ticket->held_reason,
                    'status'       => $ticket->status,
                    'status_label' => $ticket->agentStatusLabel(),
                ],
            ]);
        }

        session()->flash('success', '会话已挂起。');

        return redirect()->route('admin.customers.tickets.show', $ticket->id);
    }

    /**
     * 恢复挂起（不依赖客户回复，客服也可以主动恢复）。
     */
    public function resume(Request $request, int $id)
    {
        $admin = auth()->guard('admin')->user();

        try {
            $ticket = DB::transaction(function () use ($id, $admin, $request) {
                $ticket = SupportTicket::lockForUpdate()->findOrFail($id);

                if (! $ticket->isHeld()) {
                    throw new \DomainException('该会话当前未挂起。');
                }

                if ($ticket->assignee_id && (int) $ticket->assignee_id !== (int) $admin->id) {
                    throw new \DomainException('该会话由其他客服接待，不能恢复。');
                }

                $ticket->held_at = null;
                $ticket->held_reason = null;
                $ticket->save();

                TicketMessage::create([
                    'ticket_id'  => $ticket->id,
                    'actor_type' => TicketMessage::ACTOR_SYSTEM,
                    'actor_id'   => null,
                    'content'    => '客服已恢复处理本次会话。',
                    'created_at' => now(),
                ]);

                $this->oplog->log(
                    OperationLog::ACTOR_ADMIN,
                    $admin->id,
                    'ticket.resume',
                    'support_ticket',
                    $ticket->id,
                    ['status' => $ticket->status],
                    $request->ip()
                );

                return $ticket;
            });
        } catch (\DomainException $e) {
            return $this->transferFailure($request, $id, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '会话已恢复处理。',
                'data'    => [
                    'held'         => false,
                    'status'       => $ticket->status,
                    'status_label' => $ticket->agentStatusLabel(),
                ],
            ]);
        }

        session()->flash('success', '会话已恢复处理。');

        return redirect()->route('admin.customers.tickets.show', $ticket->id);
    }

    /**
     * 内部备注：只给客服看，永不进入用户侧接口。
     *
     * 刻意**不**更新 `last_staff_message_at`、不推进状态 —— 备注不是回复，
     * 否则「待回复」指标会把一句备注当成客服已经答复过。
     */
    public function note(Request $request, int $id)
    {
        $data = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $admin = auth()->guard('admin')->user();
        $ticket = SupportTicket::findOrFail($id);

        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            return response()->json(['message' => '该会话已关闭，不能添加备注。'], 409);
        }

        $message = TicketMessage::create([
            'ticket_id'  => $ticket->id,
            'actor_type' => TicketMessage::ACTOR_NOTE,
            'actor_id'   => $admin->id,
            'content'    => $data['content'],
            'created_at' => now(),
            // 备注不需要未读态：它只出现在后台，且写下它的人就是当前客服。
            'read_at'    => now(),
        ]);

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $admin->id,
            'ticket.note',
            'support_ticket',
            $ticket->id,
            ['length' => mb_strlen($data['content'])],
            $request->ip()
        );

        return response()->json([
            'message' => '备注已保存。',
            'data'    => [
                'message' => [
                    'id'          => $message->id,
                    'actor_type'  => $message->actor_type,
                    'actor_id'    => $message->actor_id,
                    'actor_name'  => $admin->name ?: '平台客服',
                    'content'     => $message->content,
                    'attachments' => [],
                    'created_at'  => optional($message->created_at)->toDateTimeString(),
                    'read_at'     => optional($message->read_at)->toDateTimeString(),
                ],
            ],
        ], 201);
    }

    /**
     * 客服「正在输入」心跳（8 秒 TTL 缓存，用户端轮询时读取）。
     */
    public function typing(Request $request, int $id)
    {
        $admin = auth()->guard('admin')->user();
        $ticket = SupportTicket::findOrFail($id);

        if ($ticket->status !== SupportTicket::STATUS_CLOSED) {
            Cache::put(
                SupportTicket::typingCacheKey($ticket->id, 'staff'),
                ['id' => $admin->id, 'name' => $admin->name ?: '平台客服'],
                now()->addSeconds(SupportTicket::TYPING_TTL_SECONDS)
            );
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * 快捷话术使用事件（insert=点击插入 / send=发出）。
     */
    public function quickReplyEvent(Request $request)
    {
        $data = $request->validate([
            'key'       => 'required|string|max:64',
            'event'     => 'required|in:insert,send',
            'ticket_id' => 'nullable|integer',
        ]);

        $admin = auth()->guard('admin')->user();
        $ticket = ! empty($data['ticket_id']) ? SupportTicket::find($data['ticket_id']) : null;

        $this->recordQuickReplyEvent(
            $data['event'],
            $data['key'],
            $admin->id,
            $ticket?->id,
            $ticket?->category
        );

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * 可转接的客服列表（在线优先，带当前接待数）。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function assignableAgents(int $excludeAdminId): array
    {
        $states = SupportAgentState::query()->fresh()->get()->keyBy('admin_id');

        $active = SupportTicket::query()
            ->where('service_mode', SupportTicket::SERVICE_ONLINE)
            ->whereNotNull('assignee_id')
            ->where('status', SupportTicket::STATUS_PROCESSING)
            ->whereNull('held_at')
            ->selectRaw('assignee_id, COUNT(*) as aggregate')
            ->groupBy('assignee_id')
            ->pluck('aggregate', 'assignee_id');

        return Admin::query()
            ->where('status', 1)
            ->where('id', '<>', $excludeAdminId)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name'])
            ->map(function (Admin $agent) use ($states, $active) {
                $state = $states->get($agent->id);
                $isOnline = $state !== null && $state->isFresh();
                $count = (int) ($active[$agent->id] ?? 0);
                $max = (int) ($state?->max_concurrent ?? 5);

                return [
                    'id'             => $agent->id,
                    'name'           => $agent->name ?: '平台客服',
                    'is_online'      => $isOnline,
                    'active_count'   => $count,
                    'max_concurrent' => $max,
                    'can_accept'     => $isOnline && $count < $max,
                ];
            })
            ->sortByDesc('is_online')
            ->values()
            ->all();
    }

    /**
     * 当前类别可用的快捷话术（类别组 + 通用组）。
     *
     * @return array<int, array{key:string,label:string,items:array<int, array{key:string,label:string,content:string}>}>
     */
    protected function quickReplyGroups(?string $category): array
    {
        $groups = (array) config('support_quick_replies.groups', []);
        $result = [];
        $used = [];

        if ($category && isset($groups[$category])) {
            $result[] = $this->presentQuickReplyGroup($category, $groups[$category]);
            $used[$category] = true;
        }

        if (isset($groups['common']) && ! isset($used['common'])) {
            $result[] = $this->presentQuickReplyGroup('common', $groups['common']);
        }

        // 没有归类（或 category 为空）时，至少给出「其他」组，别让快捷回复区空着。
        if (empty($result) && isset($groups[SupportTicket::CATEGORY_OTHER])) {
            $result[] = $this->presentQuickReplyGroup(SupportTicket::CATEGORY_OTHER, $groups[SupportTicket::CATEGORY_OTHER]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{key:string,label:string,items:array<int, array{key:string,label:string,content:string}>}
     */
    protected function presentQuickReplyGroup(string $key, array $group): array
    {
        return [
            'key'   => $key,
            'label' => $group['label'] ?? $key,
            'items' => array_map(fn (array $item) => [
                'key'     => (string) $item['key'],
                'label'   => (string) $item['label'],
                'content' => (string) $item['content'],
            ], array_values($group['items'] ?? [])),
        ];
    }

    /**
     * 近 N 天的快捷话术效果：插入数 / 发送数 / 采纳率。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function quickReplyStats(int $days = 7): array
    {
        $since = now()->subDays(max(1, $days) - 1)->startOfDay();

        $rows = DB::table('support_quick_reply_event')
            ->selectRaw("reply_key,
                SUM(CASE WHEN event = 'insert' THEN 1 ELSE 0 END) as inserts,
                SUM(CASE WHEN event = 'send' THEN 1 ELSE 0 END) as sends")
            ->where('created_at', '>=', $since)
            ->groupBy('reply_key')
            ->get()
            ->keyBy('reply_key');

        $stats = [];

        foreach ($this->quickReplyDefinitions() as $key => $definition) {
            $row = $rows->get($key);
            $inserts = (int) ($row->inserts ?? 0);
            $sends = (int) ($row->sends ?? 0);

            if ($inserts === 0 && $sends === 0) {
                continue;
            }

            $stats[] = [
                'key'      => $key,
                'label'    => $definition['label'],
                'group'    => $definition['group_label'],
                'inserts'  => $inserts,
                'sends'    => $sends,
                'adoption' => $inserts > 0 ? min(100, (int) round($sends / $inserts * 100)) : null,
            ];
        }

        usort($stats, fn ($a, $b) => [$b['sends'], $b['inserts']] <=> [$a['sends'], $a['inserts']]);

        return array_slice($stats, 0, 8);
    }

    /**
     * 把配置注册表拍平成 key => 定义。
     *
     * @return array<string, array{label:string,content:string,group:string,group_label:string}>
     */
    protected function quickReplyDefinitions(): array
    {
        $definitions = [];

        foreach ((array) config('support_quick_replies.groups', []) as $groupKey => $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (empty($item['key'])) {
                    continue;
                }

                $definitions[(string) $item['key']] = [
                    'label'       => (string) ($item['label'] ?? $item['key']),
                    'content'     => (string) ($item['content'] ?? ''),
                    'group'       => (string) $groupKey,
                    'group_label' => (string) ($group['label'] ?? $groupKey),
                ];
            }
        }

        return $definitions;
    }

    /**
     * 记录一条话术事件。未知 key 直接忽略（配置删掉的话术不该继续产生统计）。
     */
    protected function recordQuickReplyEvent(string $event, string $key, int $adminId, ?int $ticketId, ?string $category): void
    {
        if (! in_array($event, ['insert', 'send'], true)) {
            return;
        }

        if (! isset($this->quickReplyDefinitions()[$key])) {
            return;
        }

        DB::table('support_quick_reply_event')->insert([
            'reply_key'  => $key,
            'event'      => $event,
            'admin_id'   => $adminId,
            'ticket_id'  => $ticketId,
            'category'   => $category,
            'created_at' => now(),
        ]);
    }

    /**
     * 转接/挂起/恢复共用的失败响应：JSON 走 409，表单走 flash + 回详情。
     */
    protected function transferFailure(Request $request, int $id, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 409);
        }

        session()->flash('error', $message);

        return redirect()->route('admin.customers.tickets.show', $id);
    }

    /**
     * 客服是否能再接入一张在线会话。
     */
    protected function agentIsFresh(int $adminId): bool
    {
        $state = SupportAgentState::where('admin_id', $adminId)->first();

        return $state !== null && $state->isFresh();
    }

    protected function agentCanAccept(int $adminId): bool
    {
        $state = SupportAgentState::where('admin_id', $adminId)->first();

        if (! $state || ! $state->isFresh()) {
            return false;
        }

        $active = SupportTicket::query()
            ->where('service_mode', SupportTicket::SERVICE_ONLINE)
            ->where('assignee_id', $adminId)
            ->where('status', SupportTicket::STATUS_PROCESSING)
            // 挂起是在等客户补充，不占用客服的实时接待名额。
            ->whereNull('held_at')
            ->count();

        return $active < $state->max_concurrent;
    }
    /**
     * 改状态。
     *
     * ⚠ **结单必须同时给用户一个交代**：把状态直接推进到「已解决」却不回复，
     *   用户端只会看到状态变了、没有任何说明。所以这里不额外造规则去拦
     *   （运营有时确实只是整理积压单），但界面上把"最后一条消息是谁发的"显示出来。
     */
    public function updateStatus(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'status'   => 'required|in:'.implode(',', [
                SupportTicket::STATUS_OPEN,
                SupportTicket::STATUS_PROCESSING,
                SupportTicket::STATUS_RESOLVED,
                SupportTicket::STATUS_CLOSED,
            ]),
            'priority' => 'nullable|in:low,normal,high,urgent',
        ]);

        $ticket->status = $data['status'];

        if (! empty($data['priority'])) {
            $ticket->priority = $data['priority'];
        }

        if (in_array($ticket->status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED], true)) {
            $ticket->ended_at = now();
            // 结单后“等待客户补充”不再成立，别把挂起标记留在已结单的会话上。
            $ticket->held_at = null;
            $ticket->held_reason = null;
        } else {
            $ticket->ended_at = null;

            if ($ticket->service_mode === SupportTicket::SERVICE_ONLINE && $ticket->status === SupportTicket::STATUS_OPEN) {
                $ticket->queued_at = $ticket->queued_at ?: now();
            }
        }

        $ticket->save();

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'ticket.status',
            'support_ticket',
            $ticket->id,
            ['status' => $ticket->status, 'priority' => $ticket->priority],
            $request->ip()
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '工单状态已更新。',
                'data'    => [
                    'id'             => $ticket->id,
                    'status'         => $ticket->status,
                    'status_label'   => $ticket->agentStatusLabel(),
                    'priority'       => $ticket->priority,
                    'priority_label' => $ticket->priorityLabel(),
                    'assignee_id'    => $ticket->assignee_id,
                ],
            ]);
        }

        session()->flash('success', '工单状态已更新。');

        return redirect()->route('admin.customers.tickets.show', $ticket->id);
    }
}
