<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能标签 —— v3.4 步骤 6。
 */
class SkillTag extends Model
{
    /**
     * 标签分组 —— 前端按分组分栏展示（动作形态 / 场景 / 难度）。
     *
     * ⚠ 这是**值域白名单**（与 `SkillAsset::CARRIER_TYPES` 同一套路）：
     *   自由写 type 会让前端分组出现拼写变体（scene / Scene / scenes），
     *   而前台只认这三个。要加分组请改这里 + 前端，别直接往表里塞新值。
     */
    public const TYPE_MOTION = 'motion_type';

    public const TYPE_SCENE = 'scene';

    public const TYPE_DIFFICULTY = 'difficulty';

    public const TYPES = [self::TYPE_MOTION, self::TYPE_SCENE, self::TYPE_DIFFICULTY];

    public const TYPE_LABELS = [
        self::TYPE_MOTION     => '动作形态',
        self::TYPE_SCENE      => '适用场景',
        self::TYPE_DIFFICULTY => '难度',
    ];

    protected $table = 'skill_tag';

    protected $fillable = ['name', 'type', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];
}
