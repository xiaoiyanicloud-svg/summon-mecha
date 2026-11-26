<?php

namespace Webkul\Shop\Services\SkillCompile;

use InvalidArgumentException;

/** 编译请求契约；幂等键必须由 Application Service 在 seller 作用域内解析。 */
final class BuildCommand
{
    public function __construct(
        public readonly int $skillVersionId,
        public readonly int $sourceAssetId,
        public readonly string $sourceDigest,
        public readonly string $targetModelCode,
        public readonly string $targetProfileDigest,
        public readonly string $compilerVersion,
        public readonly string $idempotencyKey,
        public readonly string $requestedBy,
        public readonly int $sellerId,
    ) {
        if ($skillVersionId <= 0 || $sourceAssetId <= 0 || $sellerId <= 0) {
            throw new InvalidArgumentException('BuildCommand 身份字段必须为正整数');
        }

        foreach ([$sourceDigest, $targetModelCode, $targetProfileDigest, $compilerVersion, $idempotencyKey, $requestedBy] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('BuildCommand 字符串字段不得为空');
            }
        }

        if (! str_starts_with($sourceDigest, 'sha256:') || ! str_starts_with($targetProfileDigest, 'sha256:')) {
            throw new InvalidArgumentException('BuildCommand 摘要必须使用 sha256: 前缀');
        }
    }

    public function scopedIdempotencyKey(): string
    {
        return "seller:{$this->sellerId}:build:{$this->idempotencyKey}";
    }

    public function toArray(): array
    {
        return [
            'skill_version_id' => $this->skillVersionId,
            'source_asset_id' => $this->sourceAssetId,
            'source_digest' => $this->sourceDigest,
            'target_model_code' => $this->targetModelCode,
            'target_profile_digest' => $this->targetProfileDigest,
            'compiler_version' => $this->compilerVersion,
            'idempotency_key' => $this->idempotencyKey,
            'requested_by' => $this->requestedBy,
            'seller_id' => $this->sellerId,
        ];
    }
}