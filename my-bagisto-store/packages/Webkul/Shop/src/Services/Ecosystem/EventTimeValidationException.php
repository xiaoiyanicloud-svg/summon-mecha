<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 活动时间校验失败（ADR-0001 G1-D04 / §4.3）。
 *
 * 典型场景：时区不是合法 IANA 名称、`ends_at` 早于 `starts_at`、
 * 只给了起止之一、平台活动在需要时间的阶段缺少时间。
 *
 * 与普通 `InvalidArgumentException` 分开：HTTP 层要凭稳定 `errorCode`
 * 返回 422 `EVENT_TIME_INVALID`，调用方据此引导“修正时间或改为待定”，
 * 而不是解析中文文案。
 */
class EventTimeValidationException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $message)
    {
        $this->errorCode = ApiErrorCode::EVENT_TIME_INVALID;

        parent::__construct($message);
    }
}
