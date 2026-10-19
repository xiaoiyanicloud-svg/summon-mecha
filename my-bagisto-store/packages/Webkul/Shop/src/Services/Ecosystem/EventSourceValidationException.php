<?php

namespace Webkul\Shop\Services\Ecosystem;

/**
 * 活动来源 / 主办方校验失败。
 *
 * 与普通 `InvalidArgumentException` 分开：调用方与 HTTP 层必须凭稳定的 `errorCode`
 * 分支（422 的 `EVENT_SOURCE_REQUIRED` 或 `EVENT_ORGANIZER_UNVERIFIED`），
 * 而不是解析中英文文案。码值登记在 `ApiErrorCode`，不得在业务层写裸字面量。
 */
class EventSourceValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
