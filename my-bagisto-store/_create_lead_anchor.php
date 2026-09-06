<?php
// 一次性脚本:创建留资锚点商品(sku=LEAD-CUSTOM)。
// 背景:tob-order 的 custom 线索需挂在一个真实商品上(否则后台订单页渲染会坏)。
// 幂等:已存在则跳过。商品设为 visible_individually=0,不污染前台目录。
// 用法: D:/php.exe _create_lead_anchor.php   (在 my-bagisto-store 目录下)

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Webkul\Product\Repositories\ProductRepository;

$repo = app(ProductRepository::class);

$existing = $repo->findByField('sku', 'LEAD-CUSTOM')->first();
if ($existing) {
    echo "锚点商品已存在 id={$existing->id},补全信息...\n";
}

// 先建壳(type + sku + 属性族),再 update 写名称/价格/可见性(触发 flat/事件,走官方链路)
if (! $existing) {
    $product = $repo->create([
        'type'                => 'simple',
        'attribute_family_id' => 1,
        'sku'                 => 'LEAD-CUSTOM',
    ]);
    $id = $product->id;
} else {
    $id = $existing->id;
}

$repo->update([
    'sku'                 => 'LEAD-CUSTOM',
    'name'                => '定制咨询线索',
    'url_key'             => 'lead-custom',
    'price'               => 0,
    'weight'              => 1,
    'status'              => 1,
    'visible_individually'=> 0, // 后台订单可见,前台目录不展示
    'locale'              => app()->getLocale(),
    'channels'            => [1], // 渠道按 ID 传(外键 product_channels.channel_id)
    'inventories'         => [1 => 0],
], $id);

$check = $repo->findByField('sku', 'LEAD-CUSTOM')->first();
if (! $check) {
    fwrite(STDERR, "创建失败\n");
    exit(1);
}
echo "创建成功 id={$check->id} sku={$check->sku} type={$check->type}\n";
