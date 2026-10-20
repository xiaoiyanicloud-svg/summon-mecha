<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * G3-09 · 申诉私有证据。
 *
 * `storage_key` 永不出现在 API/日志；下载必须重新核验提交者或裁决权限。
 */
class CompetitionAppealAttachment extends Model
{
    protected $table = 'competition_appeal_attachments';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'appeal_id',
        'customer_id',
        'original_name',
        'storage_key',
        'mime',
        'byte_size',
        'sha256',
        'status',
    ];

    protected $casts = [
        'appeal_id'  => 'integer',
        'customer_id'=> 'integer',
        'byte_size'  => 'integer',
    ];

    public function appeal()
    {
        return $this->belongsTo(CompetitionAppeal::class, 'appeal_id');
    }
}
