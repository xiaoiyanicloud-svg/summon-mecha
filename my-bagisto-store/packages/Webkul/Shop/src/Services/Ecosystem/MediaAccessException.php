<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 公开媒体不可访问（ADR-0001 §4.9 / §4.10 / G1-06）。
 *
 * 公开门禁的**任一**条件不满足都用同一个码、同一句文案：
 *   · 所属域 public_enabled 关闭；
 *   · owner_type 非法；
 *   · 引用不存在或 review_status 不是 approved；
 *   · 媒体 status 不是 approved；
 *   · 父 revision 不是 approved / 不是父对象当前 published_revision_id；
 *   · 父对象不是 published + normal；
 *   · 私有盘上的文件不存在。
 *
 * 刻意不区分原因：公开端不得泄露“某条草稿 / 待审 / 暂缓媒体是否存在”。
 * 映射：HTTP 层转成 `ApiErrorCode::MEDIA_NOT_FOUND`（404）。
 */
class MediaAccessException extends \RuntimeException
{
    public readonly string $errorCode;

    private function __construct(string $message)
    {
        $this->errorCode = ApiErrorCode::MEDIA_NOT_FOUND;

        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('媒体不存在或不可访问。');
    }
}
