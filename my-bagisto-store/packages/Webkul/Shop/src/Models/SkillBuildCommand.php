<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 构建命令（B1）。
 *
 * 一行 = 一次 seller 作用域内的幂等构建请求，同时充当 outbox：
 * 事务内只落命令，构建副作用在事务外执行。
 */
class SkillBuildCommand extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
    ];

    protected $table = 'skill_build_commands';

    protected $fillable = [
        'seller_id',
        'idempotency_key',
        'request_hash',
        'status',
        'build_key',
        'skill_version_id',
        'source_asset_id',
        'source_digest',
        'target_model_code',
        'target_profile_digest',
        'compiler_version',
        'requested_by',
        'payload_json',
        'result_json',
        'build_run_id',
        'worker_id',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'seller_id'            => 'integer',
        'skill_version_id'     => 'integer',
        'source_asset_id'      => 'integer',
        'build_run_id'         => 'integer',
        'payload_json'         => 'array',
        'result_json'          => 'array',
        'started_at'           => 'datetime',
        'finished_at'          => 'datetime',
    ];

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }
}
