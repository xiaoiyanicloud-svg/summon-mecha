<?php
require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';
$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "db=".DB::connection()->getDatabaseName()."\n";
foreach (['categories','channels','locales','currencies','attribute_families','admins','customers','roles'] as $t) {
  try { echo str_pad($t,20).DB::table($t)->count()."\n"; } catch (Throwable $e) { echo str_pad($t,20)."ERR\n"; }
}
$root = DB::table('categories')->whereNull('parent_id')->get(['id','name'])->toArray();
echo "root_categories=".json_encode($root)."\n";
