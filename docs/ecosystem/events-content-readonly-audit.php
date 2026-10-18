<?php

declare(strict_types=1);

$base = 'D:/CodexDeploy/summon-mecha/my-bagisto-store';
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$database = $db->selectOne('SELECT DATABASE() AS name')->name;
if ($database !== 'summon_mecha_local') {
    throw new RuntimeException('Unexpected database; read-only audit stopped.');
}
$tables = array_map(fn ($row) => $row->TABLE_NAME, $db->select(
    'SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = ? ORDER BY TABLE_NAME', [$database]
));
$selected = array_values(array_filter($tables, fn ($name) => preg_match('/(^event|^cms|^marketing_events$|^content|^news|^article|^posts?$|^media|^notifications$|^operation_logs$|^admin_alerts$)/', $name)));
$state = ['database'=>$database, 'read_only'=>true, 'app_timezone'=>config('app.timezone'), 'tables'=>[], 'api'=>[]];
foreach ($selected as $table) {
    $state['tables'][$table] = [
        'count'=>$db->table($table)->count(),
        'columns'=>$db->select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.columns WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$database,$table]),
        'indexes'=>$db->select('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$database,$table]),
    ];
}
if (in_array('events', $tables, true)) {
    $state['event_status_counts'] = $db->table('events')->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->get()->all();
}
echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
