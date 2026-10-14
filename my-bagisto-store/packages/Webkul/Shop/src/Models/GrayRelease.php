<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 灰度发布 —— v3.4 步骤 8(§4.10)。
 */
class GrayRelease extends Model
{
    protected $table = 'gray_release';

    protected $fillable = ['skill_version_id', 'strategy', 'batch_no', 'status', 'metrics'];

    protected $casts = [
        'skill_version_id' => 'integer',
        'strategy'         => 'array',
        'metrics'          => 'array',
    ];
}
