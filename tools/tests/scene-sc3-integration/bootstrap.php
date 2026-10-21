<?php

declare(strict_types=1);

// 只接受本轮新建实例和新库。完整应用、会话、EAV、监听器均保持真实实现。
$repo = dirname(__DIR__, 3);
$run = (string) getenv('SCENE_SC3_INTEGRATION_RUN');
$runtime = (string) getenv('SCENE_SC3_INTEGRATION_DIR');
$normal = static fn (string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/'));
$expected = $repo.'/.claude/runtime/sc3-http-'.$run;
if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^[a-f0-9]{20}$/D', $run)
    || ! realpath($runtime) || $normal((string) realpath($runtime)) !== $normal($expected)
    || ! is_file($runtime.'/owner') || trim(file_get_contents($runtime.'/owner')) !== $run
    || getenv('DB_HOST') !== '127.0.0.1' || (int) getenv('DB_PORT') < 20000
    || getenv('DB_DATABASE') !== 'summon_mecha_scenes_test') {
    throw new RuntimeException('SC3_INTEGRATION_ISOLATION_REQUIRED');
}
$pdo = new PDO('mysql:host=127.0.0.1;port='.(int) getenv('DB_PORT').';dbname=summon_mecha_scenes_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
$identity = $pdo->query('SELECT @@datadir AS datadir, @@version AS version, DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
if ($normal($identity['datadir']) !== $normal($runtime.'/mysql')
    || $pdo->query('SELECT token FROM sc3_qa_owner')->fetchColumn() !== $run) {
    throw new RuntimeException('SC3_INTEGRATION_DATABASE_OWNER_MISMATCH');
}
foreach (['cache', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private/scenes'] as $dir) {
    if (! is_dir($runtime.'/storage/'.$dir)) {
        mkdir($runtime.'/storage/'.$dir, 0700, true);
    }
}
foreach (['APP_CONFIG_CACHE' => 'config.php', 'APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php', 'APP_ROUTES_CACHE' => 'routes.php', 'APP_EVENTS_CACHE' => 'events.php'] as $key => $file) {
    putenv($key.'='.$runtime.'/storage/cache/'.$file);
}
putenv('VIEW_COMPILED_PATH='.$runtime.'/storage/framework/views');
putenv('COLUMNS=120');
putenv('LINES=40');
$base = $repo.'/my-bagisto-store';
if (! defined('ARTISAN_BINARY')) {
    define('ARTISAN_BINARY', __DIR__.'/artisan.php');
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
if (DIRECTORY_SEPARATOR === '\\') {
    $app->addAbsoluteCachePathPrefix(substr($runtime, 0, 3));
}
$app->useEnvironmentPath($runtime);
$app->useStoragePath($runtime.'/storage');
$app->booted(function () use ($runtime, $repo) {
    $flags = json_decode(file_get_contents($runtime.'/flags.json'), true, 32, JSON_THROW_ON_ERROR);
    $allowed = ['publication_v2', 'requests_enabled', 'quotes_enabled', 'reservations_enabled', 'project_actions_enabled', 'operations_enabled', 'order_conversion_enabled'];
    foreach ($flags as $flag => $value) {
        if (! in_array($flag, $allowed, true) || ! is_bool($value)) {
            throw new RuntimeException('SC3_QA_FLAG_INVALID');
        }
        config(['scenes.'.$flag => $value]);
    }
    // 夹具扫描器仅存在于本测试入口，不能写入正式环境配置。
    config(['scenes.attachment_scan_command' => [PHP_BINARY, $repo.'/tools/tests/fixtures/scene-scanner.php'], 'app.debug' => false]);
});

return $app;
