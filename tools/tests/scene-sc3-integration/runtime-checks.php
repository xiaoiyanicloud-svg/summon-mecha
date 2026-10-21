<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$dir = dirname(storage_path());
$f = json_decode(file_get_contents($dir.'/fixtures.json'), true, 32, JSON_THROW_ON_ERROR);
$receipt = json_decode(file_get_contents($dir.'/receipt.json'), true, 32, JSON_THROW_ON_ERROR);
$checks = [];
$assert = function (string $name, bool $ok) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    } $checks[] = ['name' => $name, 'pass' => true];
};
$counts = fn () => array_map(fn ($t) => DB::table($t)->count(), ['operation_log', 'business_outbox', 'business_event_receipts', (new Webkul\Shop\Models\Notification)->getTable()]);
$before = $counts();
$code = $kernel->call('scenes:operations');
$report = json_decode($kernel->output(), true, 32, JSON_THROW_ON_ERROR);
$assert('真实运维命令只读报告反映已启用转换及完整 SC3 表', $code === 0 && $report['missing_tables'] === [] && $report['order_conversion'] === 'enabled' && $report['features']['order_conversion_enabled'] === true);
$assert('只读命令不改变审计、outbox、通知或回执', $before === $counts());
$code = $kernel->call('scenes:operations', ['--consume' => '1.5', '--sweep' => true]);
$assert('非法消费参数在扫描前拒绝且不写任何事实', $code === 1 && $before === $counts());
$schedule = app(Illuminate\Console\Scheduling\Schedule::class);
$event = collect($schedule->events())->first(fn ($event) => str_contains((string) ($event->command ?? ''), 'scenes:operations --consume=100 --sweep'));
$assert('生产注册的场景事件按分钟调度并防止重叠', $event !== null && $event->expression === '* * * * *' && $event->withoutOverlapping);
config(['scenes.operations_enabled' => false]);
$assert('场景调度受实际运维开关限制', ! $event->filtersPass($app));
config(['scenes.operations_enabled' => true]);
$event->run($app);
$scheduled = is_file($dir.'/scheduled-consumer.json') ? json_decode(file_get_contents($dir.'/scheduled-consumer.json'), true, 32, JSON_THROW_ON_ERROR) : null;
$assert('真实 Scheduler 事件启动隔离 Console 子进程执行消费者', $event->exitCode === 0 && $scheduled !== null && $scheduled['exit_code'] === 0 && $scheduled['pid'] !== getmypid());
$assert('完整 EAV 无测试专用商品属性列', ! Illuminate\Support\Facades\Schema::hasColumn('products', 'manage_stock') && DB::table('product_attribute_values')->count() > 0 && Webkul\Product\Models\Product::find($f['products']['service'])->manage_stock == false);
$assert('只有一份正式订单、项目及预留关联', DB::table('orders')->count() === 1 && DB::table('scene_projects')->count() === 1 && DB::table('scene_order_links')->where('quote_id', $f['quote_id'])->count() === 1 && DB::table('scene_reservations')->where('quote_id', $f['quote_id'])->where('state', 'confirmed')->count() === 1);
$assert('服务确认没有伪造在线收款或技能授权', DB::table('scene_projects')->find($receipt['project_id'])->payment_state === 'unpaid' && DB::table('orders')->find($receipt['order_id'])->status === 'pending' && DB::table('skill_entitlements')->count() === 0);
$assert('失败和中断没有遗留事务', DB::transactionLevel() === 0);
file_put_contents($dir.'/runtime-checks.json', json_encode(['checks' => $checks, 'operations_report' => $report, 'database' => DB::selectOne('SELECT DATABASE() AS name, VERSION() AS version, @@tx_isolation AS isolation')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'RUNTIME CHECKS '.count($checks).PHP_EOL;
