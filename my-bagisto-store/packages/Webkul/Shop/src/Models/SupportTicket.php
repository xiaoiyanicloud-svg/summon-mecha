<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 客服工单 —— v3.4 步骤 7（用户↔平台↔开发者）。
 */
class SupportTicket extends Model
{
    protected $table = 'support_ticket';

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

    protected $fillable = [
        'ticket_no',
        'idempotency_key',
        'customer_id',
        'developer_id',
        'skill_id',
        'channel',
        'category',
        'priority',
        'status',
        'assignee_id',
        'related_deploy_task_id',
        'sla_due_at',
    ];

    protected $casts = [
        'customer_id'  => 'integer',
        'developer_id' => 'integer',
        'skill_id'     => 'integer',
        'assignee_id'  => 'integer',
        'sla_due_at'   => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('id');
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

    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? $this->priority;
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

        return $this->sla_due_at !== null && $this->sla_due_at->isPast();
    }
}
