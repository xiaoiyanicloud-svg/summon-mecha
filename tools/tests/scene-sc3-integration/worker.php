<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo 'SC3_WORKER_CONNECTION:'.Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id.PHP_EOL;
$code = $kernel->call('scenes:operations', ['--consume' => (int) ($argv[1] ?? 1000)]);
echo $kernel->output();
exit($code);
