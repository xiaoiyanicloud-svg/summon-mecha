<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\Log;

/**
 * 公开读取 kill switch（ADR-0001 G1-D08 / 规划 §5.4 / G1-09）。
 *
 * 设计约束：
 *   · `assertAvailable()` **只读配置，不查任何业务表** —— 必须在任何 DB 查询
 *     之前调用。数据库不可用时，它仍然能返回稳定结果（这正是紧急停读的场景之一）。
 *   · 命中时抛 `PublicReadUnavailableException`（503），**不得**返回空列表或 404：
 *     空数据会让运营误判“没有内容”，而真实情况是“平台主动停读”。
 *   · 本类不做域开关判断。域开关（`ecosystem.events.public_enabled` /
 *     `ecosystem.content.public_enabled`）仍由公开 API / MediaAccessService 各自判断。
 *   · 公开列表、公开详情、公开媒体必须统一调用本类，不能各自读配置。
 */
class PublicReadGate
{
    /**
     * @throws PublicReadUnavailableException kill switch 已开启
     */
    public function assertAvailable(): void
    {
        if (! $this->available()) {
            Log::warning('ecosystem.public_read.emergency_disabled', [
                'reason' => (string) config('ecosystem_public_read.reason', 'unknown'),
            ]);

            throw PublicReadUnavailableException::emergencyDisabled();
        }
    }

    /**
     * 只读配置的可用性判断；供需要 `if` 分支的调用方使用。
     */
    public function available(): bool
    {
        return ! (bool) config('ecosystem_public_read.emergency_disabled', false);
    }
}
