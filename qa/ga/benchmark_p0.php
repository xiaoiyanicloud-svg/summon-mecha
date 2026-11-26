<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeployInboxEvent;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployInboxService;
use Webkul\Shop\Services\Deploy\DeployTaskService;

require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/my-bagisto-store/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = DB::connection()->getDatabaseName();
if ($db !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "Refusing non-test database: {$db}\n");
    exit(2);
}

$suffix = bin2hex(random_bytes(6));
$did = 'did_ga_bench_'.$suffix;
$productId = DB::table('products')->insertGetId([
    'sku' => 'ga-bench-'.$suffix,
    'type' => 'simple',
    'created_at' => now(),
    'updated_at' => now(),
]);
$version = SkillVersion::create([
    'product_id' => $productId,
    'version' => '1.0.0',
    'channel' => 'stable',
    'status' => SkillVersion::STATUS_PUBLISHED,
    'license_mode' => 'per_device',
]);
$binding = DeviceBinding::create([
    'did' => $did,
    'sn' => 'SN-'.$did,
    'source' => 'agent',
    'bind_status' => 'bound',
    'bound_at' => now(),
]);

$service = app(DeployTaskService::class);
$commandLatencies = [];
$firstTask = null;
for ($i = 0; $i < 1000; $i++) {
    $start = hrtime(true);
    $firstTask = $service->create(
        did: $did,
        skillId: 990000,
        skillVersionId: $version->id,
        entitlementId: null,
        operation: DeployTask::OP_ENABLE,
        protocolVersion: DeployTask::PROTOCOL_V2,
        createdBy: 'ga-benchmark',
    );
    $commandLatencies[] = (hrtime(true) - $start) / 1e6;
}

$taskCount = DeployTask::where('did', $did)->count();
$inbox = app(DeployInboxService::class);
$ackLatencies = [];
$firstAck = null;
for ($i = 0; $i < 1000; $i++) {
    $start = hrtime(true);
    $firstAck = $inbox->acknowledge([
        'task_no' => $firstTask->task_no,
        'did' => $did,
        'event_id' => 'evt_ga_bench_'.$suffix,
        'stage' => 'received_durable',
    ]);
    $ackLatencies[] = (hrtime(true) - $start) / 1e6;
}

$eventCount = DeployInboxEvent::where('did', $did)->count();
sort($commandLatencies);
sort($ackLatencies);
$percentile = fn (array $values, float $q): float => $values[(int) floor($q * (count($values) - 1))];
$cpZeroUpper = fn (int $n): float => 1.0 - pow(0.05, 1.0 / $n);
$commandFailures = max(0, $taskCount - 1);
$ackFailures = max(0, $eventCount - 1);

$result = [
    'database' => $db,
    'duplicate_commands_attempted' => 1000,
    'effective_task_count' => $taskCount,
    'duplicate_acks_attempted' => 1000,
    'effective_inbox_event_count' => $eventCount,
    'command_n' => count($commandLatencies),
    'command_failures_k' => $commandFailures,
    'command_error_rate' => round($commandFailures / max(1, count($commandLatencies)), 9),
    'command_failure_rate_cp95_upper' => $commandFailures === 0 ? round($cpZeroUpper(count($commandLatencies)), 9) : null,
    'ack_n' => count($ackLatencies),
    'ack_failures_k' => $ackFailures,
    'ack_error_rate' => round($ackFailures / max(1, count($ackLatencies)), 9),
    'ack_failure_rate_cp95_upper' => $ackFailures === 0 ? round($cpZeroUpper(count($ackLatencies)), 9) : null,
    'command_latency_ms' => [
        'p50' => round($percentile($commandLatencies, 0.50), 3),
        'p95' => round($percentile($commandLatencies, 0.95), 3),
        'p99' => round($percentile($commandLatencies, 0.99), 3),
        'max' => round(max($commandLatencies), 3),
    ],
    'ack_latency_ms' => [
        'p50' => round($percentile($ackLatencies, 0.50), 3),
        'p95' => round($percentile($ackLatencies, 0.95), 3),
        'p99' => round($percentile($ackLatencies, 0.99), 3),
        'max' => round(max($ackLatencies), 3),
    ],
    'duplicate_ack_same_idempotent_result' => ($firstAck['duplicate'] ?? false) === true,
];
$result['passed'] = $taskCount === 1 && $eventCount === 1 && $result['duplicate_ack_same_idempotent_result'];

DeployInboxEvent::where('did', $did)->delete();
DeployTask::where('did', $did)->delete();
$binding->delete();
$version->delete();
DB::table('products')->where('id', $productId)->delete();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['passed'] ? 0 : 1);