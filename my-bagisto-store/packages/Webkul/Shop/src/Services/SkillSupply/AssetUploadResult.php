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
    ) {}

    public static function ok(SkillAsset $asset, string $message = '交付物已上传。', int $status = 201): self
    {
        return new self(true, $asset, $message, $status);
    }

    public static function fail(string $message, int $status, array $extra = []): self
    {
        return new self(false, null, $message, $status, $extra);
    }
}
