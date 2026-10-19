<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 发布状态转移不合法（ADR-0001 附录 B A-04 / G1-08A）。
 *
 * 与前置校验失败分开：前置是“资料/媒体/指纹不满足”，转移是“当前状态不允许
 * 这个动作”（例如 retracted、向 in_review 对象再次 submit、审核开关关闭）。
 *
 * 码值复用已登记的 `EVENT_TRANSITION_INVALID` / `CONTENT_TRANSITION_INVALID`。
 */
class PublicationTransitionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function event(string $message): self
    {
        return new self(ApiErrorCode::EVENT_TRANSITION_INVALID, $message);
    }

    public static function content(string $message): self
    {
        return new self(ApiErrorCode::CONTENT_TRANSITION_INVALID, $message);
    }
}
