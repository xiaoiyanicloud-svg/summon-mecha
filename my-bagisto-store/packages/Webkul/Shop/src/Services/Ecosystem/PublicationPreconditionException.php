<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 发布前置校验失败（ADR-0001 附录 B A-06 / G1-08A）。
 *
 * 典型场景：来源字段不完整、B-01/B-03 未签署时尝试提交非 platform + organizer、
 * 平台活动缺起止时间、媒体引用 / 媒体自身未批准、私有盘文件缺失。
 *
 * `errorCode` 只取 `ApiErrorCode` 已登记的稳定值（EVENT_SOURCE_REQUIRED、
 * EVENT_ORGANIZER_UNVERIFIED、EVENT_TIME_INVALID、CONTENT_SOURCE_REQUIRED、
 * MEDIA_INVALID 等），调用方与 HTTP 层不得解析中文文案。
 */
class PublicationPreconditionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function media(string $message): self
    {
        return new self(ApiErrorCode::MEDIA_INVALID, $message);
    }
}
