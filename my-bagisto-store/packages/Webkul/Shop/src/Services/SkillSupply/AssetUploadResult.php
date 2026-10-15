<?php

namespace Webkul\Shop\Services\SkillSupply;

use Webkul\Shop\Models\SkillAsset;

/**
 * 交付物上传结果 —— 值对象。
 *
 * 让 AssetUploadService 不必知道 HTTP：控制器拿它映射成状态码 + 信封。
 */
final class AssetUploadResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?SkillAsset $asset = null,
        public readonly string $message = '',
        public readonly int $status = 200,
        public readonly array $extra = [],
        /**
         * 失败时的**业务理由码**（可空）—— 只在"调用方需要按码分流"时才给。
         *
         * 与 `$extra`（自由格式的诊断数据，如 sha256 的 expected/actual）分开：
         * 码是**契约**，调用方拿它映射到 `ApiErrorCode`；extra 是给人看的细节。
         * 为空表示"沿用调用方的通用失败码"（向后兼容：既有失败分支一个都没改）。
         */
        public readonly ?string $code = null,
    ) {}

    public static function ok(SkillAsset $asset, string $message = '交付物已上传。', int $status = 201): self
    {
        return new self(true, $asset, $message, $status);
    }

    public static function fail(string $message, int $status, array $extra = [], ?string $code = null): self
    {
        return new self(false, null, $message, $status, $extra, $code);
    }
}
