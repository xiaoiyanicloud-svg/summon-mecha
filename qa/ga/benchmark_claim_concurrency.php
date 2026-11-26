<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;

require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/my-bagisto-store/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = DB::connection()->getDatabaseName();
if ($db !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "Refusing non-test database: {$db}\n");
    exit(2);
}

$suffix = bin2hex(random_bytes(6));
$did = 'did_claim_race_'.$suffix;
$binding = DeviceBinding::create([
    'did' => $did,
    'sn' => 'SN-'.$did,
    'source' => 'agent',
    'bind_status' => 'bound',
    'bound_at' => now(),
]);
$task = DeployTask::create([
    'task_no' => 'RACE-'.$suffix,
    'command_seq' => 1,
    'did' => $did,
    'channel' => 'agent',
    'protocol_version' => DeployTask::PROTOCOL_V2,
    'skill_id' => 1,
    'operation' => DeployTask::OP_INSTALL,
    'status' => 'pending_online',
]);

$script = __DIR__.'/claim_worker.php';
$processes = [];
for ($i = 0; $i < 2; $i++) {
    $processes[] = proc_open(
        [PHP_BINARY, $script, $did],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes[$i],
        dirname(__DIR__, 2),
        array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'summon_mecha_step10_test', 'LOG_CHANNEL' => 'stderr'])
    );
}

$results = [];
foreach ($processes as $i => $process) {
    $stdout = stream_get_contents($pipes[$i][1]);
    $stderr = stream_get_contents($pipes[$i][2]);
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);
    $code = proc_close($process);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
    $results[] = ['exit' => $code, 'output' => $lines ? json_decode(end($lines), true) : null, 'stderr' => $stderr];
}

$claimCount = count(array_filter($results, fn ($r) => ($r['output']['claimed'] ?? false) === true));
$fresh = $task->fresh();
$result = [
    'database' => $db,
    'workers' => 2,
    'effective_claims' => $claimCount,
    'delivery_attempt' => $fresh->delivery_attempt,
    'worker_results' => $results,
    'passed' => $claimCount === 1 && $fresh->delivery_attempt === 1,
];

DeployTask::where('did', $did)->delete();
$binding->delete();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['passed'] ? 0 : 1);