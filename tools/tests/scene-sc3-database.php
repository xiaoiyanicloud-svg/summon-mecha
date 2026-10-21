<?php

if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^summon_mecha_sc3_qa_[a-f0-9]{12}$/D', (string) getenv('SCENE_SC3_DATABASE'))
    || getenv('DB_DATABASE') !== getenv('SCENE_SC3_DATABASE') || (int) getenv('SCENE_SC3_PORT') < 20000 || (int) getenv('SCENE_SC3_PORT') > 65535) {
    throw new RuntimeException('SC3_MYSQL_ISOLATION_REQUIRED');
}

return ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) getenv('SCENE_SC3_PORT'),
    'database' => getenv('SCENE_SC3_DATABASE'), 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    'strict' => true, 'engine' => 'InnoDB', 'timezone' => '+00:00', 'options' => [PDO::ATTR_TIMEOUT => 5]];
