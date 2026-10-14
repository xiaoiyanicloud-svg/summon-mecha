<?php

namespace Webkul\Shop\Services\ContentSafety;

/**
 * 内容扫描结果 —— 值对象。
 *
 * ⚠ `degraded` 与 `passed` 是**两件事**，别合并：
 *   - `passed = true`  —— 扫过了，没问题
 *   - `degraded = true` —— **根本没扫成**（词表缺失 / 驱动不可用）
 * 把后者当成前者，就是"没查 = 合规"—— 那是最危险的误读。
 */
final class ScanResult
{
    private function __construct(
        public readonly bool $passed,
        public readonly array $hits,
        public readonly string $provider,
        public readonly bool $degraded,
        public readonly string $message,
    ) {}

    public static function pass(string $provider): self
    {
        return new self(true, [], $provider, false, '');
    }

    /**
     * @param  string[]  $hits
     */
    public static function violation(array $hits, string $provider): self
    {
        return new self(false, array_values($hits), $provider, false, '');
    }

    /**
     * 扫描器没能运行 —— 调用方必须**按配置决定**是拦截还是放行，
     * 不要静默当成通过。
     */
    public static function degraded(string $provider, string $message): self
    {
        return new self(false, [], $provider, true, $message);
    }

    public function isViolation(): bool
    {
        return ! $this->passed && ! $this->degraded;
    }
}
