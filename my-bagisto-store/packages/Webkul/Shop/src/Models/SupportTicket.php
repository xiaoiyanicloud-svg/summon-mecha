<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected $fillable = [
        'ticket_no',
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

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? $this->priority;
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
