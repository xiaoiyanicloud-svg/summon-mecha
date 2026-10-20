<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 作品私有附件（G3-05）。
 *
 * `storage_key` 是私有盘路径，永不出现在 API/日志/DTO；
 * 下载必须由服务层重新核验队伍归属。文件字节不可原位替换。
 */
class CompetitionSubmissionAttachment extends Model
{
    protected $table = 'competition_submission_attachments';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'version_id',
        'entry_id',
        'original_name',
        'storage_key',
        'mime',
        'byte_size',
        'sha256',
        'created_by_customer_id',
        'status',
    ];

    protected $casts = [
        'version_id'             => 'integer',
        'entry_id'               => 'integer',
        'byte_size'              => 'integer',
        'created_by_customer_id' => 'integer',
    ];

    public function version()
    {
        return $this->belongsTo(CompetitionSubmissionVersion::class, 'version_id');
    }
}
