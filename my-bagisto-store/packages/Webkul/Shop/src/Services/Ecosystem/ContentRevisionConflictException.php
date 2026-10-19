<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 内容乐观并发校验失败：调用方持有的 `expected_row_version` 与库内不一致。
 *
 * 为什么必须是显式异常，而不是返回 false / null：
 *   并发保存落败与“字段没变化所以没写”都是“没更新”，调用方无法从返回值区分。
 *   对机器消费者（后台/Agent），前者必须提示“重取最新版本后人工合并”，
 *   后者是正常的幂等短路。混成一个结果就是把冲突静默吞掉。
 *
 * 映射：HTTP 层转成 `ApiErrorCode::CONTENT_REVISION_CONFLICT`（409）。
 */
class ContentRevisionConflictException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly int $postId,
        public readonly int $expectedRowVersion,
        public readonly int $actualRowVersion,
    ) {
        $this->errorCode = ApiErrorCode::CONTENT_REVISION_CONFLICT;

        parent::__construct(sprintf(
            '文章 %d 版本冲突：期望 row_version=%d，库内为 %d，请重取最新版本后人工合并。',
            $postId,
            $expectedRowVersion,
            $actualRowVersion,
        ));
    }
}
