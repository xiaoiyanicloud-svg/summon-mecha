<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 客服工单 —— v3.4 步骤 7（用户↔平台↔开发者）。
 */
class SupportTicket extends Model
{
    protected $table = 'support_ticket';

    protected $attributes = [
        'service_mode' => self::SERVICE_ASYNC,
    ];

    public const SERVICE_ASYNC = 'async';

    public const SERVICE_ONLINE = 'online';

    /**
     * 输入中状态的 TTL（秒）。客服/用户两端共用同一个口径：
     * 客户端约每 4 秒补一次心跳，停手后最多 8 秒对方就不再看到「正在输入」。
     */
    public const TYPING_TTL_SECONDS = 8;

    /**
     * 输入中缓存的 key（side: staff | customer）。
     *
     * 不落库是有意的：输入中是瞬时信号，写表意味着每次按键都打一次数据库。
     */
    public static function typingCacheKey(int $ticketId, string $side): string
    {
        return "support:typing:{$ticketId}:{$side}";
    }

    public const STATUS_OPEN = 'open';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_LABELS = [
        self::STATUS_OPEN       => '待受理',
        self::STATUS_PROCESSING => '处理中',
        self::STATUS_RESOLVED   => '已解决',
        self::STATUS_CLOSED     => '已关闭',
    ];

    public const PRIORITY_LABELS = [
        'low'    => '低',
        'normal' => '普通',
        'high'   => '高',
        'urgent' => '紧急',
    ];

    /**
     * 工单类别（A9）。
     *
     * 此前 `category` 只做 `string|max:64`,前端传什么都落库 —— 于是后台的"类别"列
     * 逐渐变成自由文本,既无法筛选也无法统计。这里收敛成一个固定枚举。
     *
     * ⚠ 保留 `other`:前端 `pages/mine/tickets.vue` 目前固定传 `other`,
     *   `TicketController` 的默认值也是 `other` —— 收窄枚举时必须留着它,
     *   否则用户开单会被 422 挡下来。
     */
    public const CATEGORY_OTHER = 'other';           // 其他

    public const CATEGORY_DELIVERY = 'delivery';     // 下发 / 安装

    public const CATEGORY_SKILL = 'skill';           // 技能功能

    public const CATEGORY_DEVICE = 'device';         // 设备问题

    public const CATEGORY_PAYMENT = 'payment';       // 支付 / 退款

    public const CATEGORY_LABELS = [
        self::CATEGORY_DELIVERY => '下发/安装',
        self::CATEGORY_SKILL    => '技能功能',
        self::CATEGORY_DEVICE   => '设备问题',
        self::CATEGORY_PAYMENT  => '支付/退款',
        self::CATEGORY_OTHER    => '其他',
    ];

    /**
     * 满意度评价标签（CSAT）。
     *
     * 评分是数字，标签才是「为什么」—— 只有星级的话，4 分和 4 分之间的差别
     * 全被抹平了。标签固定成枚举，避免又变成自由文本。
     */
    public const RATING_TAG_LABELS = [
        'solved'     => '问题已解决',
        'fast'       => '回复及时',
        'polite'     => '态度好',
        'clear'      => '说明清楚',
        'unresolved' => '仍未解决',
        'slow'       => '等待太久',
    ];

    protected $fillable = [
        'ticket_no',
        'idempotency_key',
        'customer_id',
        'developer_id',
        'skill_id',
        'channel',
        'service_mode',
        'category',
        'priority',
        'status',
        'assignee_id',
        'related_deploy_task_id',
        'sla_due_at',
        'queued_at',
        'accepted_at',
        'last_customer_message_at',
        'last_staff_message_at',
        'ended_at',
        'held_at',
        'held_reason',
        'rating',
        'rating_tags',
        'rating_comment',
        'rated_at',
    ];

