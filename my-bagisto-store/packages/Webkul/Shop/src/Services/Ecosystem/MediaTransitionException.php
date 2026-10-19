<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 媒体审核状态不允许该动作（ADR-0001 §4.9 / G1-06）。
 *
 * 状态机：
 *   · pending   -> approved（approve）
 *   · pending   -> rejected（reject）
 *   · pending | approved -> quarantined（quarantine，紧急隔离）
 *
 * 已隔离 / 已拒绝的媒体不能原地“恢复”为 approved；换文件必须新建媒体并重新审核。
 * 映射：HTTP 层转成 `ApiErrorCode::MEDIA_INVALID`（409）。
 */
class MediaTransitionException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $message)
    {
        $this->errorCode = ApiErrorCode::MEDIA_INVALID;

        parent::__construct($message);
    }
}
