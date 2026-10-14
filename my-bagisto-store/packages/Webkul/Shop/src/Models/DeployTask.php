<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 下发任务 —— 技能下发的状态机(Go 服务驱动,v3.4 步骤 3 新增)。
 *
 * ⚠ 幂等由应用层保证(回收任务前先原子翻转 entitlement 状态);
 *   MySQL 5.7 无"部分唯一索引",故只建索引不建唯一键。
 */
class DeployTask extends Model
{
    protected $table = 'deploy_task';

    public const OP_INSTALL = 1;

    public const OP_UNINSTALL = 2;

    public const OP_ROLLBACK = 3;

    public const OP_ENABLE = 4;

    public const OP_DISABLE = 5;

    public const OP_RECLAIM = 6; // 试用到期回收

    protected $fillable = [
        'task_no',
        'did',
        'channel',
        'skill_id',
        'skill_version_id',
        'entitlement_id',
        'operation',
        'status',
        'retry_count',
        'next_retry_at',
        'package_url',
        'package_hash',
        'signature',
        'error_code',
        'error_msg',
        'created_by',
        'completed_at',
    ];

    protected $casts = [
        'operation'       => 'integer',
        'retry_count'     => 'integer',
        'next_retry_at'   => 'datetime',
        'completed_at'    => 'datetime',
    ];
}
