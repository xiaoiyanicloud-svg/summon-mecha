<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 内容修订指纹冲突：调用方基于的 `expectedFingerprint` 与库内当前指纹不一致。
 *
 * 与 `ContentRevisionConflictException`（文章级 `row_version`）分开：
 *   · 那个是“整篇文章壳被并发改过”，客户端重取 row_version；
 *   · 这个是“版本级内容指纹对不上”，说明调用方编辑所依据的正文/来源
 *     已经不是库内当前这一份（例如另一位编辑刚刚替换了来源）。
 *
 * 两者都必须映射为 `CONTENT_REVISION_CONFLICT`(409)，但保留 expected/actual
 * 指纹进日志与后台响应 —— 只给一个“冲突”无法判断是哪一份内容漂移了。
 */
class ContentRevisionFingerprintConflictException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly int $revisionId,
        public readonly string $expectedFingerprint,
        public readonly string $actualFingerprint,
    ) {
        $this->errorCode = ApiErrorCode::CONTENT_REVISION_CONFLICT;

        parent::__construct(sprintf(
            '内容修订 %d 指纹冲突：期望 %s，当前 %s；请重取最新版本后人工合并。',
            $revisionId,
            $expectedFingerprint,
            $actualFingerprint,
        ));
    }
}
