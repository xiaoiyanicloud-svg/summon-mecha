<?php

namespace Webkul\Shop\Services\SkillCompile;

/**
 * 构建结果值对象。
 */
class BuildResult
{
    public function __construct(
        public string $status,          // SkillBuild 的 status 取值
        public ?string $carrierType = null,
        public ?int $artifactAssetId = null,
        public array $retargetConf = [],
        public string $log = '',
        public ?string $error = null,
    ) {}

    public static function success(?string $carrierType, ?int $artifactAssetId = null, array $retargetConf = [], string $log = ''): self
    {
        return new self('success', $carrierType, $artifactAssetId, $retargetConf, $log);
    }

    public static function pending(string $carrierType, array $retargetConf = [], string $log = ''): self
    {
        return new self('pending', $carrierType, null, $retargetConf, $log);
    }

    public static function failed(string $error): self
    {
        return new self('failed', null, null, [], '', $error);
    }
}
