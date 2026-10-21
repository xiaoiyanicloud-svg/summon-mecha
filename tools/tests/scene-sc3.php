<?php

declare(strict_types=1);

// 专项隔离套件；不读取 .env。MySQL 只连接显式指定的 loopback 独立 QA 数据库。
define('SCENE_SC3_MODE', in_array('--mysql', $argv, true) ? 'mysql' : 'sqlite');
define('SCENE_SC3_WORKER', in_array('--worker', $argv, true));
require __DIR__.'/scene-workflow.php';
