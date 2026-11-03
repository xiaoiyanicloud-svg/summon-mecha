<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillTargetRelease;

/**
 * 交付物唯一解析 —— 一次下发只交付一个文件，本类回答"是哪一个" (Work Package C01 / C06)。
 * 严格执行机型隔离与审核准入检查，杜绝跨机型错发或利用 asset_id 绕过审核 (C-T13 / C-T14)。
 */
class SkillDeliveryAssetResolver
{
    /**
     * @throws SkillAssetAmbiguousException
     */
    public function resolveForVersion(
        int $versionId,
        ?string $modelCode = null,
        ?int $assetId = null,
        ?string $carrierType = null,
        ?int $sortOrder = null,
        ?string $declaredSha256 = null,
    ): ?SkillAsset {
        // 1. 显式 asset_id（仍严格核验目标机型与发布准入 C01 / C-T13 / C-T14）
        if ($assetId !== null) {
            $asset = SkillAsset::query()
                ->where('skill_version_id', $versionId)
                ->whereKey($assetId)
                ->first();

            if (! $asset || ! $asset->isFile()) {
                return null;
            }

            if ($modelCode !== null && trim($modelCode) !== '') {
                if ($asset->target_model_code !== null && $asset->target_model_code !== $modelCode) {
                    return null; // 目标机型不符，禁止通过 asset_id 绕过
                }
            }

            if (! $this->isAssetApprovedForDelivery($asset, $versionId)) {
                return null;
            }

            return $asset;
        }

        // 2. 显式 carrier_type / sort_order
        if ($carrierType !== null || $sortOrder !== null) {
            $query = SkillAsset::query()
                ->where('skill_version_id', $versionId)
                ->where(
                    'carrier_type',
                    $carrierType !== null ? (string) $carrierType : SkillAsset::CARRIER_FILE
                );

            if ($sortOrder !== null) {
                $query->where('sort_order', $sortOrder);
            }

            $assets = $query->orderBy('sort_order')->get()
                ->filter(fn (SkillAsset $asset) => $asset->isFile() && $this->isAssetApprovedForDelivery($asset, $versionId))
                ->values();

            if ($modelCode !== null && trim($modelCode) !== '') {
                $assets = $assets->filter(fn (SkillAsset $a) => $a->target_model_code === null || $a->target_model_code === $modelCode)->values();
            }

            if ($assets->count() === 1) {
                return $assets->first();
            }

            if ($assets->count() > 1) {
                throw new SkillAssetAmbiguousException($versionId, $assets->pluck('id')->all());
            }

            return null;
        }

        $files = SkillAsset::fileAssetsForVersion($versionId)->filter(fn (SkillAsset $a) => ! $a->isSource())->values();
        if ($files->isEmpty()) {
            return null;
        }

        // 3. 该机型下成功 build 且已准入的产物
        $built = $this->successfulBuildArtifacts($versionId, $modelCode)
            ->filter(fn (SkillAsset $asset) => $files->contains('id', $asset->id))
            ->unique('id')
            ->values();

        if ($built->count() === 1) {
            return $built->first();
        }

        if ($built->count() > 1) {
            throw new SkillAssetAmbiguousException($versionId, $built->pluck('id')->all());
        }

        // 4. 机型守卫：若存在机型构建记录，未命中时严禁回退到其他机型产物
        $hasTargetBuilds = SkillBuild::where('skill_version_id', $versionId)->exists();
        if ($modelCode !== null && trim($modelCode) !== '') {
            if ($hasTargetBuilds) {
                return null;
            }
        }

        if ($hasTargetBuilds) {
            $isBuildArtifact = SkillBuild::where('skill_version_id', $versionId)
                ->whereIn('artifact_asset_id', $files->pluck('id'))
                ->exists();
            if ($isBuildArtifact) {
                return null;
            }
        }

        // 5. 传统唯一文件（仅限 legacy 或通用已准入文件）
        $eligibleFiles = $files->filter(function (SkillAsset $a) use ($versionId, $modelCode) {
            if ($modelCode !== null && trim($modelCode) !== '' && $a->target_model_code !== null && $a->target_model_code !== $modelCode) {
                return false;
            }
            return $this->isAssetApprovedForDelivery($a, $versionId);
        })->values();

        if ($eligibleFiles->count() === 1) {
            return $eligibleFiles->first();
        }

        // 6. 哈希匹配
        if (is_string($declaredSha256) && $declaredSha256 !== '') {
            $matched = $eligibleFiles->filter(
                fn (SkillAsset $asset) => is_string($asset->file_hash)
                    && $asset->file_hash !== ''
                    && hash_equals($declaredSha256, $asset->file_hash)
            )->values();

            if ($matched->count() === 1) {
                return $matched->first();
            }
        }

        if ($eligibleFiles->count() > 1) {
            throw new SkillAssetAmbiguousException($versionId, $eligibleFiles->pluck('id')->all());
        }

        return null;
    }

    /**
     * 判断资产是否已获得交付审核准入 (C-T14)。
     */
    protected function isAssetApprovedForDelivery(SkillAsset $asset, int $versionId): bool
    {
        // 源包绝不作为最终可交付成品 (C01 / C-T13)
        if ($asset->isSource()) {
            return false;
        }

        // 历史旧资产默认准入
        if ($asset->isLegacy()) {
            return true;
        }

        // 成品包必须处于 approved 状态
        if ($asset->review_status === SkillAsset::REVIEW_APPROVED) {
            return true;
        }

        return SkillTargetRelease::where('skill_version_id', $versionId)
            ->where('artifact_asset_id', $asset->id)
            ->where('review_status', SkillTargetRelease::STATUS_APPROVED)
            ->exists();
    }

    /**
     * 版本内成功 build 且已审核获准的产物。
     */
    protected function successfulBuildArtifacts(int $versionId, ?string $modelCode)
    {
        if (! is_string($modelCode) || trim($modelCode) === '') {
            return collect();
        }

        $query = SkillBuild::query()
            ->where('skill_version_id', $versionId)
            ->where('status', 'success')
            ->whereNotNull('artifact_asset_id')
            ->where('target_model_code', $modelCode);

        $ids = $query->pluck('artifact_asset_id')->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return SkillAsset::query()
            ->where('skill_version_id', $versionId)
            ->whereIn('id', $ids)
            ->orderBy('carrier_type')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (SkillAsset $asset) => $asset->isFile() && $this->isAssetApprovedForDelivery($asset, $versionId))
            ->values();
    }
}
