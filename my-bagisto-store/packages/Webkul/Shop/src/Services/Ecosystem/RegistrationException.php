<?php

namespace Webkul\Shop\Services\Ecosystem;

use RuntimeException;

/**
 * 报名域业务异常（G2）。
 *
 * 服务层不依赖 HTTP 控制器命名空间：异常只携带稳定错误码、HTTP 语义与
 * 可公开的原因码，由 API/Admin 控制器统一映射成错误信封。
 */
class RegistrationException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly int $httpStatus,
        string $message,
        private readonly ?string $reason = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public static function disabled(string $message = '站内报名暂未开放。'): self
    {
        return new self('REGISTRATION_DISABLED', 403, $message, 'registration_disabled');
    }

    public static function notOpen(string $message = '报名尚未开始。'): self
    {
        return new self('REGISTRATION_NOT_OPEN', 422, $message, 'not_open');
    }

    public static function closed(string $message = '报名已截止。'): self
    {
        return new self('REGISTRATION_CLOSED', 422, $message, 'closed');
    }

    public static function full(string $message = '名额已满。'): self
    {
        return new self('REGISTRATION_FULL', 422, $message, 'full');
    }

    public static function alreadyActive(string $message = '已有有效报名。'): self
    {
        return new self('REGISTRATION_ALREADY_ACTIVE', 409, $message, 'already_registered');
    }

    public static function attemptLimit(string $message = '已达到最大报名次数。'): self
    {
        return new self('REGISTRATION_ATTEMPT_LIMIT', 422, $message, 'attempt_limit');
    }

    public static function transition(string $message, ?string $reason = null): self
    {
        return new self('REGISTRATION_TRANSITION_INVALID', 409, $message, $reason);
    }

    public static function reviewClosed(string $message = '审核已截止，不能再批准。'): self
    {
        return new self('REGISTRATION_REVIEW_CLOSED', 422, $message, 'review_closed');
    }

    public static function requestConflict(string $message = '同一请求键对应了不同载荷，请刷新后重试。'): self
    {
        return new self('REGISTRATION_REQUEST_CONFLICT', 409, $message, 'request_conflict');
    }

    public static function versionStale(string $message = '数据已被他人修改，请刷新后重试。'): self
    {
        return new self('REGISTRATION_VERSION_STALE', 409, $message, 'version_stale');
    }

    public static function notFound(string $message = '报名不存在。'): self
    {
        return new self('REGISTRATION_NOT_FOUND', 404, $message, 'not_found');
    }

    public static function forbidden(string $message = '无权执行该报名操作。'): self
    {
        return new self('REGISTRATION_FORBIDDEN', 403, $message, 'forbidden');
    }

    public static function invalid(string $message): self
    {
        return new self('REGISTRATION_VALIDATION_ERROR', 422, $message, 'invalid');
    }
}