    protected $casts = [
        'customer_id'             => 'integer',
        'developer_id'            => 'integer',
        'skill_id'                => 'integer',
        'assignee_id'             => 'integer',
        'sla_due_at'              => 'datetime',
        'queued_at'               => 'datetime',
        'accepted_at'             => 'datetime',
        'last_customer_message_at'=> 'datetime',
        'last_staff_message_at'   => 'datetime',
        'ended_at'                => 'datetime',
        'held_at'                 => 'datetime',
        'rating'                  => 'integer',
        'rating_tags'             => 'array',
        'rated_at'                => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('id');
    }

    /**
     * 列表页只需要最后一条消息，避免为了展示摘要把整段对话全部读出来。
     *
     * ⚠ 用户侧**不能**直接用这个关系（内部备注也会被取到）。用户侧请用
     *   `TicketController::index()` 里按 actor_type 过滤后的查询。
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(TicketMessage::class, 'ticket_id')->latestOfMany('id');
    }

    /**
     * 由本工单定性而来的事故(B-10)。
     *
     * 反向关系 —— 真相存在 `incident.ticket_id`,这里反查,**不双写**。
     * 一张工单最多对应一起事故(定性一次);重复定性的入口在服务端被挡(见 SupportTicketController::escalate)。
     */
    public function incident(): HasOne
    {
        return $this->hasOne(Incident::class, 'ticket_id')->orderByDesc('id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * 用户端状态文案：在线会话的 open/processing 应显示“排队中/已接入”，
     * 不能把异步工单的“待受理/处理中”原样暴露给正在等待的人。
     */
    public function serviceStatusLabel(): string
    {
        // 挂起对用户只有一个含义：客服在等你的补充，而不是没人管。
        // 已结单的单不会被挂起（服务端 hold 接口会拒绝），这里仍加状态判断兜住历史脏数据。
        if ($this->isHeld() && ! in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            return '等待补充';
        }

        if ($this->service_mode === self::SERVICE_ONLINE) {
            if ($this->status === self::STATUS_OPEN) {
                return '排队中';
            }

            if ($this->status === self::STATUS_PROCESSING) {
                return '已接入';
            }
        }

        return $this->statusLabel();
    }

    /**
     * 后台视角的状态文案。
     *
     * 与 `serviceStatusLabel()` 的唯一差别：**不把挂起显示成状态**。
     * 后台用单独的琥珀色「挂起」徽标表达挂起，状态徽标继续显示真实的
     * 待受理/已接入/已解决 —— 两个徽标各说一件事，不重复。
     */
    public function agentStatusLabel(): string
    {
        if ($this->service_mode === self::SERVICE_ONLINE) {
            if ($this->status === self::STATUS_OPEN) {
                return '排队中';
            }

            if ($this->status === self::STATUS_PROCESSING) {
                return '已接入';
            }
        }

        return $this->statusLabel();
    }

    /**
     * 是否处于挂起（等待客户补充）状态。
     *
     * 挂起与 status 正交：`held_at` 非空即表示「已挂起」，
     * 不需要再新增一个会波及 DataGrid / SLA / 结单判断的状态值。
     */
    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? $this->priority;
    }

    /**
     * 能否评价：结单之后（已解决/已关闭）且尚未评价过。
     *
     * 不在处理中开放评价 —— 那时用户还没等到结果，打分只会打给「等待」本身。
     */
    public function isRateable(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)
            && $this->rated_at === null;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? ($this->category ?: '其他');
    }

    /**
     * 是否已超 SLA 且**还没结单**。
     *
     * ⚠ 已结单的不算超时 —— 超时只对"还欠着用户"的单有意义。
     *   把已解决的单也算进去，会让超时指标永远降不下来，也就没人看了。
     */
    public function isOverdue(): bool
    {
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            return false;
        }

        // 挂起 = 球在客户那边，SLA 不该继续压在客服头上。
        if ($this->isHeld()) {
            return false;
        }

        return $this->sla_due_at !== null && $this->sla_due_at->isPast();
    }
}
