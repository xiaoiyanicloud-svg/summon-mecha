<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能导入留痕 —— v3.4 步骤 6。
 */
class SkillImport extends Model
{
    protected $table = 'skill_import';

    protected $fillable = [
        'seller_id',
        'source_format',
        'source_ref',
        'status',
        'raw_meta',
        'mapped_manifest',
        'warnings',
        'skill_id',
        'skill_version_id',
        'error_msg',
        'imported_at',
    ];

    protected $casts = [
        'seller_id'       => 'integer',
        'raw_meta'        => 'array',
        'mapped_manifest' => 'array',
        'warnings'        => 'array',
        'skill_id'        => 'integer',
        'skill_version_id' => 'integer',
        'imported_at'     => 'datetime',
    ];
}
