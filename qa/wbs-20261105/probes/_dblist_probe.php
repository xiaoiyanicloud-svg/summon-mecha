<?php
require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';
$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach (DB::select('SHOW DATABASES') as $row) { echo array_values((array) $row)[0]."\n"; }
echo "--- tables in current db ---\n".count(DB::select('SHOW TABLES'))."\n";
