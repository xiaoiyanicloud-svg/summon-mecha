<?php

namespace Webkul\Shop\Services\Ecosystem;

use RuntimeException;

/**
 * G4-02 · 收藏业务错误。
 *
 * 控制器统一转成稳定错误码与 4xx；不使用 500，也不把“目标不可见”伪装成成功。
 */
final class FavoriteException extends RuntimeException
{
    public const TYPE_INVALID = 'favorite_type_invalid';

    public const TARGET_NOT_FOUND = 'favorite_target_not_found';

    public const LIMIT_REACHED = 'favorite_limit_reached';

    public const DISABLED = 'favorites_disabled';

    private function __construct(
        private readonly string $reason,
        string $message,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function typeInvalid(): self
    {
        return new self(self::TYPE_INVALID, '收藏类型不支持。', 422);
    }

    public static function targetNotFound(): self
    {
        return new self(self::TARGET_NOT_FOUND, '目标尚未公开或不存在，暂时无法收藏。', 404);
    }

    public static function limitReached(int $limit): self
    {
        return new self(self::LIMIT_REACHED, sprintf('收藏已达到上限（%d 条），请先取消部分收藏。', $limit), 409);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, '收藏功能暂未开放。', 403);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
