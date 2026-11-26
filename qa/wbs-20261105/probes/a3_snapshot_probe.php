<?php

/**
 * A3 证据探针：打印 GaOperationalMetrics 的真实快照（只读）。
 *
 * 用法：DB_DATABASE=summon_mecha_step10_test D:/php.exe qa/wbs-20261105/probes/a3_snapshot_probe.php > .../a3-metrics-snapshot.json
 */
require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$snapshot = app(Webkul\Shop\Services\Deploy\GaOperationalMetrics::class)->snapshot();

echo json_encode([
    'probe'          => 'a3_snapshot_probe',
    'executed_at'    => gmdate('c'),
    'db'             => DB::connection()->getDatabaseName(),
    'snapshot'       => $snapshot,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
