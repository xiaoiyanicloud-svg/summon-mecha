<?php
try {
$root='D:/CodexDeploy/summon-mecha/my-bagisto-store';
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='summon_mecha_step10_test') throw new RuntimeException('Refusing unsafe test config');
$actual=Illuminate\Support\Facades\DB::selectOne('select database() as db')->db;
if ($actual!=='summon_mecha_step10_test') throw new RuntimeException('Refusing unsafe actual DB');
echo json_encode(['environment'=>$app->environment(),'actual_database'=>$actual]),PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
