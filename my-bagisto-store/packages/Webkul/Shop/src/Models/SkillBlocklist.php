<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能熔断/紧急下架 —— v3.4 步骤 8(计划 §4.19)。
 *
 * ⚠ 口径(任务卡 S-5):本表记录的是**熔断意图**,但"禁用已安装设备"这件事有三处约束,
 *   不是"一键全停" —— 见 `KillSwitchService` 类注释。下面各 action 注释一律按事实写。
 */
class SkillBlocklist extends Model
{
    protected $table = 'skill_blocklist';

    public const ACTION_BLOCK_NEW = 'block_new';           // 只阻断新下发(对已装设备不动)

    // 阻断新下发。计划里写的"全网下架"= 隐藏商品列表项,**当前未实现**(没有任何读点
    // 据本表隐藏上架列表),故此刻 block_all 与 block_new 行为相同。别把它当已生效的下架。
    public const ACTION_BLOCK_ALL = 'block_all';

    // 在建上述闸门之外,再对**经 agent 通道安装**的设备建卸载任务:
    //   · 在线设备 → 任务即时可被拉取;
    //   · 离线设备 → 任务落 pending_online,待其**下次上线**才生效;
    //   · 人工通道/通道未知的设备 → **无法自动卸载**(无 agent 回程),如实计入统计。
    public const ACTION_FORCE_UNINSTALL = 'force_uninstall';

    // 同 FORCE_UNINSTALL 的范围与约束,动作换成回滚(OP_ROLLBACK)。
    public const ACTION_FORCE_ROLLBACK = 'force_rollback';

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
        'ip',
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
