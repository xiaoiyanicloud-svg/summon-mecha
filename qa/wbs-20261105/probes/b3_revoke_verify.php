<?php

/** B3 并发撤销探针：核对终态与事件数并清理。 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$releaseId = (int) ($argv[1] ?? 0);
$versionId = (int) ($argv[2] ?? 0);
$version = DB::table('skill_versions')->where('id', $versionId)->first();
$productId = (int) ($version?->product_id ?? 0);

if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "refuse: not isolated test db\n");
    exit(3);
}

$status = DB::table('skill_target_releases')->where('id', $releaseId)->value('review_status');
$events = DB::table('skill_target_release_events')
    ->where('skill_version_id', $versionId)
    ->where('target_model_code', 'unitree_g1')
    ->orderBy('id')
    ->pluck('event_type')
    ->all();

echo json_encode([
    'release_id' => $releaseId,
    'final_status' => $status,
    'events' => $events,
    'revoked_event_count' => count(array_filter($events, fn ($e) => $e === 'revoked')),
]).PHP_EOL;

DB::table('skill_target_release_events')->where('skill_version_id', $versionId)->delete();
DB::table('skill_target_releases')->where('id', $releaseId)->delete();
DB::table('skill_assets')->where('skill_version_id', $versionId)->delete();
DB::table('skill_versions')->where('id', $versionId)->delete();

if ($productId > 0) {
    DB::table('products')->where('id', $productId)->delete();
}
