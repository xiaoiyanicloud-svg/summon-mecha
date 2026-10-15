<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 回滚记录 —— v3.4 步骤 8(§4.10)。
 *
 * `created_by` / `ip` 为 P10 新增:回滚是"拿别人的设备执行动作",必须能回答
 * "谁、从哪、什么时候触发的",否则事故复盘只剩一句"回滚过"。
 */
class RollbackRecord extends Model
{
    protected $table = 'rollback_record';

    public $timestamps = false;

    protected $fillable = ['gray_release_id', 'deploy_task_id', 'trigger', 'reason', 'affected_devices', 'created_by', 'ip', 'created_at'];

    protected $casts = [
        'gray_release_id'  => 'integer',
        'deploy_task_id'   => 'integer',
        'affected_devices' => 'integer',
        'created_at'       => 'datetime',
    ];
}
