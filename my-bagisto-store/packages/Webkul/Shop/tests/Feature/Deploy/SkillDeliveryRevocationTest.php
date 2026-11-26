<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAssetResolver;

/**
 * WBS B7：交付解析 fail-closed。
 *
 * 覆盖：撤销、build_key 不符、source_digest 不符、profile 摘要过期、
 * source 资产直发、跨机型、无 release、旧 generation 新产物。
 */

function b7Version(): SkillVersion
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'b7-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

function b7Asset(
    int $versionId,
    string $role,
    ?string $model = null,
    ?string $buildKey = null,
    ?string $sourceDigest = null,
    int $sortOrder = 0,
): SkillAsset {
    return SkillAsset::create([
        'skill_version_id'  => $versionId,
        'carrier_type'      => 'file',
        'asset_role'        => $role,
        'asset_key'         => 'b7_'.uniqid(),
        'file_path'         => 'skills/b7/'.uniqid().'.skill',
        'file_name'         => 'b7.skill',
        'file_size'         => 10,
        'file_hash'         => hash('sha256', uniqid('', true)),
        'target_model_code' => $model,
        'build_key'         => $buildKey,
        'source_digest'     => $sourceDigest,
        'sort_order'        => $sortOrder,
    ]);
}

function b7Release(
    SkillVersion $version,
    SkillAsset $artifact,
    string $status,
    ?string $profileDigest = null,
    ?string $sourceDigest = null,
): SkillTargetRelease {
    return SkillTargetRelease::create([
        'skill_version_id'      => $version->id,
        'target_model_code'     => $artifact->target_model_code,
        'artifact_asset_id'     => $artifact->id,
        'build_key'             => $artifact->build_key,
        'target_profile_digest' => $profileDigest ?? app(TargetProfileService::class)->getDigest($artifact->target_model_code),
        'source_digest'         => $sourceDigest ?? $artifact->source_digest,
        'review_status'         => $status,
        'reviewed_at'           => now(),
    ]);
}

function b7Resolver(): SkillDeliveryAssetResolver
{
    return app(SkillDeliveryAssetResolver::class);
}

it('B7 · approved + build_key/source_digest/profile 全对齐 → 返回该产物', function () {
    $version = b7Version();
    $artifact = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_ok', 'sha256:src');
    b7Release($version, $artifact, SkillTargetRelease::STATUS_APPROVED);

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $artifact->id)?->id)
        ->toBe($artifact->id);
});

it('B7 · 撤销后 fail-closed（即使 build_key/profile 仍匹配）', function () {
    $version = b7Version();
    $artifact = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_revoked', 'sha256:src');
    b7Release($version, $artifact, SkillTargetRelease::STATUS_REVOKED);

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $artifact->id))->toBeNull();
});

it('B7 · release 的 build_key 与产物不一致 → 拒绝（旧快照 / 被替换）', function () {
    $version = b7Version();
    $artifact = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_asset', 'sha256:src');
    $release = b7Release($version, $artifact, SkillTargetRelease::STATUS_APPROVED);
    $release->build_key = 'bk_b7_other';
    $release->save();

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $artifact->id))->toBeNull();
});

it('B7 · release 的 source_digest 与产物不一致 → 拒绝（旧 generation）', function () {
    $version = b7Version();
    $artifact = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_src', 'sha256:new');
    $release = b7Release($version, $artifact, SkillTargetRelease::STATUS_APPROVED);
    $release->source_digest = 'sha256:old';
    $release->save();

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $artifact->id))->toBeNull();
});

it('B7 · profile 摘要过期 → 拒绝', function () {
    $version = b7Version();
    $artifact = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_profile', 'sha256:src');
    b7Release($version, $artifact, SkillTargetRelease::STATUS_APPROVED, 'sha256:deadbeef');

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $artifact->id))->toBeNull();
});

it('B7 · source 资产绝不能作为可交付成品', function () {
    $version = b7Version();
    $source = b7Asset($version->id, SkillAsset::ROLE_SOURCE);

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $source->id))->toBeNull();
});

it('B7 · 跨机型请求 → 拒绝', function () {
    $version = b7Version();
    $artifact = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_model', 'sha256:src');
    b7Release($version, $artifact, SkillTargetRelease::STATUS_APPROVED);

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_r1', $artifact->id))->toBeNull();
});

it('B7 · 无 release 的产物 → 拒绝', function () {
    $version = b7Version();
    $artifact = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_no_rel', 'sha256:src');

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $artifact->id))->toBeNull();
});

it('B7 · 旧 generation 仍按旧快照交付，新 generation 未审核则拒绝', function () {
    $version = b7Version();
    $old = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_old', 'sha256:src');
    b7Release($version, $old, SkillTargetRelease::STATUS_APPROVED);
    $new = b7Asset($version->id, SkillAsset::ROLE_ARTIFACT, 'unitree_g1', 'bk_b7_new', 'sha256:src', 1);

    expect(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $old->id)?->id)->toBe($old->id)
        ->and(b7Resolver()->resolveForVersion($version->id, 'unitree_g1', $new->id))->toBeNull();
});
