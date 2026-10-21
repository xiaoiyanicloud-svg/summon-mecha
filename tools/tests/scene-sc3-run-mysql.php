<?php

declare(strict_types=1);

// 只针对本机专用实例新建随机 QA 库；从不加载应用 .env 或复用任何现有库。
if (! extension_loaded('pdo_mysql') || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('须显式 APP_ENV=testing 并加载 pdo_mysql。');
}
$port = (int) (getenv('SCENE_SC3_PORT') ?: 23316);
if ($port < 20000 || $port > 65535) {
    throw new RuntimeException('SC3_PORT_REQUIRED');
}
$database = 'summon_mecha_sc3_qa_'.bin2hex(random_bytes(6));
$pdo = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('SCENE_SC3_PORT='.$port);
putenv('SCENE_SC3_DATABASE='.$database);
putenv('DB_DATABASE='.$database);
$marker = bin2hex(random_bytes(24));
putenv('SCENE_SC3_RUN='.$marker);
$pdo->exec('CREATE TABLE `'.$database.'`.sc3_qa_run (token CHAR(48) PRIMARY KEY) ENGINE=InnoDB');
$statement = $pdo->prepare('INSERT INTO `'.$database.'`.sc3_qa_run(token) VALUES (?)');
$statement->execute([$marker]);
register_shutdown_function(function () use ($pdo, $database, $marker) {
    // 只清理由本进程创建且所有权标记仍匹配的临时库；不 down 业务迁移。
    $actual = $pdo->query('SELECT token FROM `'.$database.'`.sc3_qa_run')->fetchColumn();
    if ($actual === $marker && preg_match('/^summon_mecha_sc3_qa_[a-f0-9]{12}$/D', $database)) {
        $pdo->exec('DROP DATABASE `'.$database.'`');
    }
});
$argv[] = '--mysql';
require __DIR__.'/scene-sc3.php';
