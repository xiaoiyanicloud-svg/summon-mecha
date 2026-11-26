<?php

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$release = DB::table('skill_target_releases')->where('id', 654)->first();

if (! $release) {
    echo json_encode(['found' => false]).PHP_EOL;
    exit(0);
}

$versionId = (int) $release->skill_version_id;

echo json_encode([
    'found' => true,
    'release_id' => 654,
    'status' => $release->review_status,
    'version_id' => $versionId,
    'columns' => Schema::getColumnListing('skill_target_release_event'),
    'events' => DB::table('skill_target_release_event')->where('skill_version_id', $versionId)->get()->toArray(),
], JSON_UNESCAPED_UNICODE).PHP_EOL;
