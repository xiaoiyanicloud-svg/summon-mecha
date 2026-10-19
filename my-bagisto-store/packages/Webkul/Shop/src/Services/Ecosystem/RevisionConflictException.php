<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 修订指纹冲突：调用方基于的指纹与库内当前指纹不一致。
 *
 * 与 `EventRevisionConflictException`（对象级 `row_version`）分开：
 *   · 那个是“整行被并发改过”，客户端重取 row_version；
 *   · 这个是“版本级内容指纹对不上”，说明提交/批准/发布时看到的内容
 *     已经不是当前这份字节（例如审核后正文/媒体被替换过）。
 *
 * 两者都应映射为 `EVENT_REVISION_CONFLICT`(409)，但必须保留 expected/actual
 * 指纹进日志与后台响应 —— 只给一个“冲突”无法判断是哪一份内容漂移了。
 */
class RevisionConflictException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly string $expectedFingerprint,
        public readonly string $actualFingerprint,
        public readonly ?int $revisionId = null,
    ) {
        $this->errorCode = ApiErrorCode::EVENT_REVISION_CONFLICT;

        parent::__construct(sprintf(
            '修订 %s 指纹冲突:期望 %s,当前 %s;请重取最新版本后人工合并。',
            $revisionId === null ? '(未指定)' : (string) $revisionId,
            $expectedFingerprint,
            $actualFingerprint,
        ));
    }
}
