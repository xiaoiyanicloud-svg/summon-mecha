<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 内容来源 / 权利依据校验失败（ADR-0001 §4.8 / G1-D06）。
 *
 * 典型场景：缺来源名、缺原文 URL、缺核验时间、缺权利依据，
 * 或 `source_type` 与 `rights_basis` 不匹配（例如 link_summary 试图复制全文）。
 *
 * 与普通 `InvalidArgumentException` 分开：HTTP 层要凭稳定 `errorCode`
 * 返回 422 `CONTENT_SOURCE_REQUIRED`，调用方据此引导“补齐来源后重新提交”，
 * 而不是解析中文文案。
 */
class ContentSourceValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function required(string $message): self
    {
        return new self(ApiErrorCode::CONTENT_SOURCE_REQUIRED, $message);
    }
}
