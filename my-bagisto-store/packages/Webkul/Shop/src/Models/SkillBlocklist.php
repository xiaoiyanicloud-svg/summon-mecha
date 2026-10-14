<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能熔断/紧急下架 —— v3.4 步骤 8(计划 §4.19)。
 */
class SkillBlocklist extends Model
{
    protected $table = 'skill_blocklist';

    public const ACTION_BLOCK_NEW = 'block_new';           // 只阻断新下发

    public const ACTION_BLOCK_ALL = 'block_all';           // 阻断新下发 + 全网下架

    public const ACTION_FORCE_UNINSTALL = 'force_uninstall'; // + 已装设备卸载

    public const ACTION_FORCE_ROLLBACK = 'force_rollback';  // + 已装设备回滚

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LIFTED = 'lifted';

    protected $fillable = [
        'skill_id',
        'skill_version_id',
        'reason',
        'severity',
        'action',
        'status',
        'affected_devices',
        'created_by',
        'lifted_at',
    ];

    protected $casts = [
        'skill_id'         => 'integer',
        'skill_version_id' => 'integer',
        'affected_devices' => 'integer',
        'lifted_at'        => 'datetime',
    ];

    /**
     * 某技能当前是否有生效中的熔断。
     */
    public static function activeFor(int $skillId): ?self
    {
        return static::query()
            ->where('skill_id', $skillId)
            ->where('status', self::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }
}
