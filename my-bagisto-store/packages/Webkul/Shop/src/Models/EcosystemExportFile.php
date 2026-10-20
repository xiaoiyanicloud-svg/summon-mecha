<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 专用导出文件事实（G2-13）。
 *
 * 文件路径只用于服务端读取，永不进入公开/本人 DTO。
 */
class EcosystemExportFile extends Model
{
    protected $table = 'ecosystem_export_files';

    protected $fillable = [
        'token_hash',
        'event_id',
        'admin_id',
        'filters',
        'fields',
        'file_path',
        'expires_at',
        'downloaded_at',
        'revoked_at',
    ];

    protected $casts = [
        'event_id'      => 'integer',
        'admin_id'      => 'integer',
        'filters'       => 'array',
        'fields'        => 'array',
        'expires_at'    => UtcDateTime::class,
        'downloaded_at' => UtcDateTime::class,
        'revoked_at'    => UtcDateTime::class,
    ];
}
