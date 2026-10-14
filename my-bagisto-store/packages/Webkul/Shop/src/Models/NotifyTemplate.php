<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 通知模板(v3.4 步骤 4)。
 */
class NotifyTemplate extends Model
{
    protected $table = 'notify_template';

    protected $fillable = [
        'code',
        'scene',
        'title_tpl',
        'content_tpl',
        'channel',
        'status',
    ];
}
