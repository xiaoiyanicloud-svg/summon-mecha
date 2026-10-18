<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuild;

/**
 * 交付物唯一解析 —— 一次下发只交付**一个**文件，本类回答"是哪一个"。
 *
 * 选择顺序（越靠前越显式）：
 *   1. 调用方显式 asset_id；
 *   2. 调用方显式 carrier_type / sort_order；
 *   3. 该机型下**成功** build 的产物；
 *   4. 版本内唯一的文件类交付物；
 *   5. 调用方给出声明 sha256 时，按哈希唯一命中；
 *   6. 仍有多份 → {@see SkillAssetAmbiguousException}（绝不默认取第一个）；
 *   7. 一份都没有 → null。
 *
 * ⚠ 只有 `carrier_type='file'` 进入本类：下载/指纹/端侧复算都是按字节定义的，
 *   `cloud_config` 等没有字节的载体不能冒充"包"（不改 `SkillAsset::isFile()`）。
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
        if ($assetId !== null) {
            $asset = SkillAsset::query()
                ->where('skill_version_id', $versionId)
                ->whereKey($assetId)
                ->first();

            return $asset && $asset->isFile() ? $asset : null;
        }

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
                ->filter(fn (SkillAsset $asset) => $asset->isFile())
                ->values();

            if ($assets->count() === 1) {
                return $assets->first();
            }

            // 只给了 carrier_type（没给 sort_order）且有多份文件：调用方没有说清是哪一份。
            // 绝不取排序第一个 —— 那等于让排序实现决定这次下发/验证的是哪份字节。
            if ($assets->count() > 1) {
                throw new SkillAssetAmbiguousException($versionId, $assets->pluck('id')->all());
            }

            return null;
        }

        $files = SkillAsset::fileAssetsForVersion($versionId);

        if ($files->isEmpty()) {
            return null;
        }

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

        if ($files->count() === 1) {
            return $files->first();
        }

        if (is_string($declaredSha256) && $declaredSha256 !== '') {
            $matched = $files->filter(
                fn (SkillAsset $asset) => is_string($asset->file_hash)
                    && $asset->file_hash !== ''
                    && hash_equals($declaredSha256, $asset->file_hash)
            )->values();

            if ($matched->count() === 1) {
                return $matched->first();
            }
        }

        throw new SkillAssetAmbiguousException($versionId, $files->pluck('id')->all());
    }

    /**
     * 版本内成功 build 的产物。
     *
     * ⚠ 平台侧当前没有稳定生产者，通常为空 —— 空了就继续走"唯一文件"，
     *   不能把 pending build 当成已产出。
     *
     * @return \Illuminate\Support\Collection<int, SkillAsset>
     */
    protected function successfulBuildArtifacts(int $versionId, ?string $modelCode)
    {
        // 机型未知时**不查任何 build**。
        //   skill_builds.target_model_code 是必填的具体机型，不存在"通用 build"；
        //   放宽条件会把某机型的成功产物当成这台未知设备的产物，进而错发字节。
        //   未知机型只允许走后面的"版本内唯一文件"保守路径。
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
            ->filter(fn (SkillAsset $asset) => $asset->isFile())
            ->values();
    }
}
