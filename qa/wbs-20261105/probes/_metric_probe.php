<?php
require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';
$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "db=".DB::connection()->getDatabaseName()."\n";
foreach (DB::table('deploy_metric_counters')->get() as $r) { echo $r->metric_key.'='.$r->metric_value.' @'.$r->updated_at."\n"; }
echo "batches=".DB::table('deploy_agent_metric_batches')->count()."\n";
