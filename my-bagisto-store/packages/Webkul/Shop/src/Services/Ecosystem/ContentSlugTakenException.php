<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * slug 已被占用（ADR-0001 G1-D06）。
 *
 * “占用”包括：现有文章正在使用、已撤稿文章永久保留的 slug。
 * 平台**不得**静默加后缀或覆盖 —— 冲突必须让编辑显式换一个 slug。
 *
 * 映射：HTTP 层转成 `ApiErrorCode::CONTENT_SLUG_TAKEN`（409）。
 */
class ContentSlugTakenException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly string $slug,
        ?int $postId = null,
    ) {
        $this->errorCode = ApiErrorCode::CONTENT_SLUG_TAKEN;

        parent::__construct(sprintf(
            'slug「%s」已被%s占用，必须换一个 slug，不得覆盖或自动加后缀。',
            $slug,
            $postId === null ? '其他文章' : sprintf('文章 #%d', $postId),
        ));
    }
}
