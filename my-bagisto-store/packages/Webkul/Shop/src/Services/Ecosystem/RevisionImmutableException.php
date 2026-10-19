<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 修订 / 指针的状态不允许该动作。
 *
 * 典型场景：
 *   · `review_state` 已离开 draft 后改内容列（应新建 revision，而不是改历史）；
 *   · 删除 revision / 规则版本（它们是审计证据，G1 不提供删除）；
 *   · G1-04 阶段写入 `published_revision_id`（发布动作属于 G1-08）。
 *
 * 映射：HTTP 层转成 `ApiErrorCode::EVENT_TRANSITION_INVALID`（409）。
 * 调用方应刷新状态后按合法动作重做，而不是原样重放。
 */
class RevisionImmutableException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $message)
    {
        $this->errorCode = ApiErrorCode::EVENT_TRANSITION_INVALID;

        parent::__construct($message);
    }
}
