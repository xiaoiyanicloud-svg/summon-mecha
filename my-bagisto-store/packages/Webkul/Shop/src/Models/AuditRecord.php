<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 审核记录 —— 三层准入(v3.4 步骤 3 新增)。
 *
 * layer: 1=L0 机审 / 2=L1 仿真预跑 / 3=L2 人审
 * result: 0待审 1通过 2驳回 3待补充
 */
class AuditRecord extends Model
{
    protected $table = 'audit_record';

    public const LAYER_MACHINE = 1;

    public const LAYER_SIMULATION = 2;

    public const LAYER_HUMAN = 3;

    public const RESULT_PENDING = 0;

    public const RESULT_PASS = 1;

    public const RESULT_REJECT = 2;

    public const RESULT_SUPPLEMENT = 3;

    protected $fillable = [
        'skill_id',
        'version',
        'audit_type',
        'layer',
        'auditor_id',
        'result',
        'reason_category',
        'reason_detail',
        'suggestion',
        'submitted_at',
        'audited_at',
    ];

    protected $casts = [
        'skill_id'     => 'integer',
        'version'      => 'integer',
        'audit_type'   => 'integer',
        'layer'        => 'integer',
        'result'       => 'integer',
        'submitted_at' => 'datetime',
        'audited_at'   => 'datetime',
    ];

    /**
     * 取某个技能版本的审核记录。
     *
     * ⚠ **列名陷阱，务必走这里，别手写 where**：
     *   - `skill_id` 存的是 **`products.id`**（不是 skill_versions.id）
     *   - `version`  列存的是 **`skill_versions.id`**（不是版本号字符串）
     *
     * 也就是说列名 `version` 与它承载的内容（版本主键）并不一致 —— 历史命名。
     * 每次要按"版本"查审核记录时都得先想清楚这件事，所以收敛成一个 scope。
     */
    public function scopeForVersion(Builder $query, int $productId, int $versionId): Builder
    {
        return $query->where('skill_id', $productId)->where('version', $versionId);
    }
}
