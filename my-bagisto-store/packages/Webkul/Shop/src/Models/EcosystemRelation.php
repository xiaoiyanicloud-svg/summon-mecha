<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * G4-03 · 运营配置的生态关联。
 *
 * 只存“来源 → 目标”的引用与排序；目标标题/链接/可售状态一律实时解析，
 * 避免出现第二个真相源，也避免目标下架后仍展示旧标题。
 */
class EcosystemRelation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $table = 'ecosystem_relations';

    protected $fillable = [
        'source_type',
        'source_key',
        'target_type',
        'target_key',
        'sort_order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_by' => 'integer',
    ];
}
