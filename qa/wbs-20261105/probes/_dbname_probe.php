<?php
require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';
$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "connection=".config('database.default')."\n";
echo "database=".Illuminate\Support\Facades\DB::connection()->getDatabaseName()."\n";
echo "disk=".Webkul\Shop\Models\SkillAsset::disk()."\n";
echo "storage=".\Illuminate\Support\Facades\Storage::disk(Webkul\Shop\Models\SkillAsset::disk())->path('')."\n";
