<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能执行事实模型（与安装事实 install_record 严格隔离）。
 * 记录一次具体的技能执行生命周期。
 */
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

    protected $fillable = [
        'execution_id',
        'deploy_task_id',
        'did',
        'skill_id',
        'skill_version_id',
        'entitlement_id',
        'status',
        'execution_params',
        'plan_digest',
        'error_code',
        'error_msg',
        'started_at',
        'completed_at',
        'observed_at',
    ];

    protected $casts = [
        'execution_params' => 'array',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'observed_at'      => 'datetime',
    ];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
