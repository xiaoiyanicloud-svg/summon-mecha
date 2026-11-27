<?php

declare(strict_types=1);

/**
 * OrderController 前后端对齐隔离回归测试（内存 SQLite）：
 * 覆盖 OrderController::index / guestIndex / show / cancel 的履约态聚合、全量状态计数、搜索、分页与状态取消守卫。
 */
require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/my-bagisto-store/packages/Webkul/Core/src/Http/helpers.php';
require __DIR__.'/scene-sc3-foundation.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Webkul\Shop\Http\Controllers\API\OrderController;

if (! extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "缺少 pdo_sqlite 扩展。\n");
    exit(1);
}

$base = dirname(__DIR__, 2).'/my-bagisto-store';
$app = new Application($base);
$storageRoot = tempnam(sys_get_temp_dir(), 'order-api-test-');
@unlink($storageRoot);
mkdir($storageRoot.'/app', 0755, true);
$app->useStoragePath($storageRoot);
register_shutdown_function(function () use ($storageRoot): void {
    @rmdir($storageRoot.'/app');
    @rmdir($storageRoot);
});

$app->instance('config', new Repository([
    'app'    => ['key' => str_repeat('a', 32), 'locale' => 'zh_CN', 'timezone' => 'UTC'],
    'scenes' => require $base.'/config/scenes.php',
]));

$db = new Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->instance('db.schema', $db->schema());
Facade::setFacadeApplication($app);

$schema = DB::connection()->getSchemaBuilder();
$schema->create('channels', function (Blueprint $t) {
    $t->increments('id');
    $t->string('code')->default('default');
});
$schema->create('customers', function (Blueprint $t) {
    $t->increments('id');
    $t->string('first_name')->nullable();
    $t->string('last_name')->nullable();
    $t->timestamps();
});
$schema->create('products', function (Blueprint $t) {
    $t->increments('id');
    $t->string('type')->default('simple');
    $t->timestamps();
});
$schema->create('category_translations', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('category_id');
    $t->string('slug')->nullable();
});
$schema->create('device_model', function (Blueprint $t) {
    $t->increments('id');
    $t->string('code')->nullable();
    $t->string('name')->nullable();
    $t->timestamps();
});
$schema->create('product_flat', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('product_id');
    $t->string('channel')->default('default');
    $t->integer('status')->default(1);
    $t->integer('visible_individually')->default(1);
});
$schema->create('product_channels', function (Blueprint $t) {
    $t->unsignedInteger('product_id');
    $t->unsignedInteger('channel_id');
});
$schema->create('sellers', function (Blueprint $t) {
    $t->increments('id');
    $t->string('code')->nullable();
    $t->string('name')->nullable();
    $t->string('status')->default('active');
    $t->timestamps();
});
$schema->create('admins', function (Blueprint $t) {
    $t->increments('id');
    $t->string('name')->nullable();
});

sceneSc3Foundation();
sceneSc3Bindings();

$baseCore = $app->make(Webkul\Core\Core::class);
$app->instance(Webkul\Core\Core::class, new class($baseCore)
{
    public function __construct(private object $inner) {}

    public function formatPrice($price, $currencyCode = null): string
    {
        return '¥'.number_format((float) $price, 2);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->{$name}(...$arguments);
    }
});

foreach ([
    '2026_09_30_000002_add_guest_key_to_orders_table.php',
    '2026_11_01_000002_create_portal_backends_and_merchant_foundation_tables.php',
    '2026_11_01_000003_create_merchant_deep_closed_loop_tables.php',
    '2026_11_02_000001_add_cancel_fields_to_merchant_orders.php',
] as $mFile) {
    (require $base.'/packages/Webkul/Shop/src/Database/Migrations/'.$mFile)->up();
}

DB::table('channels')->insert(['id' => 1, 'code' => 'default', 'name' => 'Default']);
DB::table('customers')->insert(['id' => 1, 'first_name' => '张', 'last_name' => '三', 'email' => 'c1@example.test', 'created_at' => now(), 'updated_at' => now()]);
DB::table('products')->insert(['id' => 11, 'type' => 'simple', 'sku' => 'G1', 'created_at' => now(), 'updated_at' => now()]);

$assertSame = static function (mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "ASSERTION FAILED [{$label}]: expected ".var_export($expected, true).' got '.var_export($actual, true)."\n");
        exit(1);
    }
};

$GLOBALS['sc3AuthenticatedCustomer'] = 1;
$ctrl = app(OrderController::class);

