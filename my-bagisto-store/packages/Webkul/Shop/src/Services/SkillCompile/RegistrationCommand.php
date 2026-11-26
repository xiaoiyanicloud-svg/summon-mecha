<?php

namespace Webkul\Shop\Services\SkillCompile;

use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 登记型构建的强类型入口（WBS B1.2）。
 *
 * 为什么还需要一个命令对象：便携编译有源包字节，可以用 `source_digest` 锁住输入；
 * **登记型**（motion/app 等）平台侧本来就没有源包，无法算源摘要 —— 但它同样不能
 * "裸调 builder 就完事"，否则入口校验、目标机型闭集、调用方身份都会各写各的。
 *
 * 这里用 **manifest 摘要** 作为可核验输入指纹：
 *   · 同一份 manifest 的 JCS 规范化字节 → sha256；
 *   · 命令自带摘要，服务端重新计算并比对，防止"校验的是 A、编译的是 B"。
 *
 * ⚠ 登记 ≠ 产出：本命令只登记构建意图，产物仍需外部工具/人工回填（D-9 口径）。
 */
final class RegistrationCommand
{
    /**
     * @param  array<int, string>  $targetModelCodes
     */
    public function __construct(
        public readonly int $skillVersionId,
        public readonly array $targetModelCodes,
        public readonly string $manifestDigest,
        public readonly string $sellerId = BuildCommandService::PLATFORM_SELLER_ID,
    ) {}

    public static function fromManifest(int $skillVersionId, array $manifest, array $targetModelCodes, ?string $sellerId = null): self
    {
        return new self(
            skillVersionId: $skillVersionId,
            targetModelCodes: array_values(array_map('strval', $targetModelCodes)),
            manifestDigest: self::digest($manifest),
            sellerId: $sellerId ?: BuildCommandService::PLATFORM_SELLER_ID,
        );
    }

    public static function digest(array $manifest): string
    {
        return 'sha256:'.hash('sha256', SigningService::jcsCanonicalize($manifest));
    }

    /** 供审计/日志用的稳定键（不含自由文本）。 */
    public function scopedKey(): string
    {
        return 'register:'.$this->skillVersionId.':'.$this->manifestDigest.':'.implode(',', $this->targetModelCodes);
    }
}
