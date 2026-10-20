<?php

/**
 * Q-03 · 真实 worker 进程：调用 `php artisan ecosystem:process-outbox`。
 *
 *   php worker.php [limit=20] [--dry-run]
 *
 * qa_spawn 会启动**独立 PHP 进程**，不是在同一进程里直接调 processor。
 * stdout 保留 artisan 的原始摘要，便于把“真实进程 + 真实租约”写进证据。
 */

require __DIR__.'/../bootstrap.php';

$limit = 20;
$dryRun = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;

        continue;
    }

    if (ctype_digit($argument)) {
        $limit = max(1, (int) $argument);
    }
}

$arguments = ['ecosystem:process-outbox', '--limit='.$limit];

if ($dryRun) {
    $arguments[] = '--dry-run';
}

$run = qa_spawn(base_path('artisan'), $arguments);
$output = trim($run['stdout']."\n".$run['stderr']);

qa_json([
    'exit'    => $run['exit'],
    'dry_run' => $dryRun,
    'limit'   => $limit,
    'output'  => $output,
    'ok'      => $run['exit'] === 0,
]);
