<?php

declare(strict_types=1);

// 执行真实 OrderRepository::create；依赖用可注入故障的 SQL 夹具替换。
// 不加载 .env / 业务 bootstrap，不证明真实库存、SKU、监听器或 MySQL 行锁。
require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Webkul\Sales\Contracts\OrderItem as OrderItemContract;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Repositories\DownloadableLinkPurchasedRepository;
use Webkul\Sales\Repositories\OrderItemRepository;
use Webkul\Sales\Repositories\OrderRepository;

$app = new Application(dirname(__DIR__, 2).'/my-bagisto-store');
$app->instance('config', new Repository(['app'=>['timezone'=>'UTC']]));
$app->instance('events', new Dispatcher($app));
$app->instance('log', new Psr\Log\NullLogger);
$db = new Manager($app);
$db->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'foreign_key_constraints'=>true]);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
Facade::setFacadeApplication($app);
if (DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('拒绝非内存数据库。');
}
DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
$tables = ['probe_orders', 'probe_payments', 'probe_addresses', 'probe_items', 'probe_inventory', 'probe_options', 'probe_downloads', 'probe_listener', 'probe_outer'];
foreach ($tables as $table) {
    $db->schema()->create($table, function (Blueprint $t) {
        $t->increments('id');
        $t->integer('order_id')->nullable();
        $t->integer('parent_id')->nullable();
        $t->string('status')->nullable();
        $t->string('increment_id')->nullable();
        $t->string('phase')->nullable();
    });
}

class OrderFault
{
    public static ?string $phase = null;
    public static string $type = 'exception';
    public static int $hits = 0;
    public static int $failures = PHP_INT_MAX;

    public static function hit(string $phase): void
    {
        if (self::$phase !== $phase) {
            return;
        }
        self::$hits++;
        if (self::$hits > self::$failures) {
            return;
        }
        throw match (self::$type) {
            'error' => new Error('TEST_INJECTED_ERROR'),
            'deadlock' => new PDOException('Deadlock found when trying to get lock; try restarting transaction'),
            default => new RuntimeException('TEST_INJECTED_FAILURE'),
        };
    }
}
class ProbeRecord extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected static function booted(): void
    {
        static::created(fn ($row) => OrderFault::hit($row->phase ?? $row->getTable()));
    }
}
class ProbePayment extends ProbeRecord { protected $table = 'probe_payments'; }
class ProbeAddress extends ProbeRecord { protected $table = 'probe_addresses'; }
class ProbeOrder extends ProbeRecord implements Webkul\Sales\Contracts\Order
{
    protected $table = 'probe_orders';
    protected $guarded = ['items', 'payment', 'billing_address', 'shipping_address', 'order_type'];

    public function payment() { return $this->hasOne(ProbePayment::class, 'order_id'); }
    public function addresses() { return $this->hasMany(ProbeAddress::class, 'order_id'); }
}
class ProbeItem extends OrderItem
{
    protected $table = 'probe_items';
    public $timestamps = false;
}
class ProbeItems extends OrderItemRepository
{
    public function __construct() {}

    public function create(array $attributes)
    {
        $row = (new ProbeItem)->newQuery()->create($attributes);
        OrderFault::hit($row->phase);
        return $row;
    }
    public function manageInventory(OrderItemContract $orderItem): void
    {
        DB::table('probe_inventory')->insert(['order_id'=>$orderItem->order_id]);
        OrderFault::hit('inventory');
    }
    public function manageCustomizableOptions(OrderItemContract $orderItem): void
    {
        DB::table('probe_options')->insert(['order_id'=>$orderItem->order_id]);
        OrderFault::hit('options');
    }
}
class ProbeLinks extends DownloadableLinkPurchasedRepository
{
    public function __construct() {}
    public function saveLinks($orderItem)
    {
        DB::table('probe_downloads')->insert(['order_id'=>$orderItem->order_id]);
        OrderFault::hit('downloads');
    }
}
class ProbeOrders extends OrderRepository
{
    public function __construct()
    {
        $this->model = new ProbeOrder;
        $this->orderItemRepository = new ProbeItems;
        $this->downloadableLinkPurchasedRepository = new ProbeLinks;
    }
    public function generateIncrementId() { return bin2hex(random_bytes(8)); }
}

Event::listen('checkout.order.save.before', fn () => OrderFault::hit('before'));
Event::listen('checkout.order.orderitem.save.after', function ($row) {
    DB::table('probe_listener')->insert(['order_id'=>$row->order_id]);
    OrderFault::hit('item_listener');
});
Event::listen('checkout.order.save.after', function ($row) {
    DB::table('probe_listener')->insert(['order_id'=>$row->id]);
    OrderFault::hit('order_listener');
});

