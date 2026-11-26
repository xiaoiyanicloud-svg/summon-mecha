<?php
require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';
$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "db=".DB::connection()->getDatabaseName()."\n";
echo "tables=".count(DB::select('SHOW TABLES'))."\n";
foreach (['skill_versions','skill_assets','skill_build_runs','skill_build_commands','products','skill_target_releases'] as $t) {
    try { echo $t.'='.DB::table($t)->count()."\n"; } catch (Throwable $e) { echo $t."=ERR\n"; }
}
