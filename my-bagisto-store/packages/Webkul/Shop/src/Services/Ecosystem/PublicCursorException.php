<?php

namespace Webkul\Shop\Services\Ecosystem;

use RuntimeException;

/**
 * G4-01 · 游标非法 / 与筛选条件不一致。
 *
 * 调用方必须把它转成稳定 400 `VALIDATION_ERROR`，不能静默从头翻页。
 */
final class PublicCursorException extends RuntimeException
{
    public const REASON_INVALID = 'invalid_cursor';

    public const REASON_FILTER_MISMATCH = 'cursor_filter_mismatch';

    private function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self(self::REASON_INVALID, '分页游标无效。');
    }

    public static function filterMismatch(): self
    {
        return new self(self::REASON_FILTER_MISMATCH, '分页游标与当前筛选条件不一致，请从第一页重新开始。');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
