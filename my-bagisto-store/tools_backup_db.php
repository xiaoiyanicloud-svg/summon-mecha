<?php
// 业务表备份:导出关键表 JSON 到 .claude/backup/db/YYYY-MM-DD/(在项目根 D:/main 下)。
// 用法: cd my-bagisto-store && D:/php.exe tools_backup_db.php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['orders', 'order_items', 'addresses', 'order_payment', 'products', 'product_flat', 'product_inventories', 'categories', 'category_translations', 'scenes', 'customers'];

$date = date('Y-m-d');
$dir = dirname(__DIR__).'/.claude/backup/db/'.$date;
if (! is_dir($dir)) { mkdir($dir, 0777, true); }

$out = [];
foreach ($tables as $t) {
    if (! Schema::hasTable($t)) { echo "跳过(表不存在): $t\n"; continue; }
    $rows = DB::table($t)->get();
    file_put_contents("$dir/$t.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $out[$t] = count($rows);
    echo str_pad($t, 24)." {$out[$t]} 行\n";
}
file_put_contents("$dir/_manifest.json", json_encode(['date' => $date, 'counts' => $out, 'created' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT));
echo "备份目录: $dir\n";
