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
