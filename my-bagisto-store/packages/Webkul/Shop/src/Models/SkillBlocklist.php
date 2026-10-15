<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能熔断/紧急下架 —— v3.4 步骤 8(计划 §4.19)。
 *
 * ⚠ 口径(任务卡 S-5):本表记录的是**熔断意图**,但"禁用已安装设备"这件事有三处约束,
 *   不是"一键全停" —— 见 `KillSwitchService` 类注释。下面各 action 注释一律按事实写。
 *
 * ⚠ 列名与内容不一致(反复踩过):`skill_id` 这一列存的是 **`products.id`,不是别的**。
 *   证据:`KillSwitchService::engage()` 找已装设备时用的正是 `skill_versions.product_id = skill_id`;
 *   `DeployTaskService` / `DeployController` 的 `skill_id` 也用于 `skill_versions.product_id` 反查。
 *   即本仓"技能"就是 category.slug='skill' 下的**商品**,它的 id 就是 products.id。
 *   本表的 `skill_version_id` 才指向具体版本(`skill_versions.id`)。
 *
 * ── S-8 · 熔断强度是**递增阶梯**(不是几个并列的开关) ──
 *   block_new  ⊂  block_all  ⊂  force_uninstall / force_rollback
 *   按下去越深,闸越往下游推:先停"新增",再停"既有分发",最后才动"已装设备"。
 *   这样设计是为了**避免"能买不能装"这种坏状态**(用户付了钱却拿不到东西)。
 */
class SkillBlocklist extends Model
{
    protected $table = 'skill_blocklist';

    // 停**新增的一切**:市场不展示 + 不可下单 + 不可下发。**不动已装设备**。
    //
    // ⚠ 与旧注释("只阻断新下发")不同 —— S-8 把这一档**改准**为"也停销售"。
    //   原因是"能买不能装"是坏状态:用户付了钱、平台却不下发,钱货两空。
    //   故 block_new 一按,商品即从市场消失、下单也被拒(见 SkillCatalogController /
    //   TobOrderController 的读点)。**唯一不动**的是:已授权用户的**下载**不受影响
    //   —— 那是 block_all 才收的口子(见下)。
    public const ACTION_BLOCK_NEW = 'block_new';

    // 在 block_new 基础上,**再停已有授权的下载**:既然明知这个包有问题,就不再继续分发。
    //   把闸从"入口"(展示/下单/下发)扩到"出口"(既有授权的取包口)。
    //   ⚠ 停止分发 ≠ 用户权益消失(D10):用户已买的授权仍在,只是暂时下不到包;
    //     补偿是运营基于影响面(engage() 记的已售/已装/已下载)另做的决定,不在本档自动发生。
    public const ACTION_BLOCK_ALL = 'block_all';

    // 在 block_all 基础上,再对**经 agent 通道安装**的设备建卸载任务:
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
