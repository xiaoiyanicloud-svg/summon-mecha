<?php

declare(strict_types=1);

// 不 bootstrap 应用，不读取 .env；建库/清理前均核验自己创建的 datadir。
$run = (string) getenv('SCENE_SC3_INTEGRATION_RUN');
$runtime = (string) getenv('SCENE_SC3_INTEGRATION_DIR');
$port = (int) getenv('DB_PORT');
$normal = static fn (string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/'));
if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^[a-f0-9]{20}$/D', $run) || $port < 20000 || $port > 65535
    || ! realpath($runtime) || $normal((string) realpath($runtime)) !== $normal(dirname(__DIR__, 3).'/.claude/runtime/sc3-http-'.$run)
    || ! is_file($runtime.'/owner') || trim(file_get_contents($runtime.'/owner')) !== $run) {
    throw new RuntimeException('SC3_QA_OWNER_REQUIRED');
}
$deadline = microtime(true) + 30;
do {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port='.$port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        break;
    } catch (PDOException $e) {
        if (microtime(true) >= $deadline) {
            throw $e;
        } usleep(200000);
    }
} while (true);
$actual = $pdo->query('SELECT @@datadir AS datadir, @@version AS version, @@port AS port')->fetch(PDO::FETCH_ASSOC);
if ($normal($actual['datadir']) !== $normal((string) realpath($runtime.'/mysql'))) {
    throw new RuntimeException('SC3_QA_INSTANCE_MISMATCH');
}
if (($argv[1] ?? '') === 'create') {
    $pdo->exec('CREATE DATABASE summon_mecha_scenes_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE summon_mecha_scenes_test.sc3_qa_owner (token CHAR(20) PRIMARY KEY) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO summon_mecha_scenes_test.sc3_qa_owner (token) VALUES (?)')->execute([$run]);
    echo json_encode(['created' => true, 'identity' => $actual], JSON_UNESCAPED_SLASHES).PHP_EOL;
} elseif (($argv[1] ?? '') === 'cleanup') {
    $token = $pdo->query('SELECT token FROM summon_mecha_scenes_test.sc3_qa_owner')->fetchColumn();
    if ($token !== $run) {
        throw new RuntimeException('SC3_QA_DATABASE_OWNER_MISMATCH');
    }
    $pdo->exec('DROP DATABASE summon_mecha_scenes_test');
    $pdo->exec('SHUTDOWN');
    echo json_encode(['own_instance_shutdown' => true, 'own_database_removed' => true, 'identity' => $actual], JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    throw new RuntimeException('SC3_QA_DATABASE_ACTION_INVALID');
}
