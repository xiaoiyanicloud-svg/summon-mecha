<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 回滚记录 —— v3.4 步骤 8(§4.10)。
 */
class RollbackRecord extends Model
{
    protected $table = 'rollback_record';

    public $timestamps = false;

    protected $fillable = ['gray_release_id', 'deploy_task_id', 'trigger', 'reason', 'affected_devices', 'created_at'];

    protected $casts = [
        'gray_release_id'  => 'integer',
        'deploy_task_id'   => 'integer',
        'affected_devices' => 'integer',
        'created_at'       => 'datetime',
    ];
}
