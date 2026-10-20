<?php

/**
 * Q-02 · 专用库硬校验探针。
 *
 * 由 run.php 用两组环境变量各跑一次：
 *   · DB_DATABASE=root / APP_ENV=local → 必须 exit 2（bootstrap 拒绝）；
 *   · DB_DATABASE=..._test / APP_ENV=testing → 必须 exit 0。
 */

require __DIR__.'/../bootstrap.php';

qa_json([
    'ok'        => true,
    'app_env'   => app()->environment(),
    'database'  => (string) config('database.connections.mysql.database'),
    'pid'       => getmypid(),
]);
