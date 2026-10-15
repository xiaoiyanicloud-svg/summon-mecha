<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 安装事实 —— 每设备每技能一条(v3.4 步骤 3 新增)。
 */
class InstallRecord extends Model
{
    protected $table = 'install_record';

    protected $fillable = [
        'did',
        'skill_version_id',
        'entitlement_id',
        'deploy_task_id',
        // 交付通道(S-5 新增)：这条安装当初是经哪条通道落到设备上的。
        //   `agent` = 平台经 agent 推下去(可经 agent 撤回来 → 熔断能自动卸载)；
        //   `app`   = 应用内/人工通道(无回程 → 熔断无法自动卸载)；
        //   NULL    = 通道未知(存量历史行，**未回填**，读取方按"未知"处理)。
        //   写入口径见 DeployTaskService::recordInstall / SkillController::confirmInstall。
        'channel',
        'status',
        'current_version',
        'installed_at',
        'removed_at',
    ];

    protected $casts = [
        'skill_version_id' => 'integer',
        'entitlement_id'   => 'integer',
        'deploy_task_id'   => 'integer',
        'installed_at'     => 'datetime',
        'removed_at'       => 'datetime',
    ];
}
