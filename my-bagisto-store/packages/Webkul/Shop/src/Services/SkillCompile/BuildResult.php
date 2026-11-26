<?php

namespace Webkul\Shop\Services\SkillCompile;

/**
 * 构建结果值对象。
 *
 * `status` 沿用既有数据库词表 success/pending/failed；对外状态机可映射为
 * succeeded/failed/canceled，禁止在本类里暗中改库内词表。
 */
class BuildResult
{
    public function __construct(
        public string $status,
        public ?string $carrierType = null,
        public ?int $artifactAssetId = null,
        public array $retargetConf = [],
        public string $log = '',
        public ?string $error = null,
        public ?string $buildKey = null,
        public ?int $runNumber = null,
        public ?string $targetModelCode = null,
        public ?string $packageSha256 = null,
        public ?string $targetProfileDigest = null,
        public ?string $errorCode = null,
    ) {}

    public static function success(
        ?string $carrierType,
        ?int $artifactAssetId = null,
        array $retargetConf = [],
        string $log = '',
        ?string $buildKey = null,
        ?int $runNumber = null,
        ?string $targetModelCode = null,
        ?string $packageSha256 = null,
        ?string $targetProfileDigest = null,
    ): self {
        return new self(
            'success', $carrierType, $artifactAssetId, $retargetConf, $log, null,
            $buildKey, $runNumber, $targetModelCode, $packageSha256, $targetProfileDigest,
        );
    }

    public static function pending(string $carrierType, array $retargetConf = [], string $log = ''): self
    {
        return new self('pending', $carrierType, null, $retargetConf, $log);
    }

    public static function failed(string $error, string $errorCode = 'BUILD_FAILED'): self
    {
        return new self('failed', null, null, [], '', $error, errorCode: $errorCode);
    }

    public function toArray(): array
    {
        return [
            'build_key' => $this->buildKey,
            'run_number' => $this->runNumber,
            'target_model_code' => $this->targetModelCode,
            'status' => $this->status,
            'artifact_asset_id' => $this->artifactAssetId,
            'package_sha256' => $this->packageSha256,
            'target_profile_digest' => $this->targetProfileDigest,
            'error_code' => $this->errorCode,
            'carrier_type' => $this->carrierType,
            'retarget_conf' => $this->retargetConf,
            'log' => $this->log,
            'error' => $this->error,
        ];
    }
}