$now = '2026-11-28 01:00:00';
$insertOrder = function (array $overrides, string $itemName = '宇树 G1 租赁版') use ($now): int {
    $id = DB::table('orders')->insertGetId(array_merge([
        'increment_id'        => 'SO-'.uniqid(),
        'status'              => 'pending',
        'order_type'          => 'rent',
        'channel_name'        => 'Default',
        'is_guest'            => 0,
        'customer_id'         => 1,
        'customer_email'      => 'c1@example.test',
        'customer_first_name' => '张',
        'customer_last_name'  => '三',
        'total_item_count'    => 1,
        'total_qty_ordered'   => 1,
        'grand_total'         => 1000.00,
        'sub_total'           => 1000.00,
        'tax_amount'          => 0,
        'shipping_amount'     => 0,
        'discount_amount'     => 0,
        'created_at'          => $now,
        'updated_at'          => $now,
    ], $overrides));

    DB::table('order_items')->insert([
        'order_id'    => $id,
        'product_id'  => 11,
        'sku'         => 'SKU-'.$id,
        'type'        => 'simple',
        'name'        => $itemName,
        'qty_ordered' => 1,
        'price'       => 1000.00,
        'total'       => 1000.00,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);

    return $id;
};

// 1. 建立登录客户 4 笔不同态订单 + 1 笔游客单
$oPendingPay = $insertOrder(['increment_id' => 'SO-PEND', 'status' => 'pending_payment', 'created_at' => '2026-11-28 01:01:00'], 'Go2 四足机器');
$oConfirm = $insertOrder(['increment_id' => 'SO-CONF', 'status' => 'processing', 'created_at' => '2026-11-28 01:02:00'], 'T800 机械臂');
$oShipped = $insertOrder(['increment_id' => 'SO-ACTV', 'status' => 'processing', 'created_at' => '2026-11-28 01:03:00'], 'G1 巡检版');
$oCompleted = $insertOrder(['increment_id' => 'SO-DONE', 'status' => 'completed', 'order_type' => 'buy', 'created_at' => '2026-11-28 01:04:00'], '深度相机配件');

DB::table('merchant_orders')->insert([
    [
        'order_no' => 'MO-CONF', 'bagisto_order_id' => $oConfirm, 'seller_id' => 1, 'shop_id' => 1, 'product_id' => 11,
        'fulfillment_type' => 'rental', 'product_name' => 'T800', 'sku_code' => 'SKU-T800', 'buyer_name' => '张三', 'buyer_phone' => '138',
        'shipping_address' => '上海', 'qty' => 1, 'status' => 'pending_shipment', 'carrier' => null, 'tracking_no' => null,
        'created_at' => $now, 'updated_at' => $now,
    ],
    [
        'order_no' => 'MO-ACTV', 'bagisto_order_id' => $oShipped, 'seller_id' => 1, 'shop_id' => 1, 'product_id' => 11,
        'fulfillment_type' => 'rental', 'product_name' => 'G1', 'sku_code' => 'SKU-G1', 'buyer_name' => '张三', 'buyer_phone' => '138',
        'shipping_address' => '上海', 'qty' => 1, 'status' => 'shipped', 'carrier' => '顺丰', 'tracking_no' => 'SF888',
        'created_at' => $now, 'updated_at' => $now,
    ],
]);

$guestKey = 'order-api-guest-key-0123456789ab';
$oGuest = $insertOrder([
    'increment_id' => 'SO-GUEST', 'customer_id' => null, 'is_guest' => 1, 'guest_key' => $guestKey,
    'status' => 'processing', 'created_at' => '2026-11-28 01:05:00',
], '游客租用G1');
DB::table('merchant_orders')->insert([
    'order_no' => 'MO-GUEST', 'bagisto_order_id' => $oGuest, 'seller_id' => 1, 'shop_id' => 1, 'product_id' => 11,
    'fulfillment_type' => 'rental', 'product_name' => 'G1', 'sku_code' => 'SKU-G1', 'buyer_name' => '游客', 'buyer_phone' => '139',
    'shipping_address' => '北京', 'qty' => 1, 'status' => 'in_use', 'created_at' => $now, 'updated_at' => $now,
]);

// 2. 验证分页（limit=2）与全量 counts 不受分页截断
$reqPage1 = Request::create('/api/customer/orders', 'GET', ['limit' => 2, 'page' => 1]);
$resPage1 = $ctrl->index($reqPage1)->toArray($reqPage1);
$assertSame(2, count($resPage1['data']), 'page1 count');
$assertSame(true, $resPage1['has_more'], 'has_more');
$assertSame(4, $resPage1['counts']['total'], 'counts total');
$assertSame(1, $resPage1['counts']['pending'], 'counts pending');
$assertSame(1, $resPage1['counts']['confirm'], 'counts confirm');
$assertSame(1, $resPage1['counts']['active'], 'counts active');
$assertSame(1, $resPage1['counts']['completed'], 'counts completed');
$assertSame('SO-DONE', $resPage1['data'][0]['increment_id'], 'order desc 0');
$assertSame('SO-ACTV', $resPage1['data'][1]['increment_id'], 'order desc 1');
$assertSame('shipped', $resPage1['data'][1]['fulfillment_status'], 'fulfillment_status');
$assertSame('SF888', $resPage1['data'][1]['tracking_no'], 'tracking_no');

$reqPage2 = Request::create('/api/customer/orders', 'GET', ['limit' => 2, 'page' => 2]);
$resPage2 = $ctrl->index($reqPage2)->toArray($reqPage2);
$assertSame(2, count($resPage2['data']), 'page2 count');
$assertSame(false, $resPage2['has_more'], 'page2 has_more');
$assertSame('SO-CONF', $resPage2['data'][0]['increment_id'], 'page2 order 0');
$assertSame('SO-PEND', $resPage2['data'][1]['increment_id'], 'page2 order 1');

$reqShow = Request::create('/api/customer/orders/'.$oShipped, 'GET');
$resShow = $ctrl->show($oShipped, $reqShow)->toArray($reqShow);
$assertSame('shipped', $resShow['data']['fulfillment_status'], 'show fulfillment_status');
$assertSame('顺丰', $resShow['data']['fulfillment']['carrier'], 'show fulfillment carrier');

// 3. 验证状态过滤（status=active 仅命中已发货履约单，不命中 pending_shipment）
$reqActive = Request::create('/api/customer/orders', 'GET', ['status' => 'active']);
$resActive = $ctrl->index($reqActive)->toArray($reqActive);
$assertSame(1, count($resActive['data']), 'status=active count');
$assertSame('SO-ACTV', $resActive['data'][0]['increment_id'], 'status=active id');

// 4. 验证关键词搜索（q=机械臂 命中 T800 机械臂）
$reqSearch = Request::create('/api/customer/orders', 'GET', ['q' => '机械臂']);
$resSearch = $ctrl->index($reqSearch)->toArray($reqSearch);
$assertSame(1, count($resSearch['data']), 'search count');
$assertSame('SO-CONF', $resSearch['data'][0]['increment_id'], 'search id');

// 5. 验证游客列表（空 X-Guest-Key 返回空；合法 X-Guest-Key 返回 in_use 履约态与 counts）
$reqGuestEmpty = Request::create('/api/guest-orders', 'GET');
$resGuestEmpty = $ctrl->guestIndex($reqGuestEmpty)->toArray($reqGuestEmpty);
$assertSame(0, $resGuestEmpty['total'], 'guest empty');
$assertSame(0, $resGuestEmpty['counts']['active'], 'guest empty counts');

$reqGuest = Request::create('/api/guest-orders', 'GET');
$reqGuest->headers->set('X-Guest-Key', $guestKey);
$resGuest = $ctrl->guestIndex($reqGuest)->toArray($reqGuest);
$assertSame(1, $resGuest['total'], 'guest total');
$assertSame('in_use', $resGuest['data'][0]['fulfillment_status'], 'guest in_use');
$assertSame(1, $resGuest['counts']['active'], 'guest active count');

// 6. 验证 pending_payment 允许自助取消，processing 拒绝取消
$cancelPending = json_decode($ctrl->cancel(Request::create('/api/customer/orders/'.$oPendingPay.'/cancel', 'POST'), $oPendingPay)->getContent(), true);
$assertSame(true, $cancelPending['success'], 'cancel pending_payment success');
$assertSame('canceled', $cancelPending['status'], 'cancel pending_payment status');

$cancelConfirm = json_decode($ctrl->cancel(Request::create('/api/customer/orders/'.$oConfirm.'/cancel', 'POST'), $oConfirm)->getContent(), true);
$assertSame(false, $cancelConfirm['success'], 'cancel processing blocked');
$assertSame('ORDER_NOT_CANCELABLE', $cancelConfirm['code'], 'cancel processing code');

echo "ORDER_LIST_API_PASS (6/6 suites)\n";
