<?php
// Read-only DB probe. No writes.
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '123456', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "SERVER_VERSION=" . $pdo->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
    echo "ISOLATION=" . $pdo->query("SELECT @@transaction_isolation")->fetchColumn() . PHP_EOL;
    $dbs = $pdo->query("SHOW DATABASES LIKE 'summon_mecha%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "SUMMON_DBS=" . implode(',', $dbs) . PHP_EOL;
    $tables = [];
    try {
        $tables = $pdo->query("SHOW TABLES FROM summon_mecha_step10_test LIKE 'skill_build%'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        echo "TESTDB_TABLES_ERROR=" . $e->getMessage() . PHP_EOL;
    }
    echo "TESTDB_SKILL_BUILD_TABLES=" . implode(',', $tables) . PHP_EOL;
} catch (Throwable $e) {
    echo "DB_PROBE_ERROR=" . $e->getMessage() . PHP_EOL;
    exit(1);
}
