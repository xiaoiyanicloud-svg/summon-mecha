<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

/**
 * 使用 SDK 返回 / BT halt / 云端 ACK / mock / SIL 冒充物理停止 → 明确拒绝（WBS A2.2）。
 */
class StopObservationSourceForbiddenException extends InvalidArgumentException
{
    public const ERROR_CODE = 'STOP_OBSERVATION_SOURCE_FORBIDDEN';
}
