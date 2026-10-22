<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\SceneOperationsService;
use Webkul\Shop\Services\Scenes\SceneWorkflowException;

$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$dir = dirname(storage_path());
$fixture = json_decode(file_get_contents($dir.'/fixtures.json'), true, 32, JSON_THROW_ON_ERROR);
$manual = file_get_contents(dirname(__DIR__, 3).'/docs/scenes/SC5-Operations-Runbook.md');
$checks = [];
$assert = function (string $name, bool $ok) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    } $checks[] = ['name' => $name, 'pass' => true];
};
$counts = fn () => array_map(fn ($table) => DB::table($table)->count(), ['operation_log', 'business_outbox', 'business_event_receipts', 'scene_operation_todos']);

preg_match_all('/```sql\r?\n([\s\S]*?)```/', $manual, $sqlBlocks);
$assert('手册包含五组实际告警与排查 SQL', count($sqlBlocks[1]) === 5);
foreach ($sqlBlocks[1] as $index => $sql) {
    $assert('SQL '.($index + 1).' 仅允许单条 SELECT', preg_match('/^\s*SELECT\b/i', $sql) === 1 && count(array_filter(explode(';', $sql), fn ($part) => trim($part) !== '')) === 1);
    $before = $counts();
    DB::select(trim($sql));
    $assert('SQL '.($index + 1).' 在真实 MySQL 执行成功且无业务写入', $before === $counts());
}
preg_match_all('/^php artisan (.+)$/m', $manual, $commands);
$assert('手册包含七条实际 Artisan 命令', count($commands[1]) === 7);
foreach ($commands[1] as $command) {
    $before = $counts();
    $exit = $kernel->handle(new Symfony\Component\Console\Input\StringInput($command), $output = new Symfony\Component\Console\Output\BufferedOutput);
    $assert('实际 Artisan 命令通过：'.$command, $exit === 0);
    if (! str_contains($command, '--consume=100')) {
        $assert('只读命令/缓存清理不修改业务事实：'.$command, $before === $counts());
    }
}
$ops = app(SceneOperationsService::class);
$definition = $kernel->all()['scenes:operations']->getDefinition();
foreach (['check-sla', 'limit', 'json'] as $invalid) {
    $assert('手册明确不存在的参数不在实际命令定义中：'.$invalid, ! $definition->hasOption($invalid));
}
foreach (['consume', 'sweep', 'metrics'] as $valid) {
    $assert('手册参数存在：'.$valid, $definition->hasOption($valid));
}
$before = $counts();
$exit = $kernel->call('scenes:operations', ['--consume' => '1.5', '--sweep' => true]);
$assert('非法消费数量在扫描前失败且无写入', $exit === 1 && $before === $counts());
$schedule = app(Illuminate\Console\Scheduling\Schedule::class);
$event = collect($schedule->events())->first(fn ($event) => str_contains((string) ($event->command ?? ''), 'scenes:operations --consume=100 --sweep'));
$assert('实际 Scheduler 注册与手册一致且防重叠', $event && $event->expression === '* * * * *' && $event->withoutOverlapping);

$eventId = (string) Illuminate\Support\Str::uuid();
$id = DB::table('business_outbox')->insertGetId(['event_id' => $eventId, 'aggregate_type' => 'scene_request', 'aggregate_id' => $fixture['request_id'], 'aggregate_seq' => 1, 'event_type' => 'scene.request.submitted', 'schema_version' => 1, 'payload' => json_encode(['id' => $fixture['request_id'], 'version' => 1]), 'attempts' => 8, 'last_error_code' => 'QA_RECOVERABLE', 'available_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
$actor = $fixture['admins']['author']['id'];
$row = (array) DB::table('business_outbox')->find($id);
DB::unprepared("CREATE TRIGGER scene_p2_fail_audit BEFORE INSERT ON operation_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA_AUDIT_FAILURE'");
try {
    try {
        $ops->replay($id, $actor);
        throw new RuntimeException('审计失败未阻断重放');
    } catch (Illuminate\Database\QueryException $e) {
        $assert('重放使用真实数据库审计失败整体回滚', str_contains($e->getMessage(), 'QA_AUDIT_FAILURE') && $row === (array) DB::table('business_outbox')->find($id));
    }
} finally {
    DB::unprepared('DROP TRIGGER scene_p2_fail_audit');
}
$url = route('admin.catalog.scene-workflow.replay', $id);
$request = Illuminate\Http\Request::create($url, 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
$route = app('router')->getRoutes()->match($request);
$request->setRouteResolver(fn () => $route);
$guard = new Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow;
auth('admin')->loginUsingId($fixture['admins']['viewer']['id']);
try {
    $guard->handle($request, fn () => true);
    throw new RuntimeException('只读角色越权重放');
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $assert('实际 Admin 角色无 operate 权限拒绝重放', $e->getStatusCode() === 403);
}
auth('admin')->loginUsingId($actor);
$response = $guard->handle($request, fn () => app(Webkul\Admin\Http\Controllers\Catalog\SceneWorkflowController::class)->replay($request, $id, $ops));
$assert('实际路由、权限及 Controller 通过受审计重放入口', $response->getStatusCode() === 200 && json_decode($response->getContent(), true)['data']['state'] === 'pending');
$assert('真实重放审计带操作者及目标对象', DB::table('operation_log')->where('action', 'scene.outbox.replayed')->where('actor_id', $actor)->where('target_id', $id)->exists());
$assert('重放重置 attempts、错误码和可用时间', DB::table('business_outbox')->find($id)->attempts === 0 && DB::table('business_outbox')->find($id)->last_error_code === null);
$exit = $kernel->call('scenes:operations', ['--consume' => 100, '--sweep' => true]);
$assert('按手册命令恢复后该事件确实被处理，且仅一份 receipt', $exit === 0 && DB::table('business_outbox')->find($id)->processed_at !== null && DB::table('business_event_receipts')->where('event_id', $eventId)->count() === 1);
try {
    $ops->replay($id, $actor);
    throw new RuntimeException('允许重放已完成事件');
} catch (SceneWorkflowException $e) {
    $assert('已完成事件拒绝重放', $e->errorCode === 'OUTBOX_REPLAY_CONFLICT');
}
DB::table('business_outbox')->where('id', $id)->update(['processed_at' => null, 'lease_until' => now('UTC')->addMinute(), 'lease_token' => 'qa-live-lease']);
try {
    $ops->replay($id, $actor);
    throw new RuntimeException('允许抢占有效租约');
} catch (SceneWorkflowException $e) {
    $assert('有效租约拒绝重放', $e->errorCode === 'OUTBOX_REPLAY_CONFLICT');
}
DB::table('business_outbox')->where('id', $id)->update(['event_type' => 'ecosystem.qa', 'lease_until' => null]);
try {
    $ops->replay($id, $actor);
    throw new RuntimeException('允许重放其他领域');
} catch (SceneWorkflowException $e) {
    $assert('非场景事件拒绝重放', $e->errorCode === 'OUTBOX_SCOPE_FORBIDDEN');
}
$assert('没有遗留数据库事务', DB::transactionLevel() === 0);
file_put_contents($dir.'/runbook-checks.json', json_encode(['environment' => 'complete Laravel/Bagisto + dedicated MySQL, actual Console, Admin guard/controller and real audit failure', 'sql_blocks' => count($sqlBlocks[1]), 'artisan_commands' => count($commands[1]), 'checks' => $checks, 'business_database_touched' => false, 'external_calls' => false, 'http_network_submission' => false], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
echo 'RUNBOOK CHECKS '.count($checks).PHP_EOL;
