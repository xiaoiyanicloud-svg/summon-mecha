<?php
try {
$root = 'D:/CodexDeploy/summon-mecha/my-bagisto-store';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
if ($db !== 'summon_mecha_step10_test' || ! $app->environment('testing')) {
    throw new RuntimeException('Refusing non-isolated environment');
}
$actual = Illuminate\Support\Facades\DB::selectOne('select database() as db')->db;
if ($actual !== 'summon_mecha_step10_test') throw new RuntimeException('Wrong actual DB');
echo json_encode(['environment'=>$app->environment(),'actual_database'=>$actual,'migration_present'=>Illuminate\Support\Facades\Schema::hasTable('skill_execution')], JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
