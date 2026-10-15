<?php

namespace Webkul\Shop\Services\Incident;

use Webkul\Shop\Models\Incident;

/**
 * 事故状态流转非法（A6）—— 从当前状态到目标状态不在 `Incident::STATUS_TRANSITIONS` 里。
 *
 * 为什么是一个显式异常而不是静默放过:
 *   此前 `advance()` 只校验"目标状态在枚举内",不校验方向。于是 `closed → reported`
 *   这种"把已结案的案子打回待受理"的跳跃能落库,`resolved_at` 也不会被清,
 *   结案率 / SLA 超时口径随之失真。把它暴露成异常,调用方(受理台)能据实回一句人话,
 *   测试也能断言"这次流转被拒了",而不是靠事后查库反推。
 */
class IllegalIncidentTransitionException extends \RuntimeException
{
    public function __construct(
        /** 当前状态 */
        public readonly string $from,
        /** 请求流转到的状态 */
        public readonly string $to,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            '事故当前状态为「%s」,不能直接流转到「%s」。',
            Incident::STATUS_LABELS[$from] ?? $from,
            Incident::STATUS_LABELS[$to] ?? $to,
        ));
    }
}
