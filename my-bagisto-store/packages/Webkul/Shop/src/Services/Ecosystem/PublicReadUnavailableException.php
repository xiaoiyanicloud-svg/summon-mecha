<?php

namespace Webkul\Shop\Services\Ecosystem;

use RuntimeException;

/**
 * 公开读取被平台主动停用（G1-09 kill switch）。
 *
 * 与“未找到 / 已撤稿”是**不同语义**：这是 503，不是 404，也不是空列表。
 * G1-10 的 controller / 媒体输出负责把它映射为：
 *
 *   HTTP 503
 *   {code: SERVICE_UNAVAILABLE, message: 公开内容暂时不可用，请稍后重试。}
 *
 * 本异常只表达“公开读取整体不可用”，不携带业务对象 ID，避免暴露对象是否存在。
 */
class PublicReadUnavailableException extends RuntimeException
{
    public const HTTP_STATUS = 503;

    public const ERROR_CODE = 'SERVICE_UNAVAILABLE';

    public static function emergencyDisabled(): self
    {
        return new self('公开内容暂时不可用，请稍后重试。');
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
