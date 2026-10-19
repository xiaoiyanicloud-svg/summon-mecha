<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 内容状态 / 不可变边界不允许该动作（ADR-0001 G1-D06）。
 *
 * 典型场景：
 *   · 非 draft 文章仍被编辑；
 *   · 文章首次发布后修改 slug；
 *   · G1-05 提前写入 `published_revision_id`；
 *   · 非 draft revision 修改内容列，或试图删除文章 / revision 审计证据；
 *   · 父 revision 非 draft 时新增 / 修改 / 删除来源。
 *
 * 映射：HTTP 层转成 `ApiErrorCode::CONTENT_TRANSITION_INVALID`（409）。
 * 调用方应刷新状态后按合法动作重做（例如新建 revision_no + 1），而不是原样重放。
 */
class ContentTransitionException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $message)
    {
        $this->errorCode = ApiErrorCode::CONTENT_TRANSITION_INVALID;

        parent::__construct($message);
    }
}
