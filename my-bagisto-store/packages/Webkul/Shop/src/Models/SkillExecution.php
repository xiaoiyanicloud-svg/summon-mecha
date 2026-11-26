<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/** 技能执行事实模型（与安装事实 install_record 严格隔离）。 */
class SkillExecution extends Model
{
    protected $table = 'skill_execution';

    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_CANCEL_REQUESTED = 'CANCEL_REQUESTED';
    public const STATUS_SUCCEEDED = 'SUCCEEDED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELED = 'CANCELED';
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
        self::STATUS_UNKNOWN,
    ];

    /** 明确的状态机；UNKNOWN 是终态，迟到事实只能追加审计，不能静默改回成功。 */
    public const TRANSITIONS = [
        self::STATUS_ACCEPTED => [self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_CANCEL_REQUESTED, self::STATUS_UNKNOWN],
        self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCEL_REQUESTED, self::STATUS_UNKNOWN],
        self::STATUS_CANCEL_REQUESTED => [self::STATUS_CANCELED, self::STATUS_FAILED, self::STATUS_UNKNOWN],
        self::STATUS_SUCCEEDED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELED => [],
        self::STATUS_UNKNOWN => [],
    ];

    protected $fillable = [
        'execution_id',
        'deploy_task_id',
        'did',
        'skill_id',
        'skill_version_id',
        'entitlement_id',
        'status',
        'actual_started',
        'actual_stopped',
        'execution_params',
        'execution_evidence',
        'plan_digest',
        'error_code',
        'error_msg',
        'started_at',
        'completed_at',
        'observed_at',
        'last_event_seq',
    ];

    protected $casts = [
        'execution_params' => 'array',
        'execution_evidence' => 'array',
        'actual_started' => 'boolean',
        'actual_stopped' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'observed_at' => 'datetime',
        'last_event_seq' => 'integer',
    ];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}