<?php

/** B3 并发撤销探针：创建一条 approved 目标发布，打印身份。 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "refuse: not isolated test db\n");
    exit(3);
}

$productId = DB::table('products')->insertGetId([
    'sku' => 'b3-probe-'.uniqid(), 'type' => 'simple', 'created_at' => now(), 'updated_at' => now(),
]);

$version = SkillVersion::create([
    'product_id' => $productId, 'version' => '1.0.0', 'channel' => 'stable',
    'status' => SkillVersion::STATUS_PUBLISHED, 'license_mode' => 'per_device',
]);

$sourceDigest = 'sha256:'.str_repeat('a', 64);

$artifact = SkillAsset::create([
    'skill_version_id' => $version->id, 'carrier_type' => 'file', 'asset_role' => SkillAsset::ROLE_ARTIFACT,
    'asset_key' => 'b3_'.uniqid(), 'file_path' => 'skills/b3/'.uniqid().'.skill', 'file_name' => 'b3.skill',
    'file_size' => 10, 'file_hash' => hash('sha256', uniqid('', true)),
    'target_model_code' => 'unitree_g1', 'build_key' => 'bk_b3_'.uniqid(), 'source_digest' => $sourceDigest, 'sort_order' => 0,
]);

$release = SkillTargetRelease::create([
    'skill_version_id' => $version->id, 'target_model_code' => 'unitree_g1',
    'artifact_asset_id' => $artifact->id, 'build_key' => $artifact->build_key,
    'target_profile_digest' => app(TargetProfileService::class)->getDigest('unitree_g1'),
    'source_digest' => $sourceDigest, 'review_status' => SkillTargetRelease::STATUS_APPROVED, 'reviewed_at' => now(),
]);

echo json_encode([
    'release_id' => (int) $release->id,
    'version_id' => (int) $version->id,
    'product_id' => (int) $productId,
]).PHP_EOL;
