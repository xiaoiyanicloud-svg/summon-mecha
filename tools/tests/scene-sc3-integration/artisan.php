<?php

declare(strict_types=1);

// 调度器只替换为隔离入口，随后使用同一真实 Console Kernel 和生产注册命令。
if (getenv('APP_ENV') !== 'testing' || ($argv[1] ?? '') !== 'scenes:operations') {
    throw new RuntimeException('SC3_QA_SCHEDULE_COMMAND_REQUIRED');
}
if ((! extension_loaded('pdo_mysql') || ! extension_loaded('intl')) && getenv('SCENE_QA_ARTISAN_REEXEC') !== '1') {
    $args = json_decode((string) getenv('SCENE_SC3_PHP_ARGS'), true, 32, JSON_THROW_ON_ERROR);
    putenv('SCENE_QA_ARTISAN_REEXEC=1');
    $process = proc_open([PHP_BINARY, ...$args, __FILE__, ...array_slice($argv, 1)], [STDIN, STDOUT, STDERR], $pipes, null, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('SC3_QA_PHP_START_FAILED');
    }
    exit(proc_close($process));
}
$app = require __DIR__.'/bootstrap.php';
$status = $app->handleCommand(new Symfony\Component\Console\Input\ArgvInput);
file_put_contents(dirname(storage_path()).'/scheduled-consumer.json', json_encode(['pid' => getmypid(), 'command' => 'scenes:operations --consume=100 --sweep', 'exit_code' => $status]));
exit($status);
