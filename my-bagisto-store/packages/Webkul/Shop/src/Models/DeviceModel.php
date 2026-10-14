<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 机型库 —— 兼容性校验的输入(v3.4 步骤 3 新增)。
 *
 * 种子数据见 docs/device-model-seed.md。
 * ⚠ compute_platform / firmware_versions 允许为空("未知")——多数厂商不公开,
 *   校验逻辑必须能处理"未知",不能因未知就判"固件过低"。
 */
class DeviceModel extends Model
{
    /**
     * 机型状态（计划 §0.5 的兼容性承诺分级）。
     *
     *   active       —— 可交付：能进选型、能被下单、有可用通道
     *   display_only —— 仅展示：出现在机型库里让人看得见，但不承诺能装
     *
     * ⚠ 这两个值直接影响 `CompatibilityService` 的判定（display_only 会降级为
     *   "仅人工通道"并给出提示），改值域前先看那边。
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISPLAY_ONLY = 'display_only';

    /**
     * 可用通道值域 —— 与技能声明的 `delivery_channels` 取交集得到"实际可用通道"。
     */
    public const COMM_CHANNELS = ['agent', 'manual'];

    protected $table = 'device_model';

    protected $fillable = [
        'brand',
        'model_code',
        'model_name',
        'vendor_code',
        'degrees_of_freedom',
        'compute_platform',
        'comm_channels',
        'firmware_versions',
        'min_agent_version',
        'icon',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'degrees_of_freedom' => 'integer',
        'comm_channels'      => 'array',
        'firmware_versions'  => 'array',
        'sort_order'         => 'integer',
    ];
}
