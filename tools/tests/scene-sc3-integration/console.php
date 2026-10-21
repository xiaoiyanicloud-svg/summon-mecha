<?php

declare(strict_types=1);

$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if (($argv[1] ?? '') !== 'migrate') {
    throw new RuntimeException('SC3_QA_CONSOLE_ACTION_INVALID');
}
$migrator = app('migrator');
if (! $migrator->repositoryExists()) {
    $migrator->getRepository()->createRepository();
}
$paths = array_merge($migrator->paths(), [database_path('migrations')]);
$files = $migrator->getMigrationFiles($paths);
// 预加载并记录原始文件；执行及迁移记录仍全部由 Laravel Migrator 管理。
foreach ($files as $file) {
    echo 'LOAD '.basename($file).PHP_EOL;
    require_once $file;
}
$migrator->run($paths);
$ran = $migrator->getRepository()->getRan();
$missing = array_values(array_diff(array_keys($files), $ran));
if ($missing) {
    throw new RuntimeException('SC3_QA_MIGRATION_INCOMPLETE');
}
file_put_contents(dirname(storage_path()).'/migrations.json', json_encode(['migrator' => get_class($migrator), 'registered' => count($files), 'executed' => count($ran), 'pending' => $missing, 'migrations' => $ran], JSON_PRETTY_PRINT));
echo 'MIGRATED '.count($ran).PHP_EOL;
