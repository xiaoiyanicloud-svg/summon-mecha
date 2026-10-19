<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 媒体上传校验失败（ADR-0001 §4.9 / G1-06）。
 *
 * 典型场景：
 *   · 真实 MIME 不在 image/jpeg、image/png、image/webp、application/pdf 之内；
 *   · 扩展名与真实 MIME 不匹配（含伪装成 .png 的 PHP / SVG）；
 *   · 图片 getimagesize 失败、类型不符或尺寸超过上限；
 *   · PDF 缺少 `%PDF-` 头部；
 *   · 大小超过图片 5MB / 文档 10MB；
 *   · sha256 已存在（相同内容不重复落盘）。
 *
 * 映射：HTTP 层转成 `ApiErrorCode::MEDIA_INVALID`（422）。
 */
class MediaValidationException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $message)
    {
        $this->errorCode = ApiErrorCode::MEDIA_INVALID;

        parent::__construct($message);
    }
}