$repo = new ProbeOrders;
$input = ['payment'=>['phase'=>'payment'], 'shipping_address'=>['phase'=>'shipping'], 'billing_address'=>['phase'=>'billing'],
    'items'=>[['phase'=>'item_one', 'children'=>[['phase'=>'child']]], ['phase'=>'item_two']]];
$tests = [];
function expectValue($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(json_encode(['expected'=>$expected, 'actual'=>$actual]));
    }
}
function orderCase(string $name, callable $test): void
{
    global $tests, $tables;
    try {
        $test();
        $tests[] = ['name'=>$name, 'pass'=>true];
    } catch (Throwable $e) {
        $tests[] = ['name'=>$name, 'pass'=>false, 'error'=>$e->getMessage()];
    } finally {
        DB::rollBack(0);
        foreach (array_reverse($tables) as $table) {
            DB::table($table)->delete();
        }
        OrderFault::$phase = null;
        OrderFault::$type = 'exception';
        OrderFault::$hits = 0;
        OrderFault::$failures = PHP_INT_MAX;
    }
}
foreach (['exception', 'error'] as $type) {
    foreach (['before', 'probe_orders', 'payment', 'shipping', 'billing', 'item_one', 'item_two', 'child', 'inventory', 'options', 'downloads', 'item_listener', 'order_listener'] as $phase) {
        orderCase($type.':'.$phase.' 回滚所有 SQL 且不重试业务异常', function () use ($repo, $input, $phase, $type, $tables) {
            OrderFault::$phase = $phase;
            OrderFault::$type = $type;
            try {
                $repo->create($input);
                throw new LogicException('未触发故障');
            } catch (Throwable $e) {
                if ($e instanceof LogicException) { throw $e; }
            }
            expectValue(0, DB::transactionLevel());
            foreach ($tables as $table) { expectValue(0, DB::table($table)->count()); }
            expectValue(1, OrderFault::$hits);
        });
    }
}
orderCase('嵌套失败不提交调用方事务，外层可继续写入并完整回滚', function () use ($repo, $input) {
    DB::beginTransaction();
    DB::table('probe_outer')->insert(['phase'=>'outer_before']);
    OrderFault::$phase = 'order_listener';
    try { $repo->create($input); } catch (RuntimeException) {}
    expectValue(1, DB::transactionLevel());
    expectValue(1, DB::table('probe_outer')->count());
    expectValue(0, DB::table('probe_orders')->count());
    DB::table('probe_outer')->insert(['phase'=>'outer_after']);
    DB::rollBack();
    expectValue(0, DB::table('probe_outer')->count());
});
orderCase('嵌套成功仍由外层决定提交，afterCommit 不提前触发', function () use ($repo, $input) {
    $called = 0;
    DB::beginTransaction();
    DB::afterCommit(function () use (&$called) { $called++; });
    $order = $repo->create($input);
    expectValue(true, $order->id > 0);
    expectValue(1, DB::transactionLevel());
    expectValue(0, $called);
    DB::rollBack();
    expectValue(0, DB::table('probe_orders')->count());
    expectValue(0, $called);
    DB::beginTransaction();
    DB::afterCommit(function () use (&$called) { $called++; });
    $repo->create($input);
    DB::commit();
    expectValue(1, $called);
    expectValue(1, DB::table('probe_orders')->count());
    expectValue(3, DB::table('probe_items')->count());
    expectValue(0, DB::transactionLevel());
});
orderCase('顶层并发错误最多三次；重试重新执行全部 SQL，不留半单', function () use ($repo, $input) {
    OrderFault::$phase = 'order_listener';
    OrderFault::$type = 'deadlock';
    OrderFault::$failures = 2;
    $repo->create($input);
    expectValue(3, OrderFault::$hits);
    expectValue(1, DB::table('probe_orders')->count());
    expectValue(3, DB::table('probe_items')->count());
    expectValue(0, DB::transactionLevel());
});
orderCase('并发错误超过重试上限向外抛出且无残留', function () use ($repo, $input, $tables) {
    OrderFault::$phase = 'order_listener';
    OrderFault::$type = 'deadlock';
    try { $repo->create($input); throw new LogicException('未传播并发错误'); } catch (PDOException) {}
    expectValue(3, OrderFault::$hits);
    expectValue(0, DB::transactionLevel());
    foreach ($tables as $table) { expectValue(0, DB::table($table)->count()); }
});
$failed = count(array_filter($tests, fn ($row) => ! $row['pass']));
echo json_encode(['environment'=>'isolated SQLite :memory:', 'production_method'=>'OrderRepository::create', 'collaborators'=>'SQL fixtures, not production inventory/listeners', 'tests'=>$tests, 'passed'=>count($tests)-$failed, 'failed'=>$failed, 'mysql_verified'=>false], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed ? 1 : 0);
