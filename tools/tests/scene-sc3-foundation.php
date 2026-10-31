<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

if (! class_exists('DB', false)) {
    class_alias(DB::class, 'DB');
}

function sceneSc3Foundation(): void
{
    $schema = DB::connection()->getSchemaBuilder();
    $schema->table('products', function (Blueprint $t) {
        $t->string('sku')->nullable();
        // 场景事务夹具直接提供目录解析后的属性；本套件不验证 Bagisto EAV 编辑器。
        $t->boolean('manage_stock')->default(false);
        $t->unsignedInteger('parent_id')->nullable();
        $t->unsignedInteger('seller_id')->nullable();
    });
    $schema->table('customers', fn (Blueprint $t) => $t->string('email')->nullable());
    $schema->table('channels', fn (Blueprint $t) => $t->string('name')->nullable());
    $schema->create('cart', fn (Blueprint $t) => $t->increments('id'));
    foreach (['Sales/2018_09_27_113154_create_orders_table', 'Sales/2018_09_27_113207_create_order_items_table',
        'Sales/2018_10_01_095504_create_order_payment_table', 'Core/2020_04_16_185147_add_table_addresses',
        'Core/2024_02_26_153000_add_columns_to_addresses_table', 'Core/2024_03_07_193421_rename_address1_column_in_addresses_table',
        'Sales/2024_04_19_144641_add_incl_tax_columns_in_order_items_table', 'Sales/2024_04_19_102939_add_incl_tax_columns_in_orders_table'] as $path) {
        [$package, $file] = explode('/', $path);
        (require app()->basePath('packages/Webkul/'.$package.'/src/Database/Migrations/'.$file.'.php'))->up();
    }
    $schema->table('orders', fn (Blueprint $t) => $t->string('order_type')->nullable());
    foreach (['product_inventories', 'product_ordered_inventories'] as $table) {
        $schema->create($table, function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('product_id');
            $t->unsignedInteger('channel_id')->nullable();
            $t->unsignedInteger('inventory_source_id')->nullable();
            $t->integer('qty')->default(0);
            $t->timestamps();
            $t->unique(['product_id', 'channel_id']);
        });
    }
    foreach (['product_grouped_products', 'product_bundle_option_products'] as $table) {
        $schema->create($table, function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('product_id');
            $t->unsignedInteger('associated_product_id')->nullable();
        });
    }
    $schema->create('categories', fn (Blueprint $t) => $t->increments('id'));
    $schema->table('category_translations', fn (Blueprint $t) => $t->string('locale')->default('en'));
    $schema->table('device_model', function (Blueprint $t) {
        $t->json('comm_channels')->nullable();
        $t->json('firmware_versions')->nullable();
        $t->string('status')->default('active');
    });
    $schema->table('product_flat', function (Blueprint $t) {
        $t->string('locale')->nullable();
        $t->string('name')->nullable();
        $t->decimal('price', 12, 2)->nullable();
    });
    foreach (['2026_09_30_000001_create_skill_delivery_tables', '2026_10_13_000001_add_v34_columns_to_skill_versions_and_devices',
        '2026_10_13_000004_create_skill_asset_tables', '2026_10_13_000005_create_ops_tables', '2026_10_14_000002_create_blocklist_developer_tables',
        '2026_10_16_000003_add_permissions_to_skill_versions', '2026_10_18_000001_add_compatibility_to_skill_versions', '2026_10_18_000002_create_skill_compatibility_evidence_table'] as $migration) {
        (require app()->basePath('packages/Webkul/Shop/src/Database/Migrations/'.$migration.'.php'))->up();
    }
}

function sceneSc3Bindings(): void
{
    $app = app();
    $app->instance('env', 'testing');
    require_once $app->basePath('packages/Webkul/Core/src/Http/helpers.php');
    if (! class_exists('DB', false)) {
        class_alias(DB::class, 'DB');
    }
    $app->instance('events', new Illuminate\Events\Dispatcher($app));
    $router = new Illuminate\Routing\Router($app['events'], $app);
    $app->instance('router', $router);
    $view = new Illuminate\View\Factory(new Illuminate\View\Engines\EngineResolver, new Illuminate\View\FileViewFinder(new Illuminate\Filesystem\Filesystem, []), $app['events']);
    $url = new Illuminate\Routing\UrlGenerator($router->getRoutes(), Illuminate\Http\Request::create('http://localhost'));
    $app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new Illuminate\Routing\ResponseFactory($view, new Illuminate\Routing\Redirector($url)));
    $app->instance(Illuminate\Contracts\Auth\Factory::class, new class
    {
        public function guard($name = null)
        {
            return new class
            {
                public function user()
                {
                    return Webkul\Customer\Models\Customer::find($GLOBALS['sc3AuthenticatedCustomer'] ?? 1);
                }
            };
        }

        public function shouldUse($name) {}
    });
    DB::connection()->setEventDispatcher($app['events']);
    Illuminate\Database\Eloquent\Model::setEventDispatcher($app['events']);
    DB::connection()->setTransactionManager(new Illuminate\Database\DatabaseTransactionsManager);
    $app->instance('log', new Psr\Log\NullLogger);
    $app->instance('filesystem', new Illuminate\Filesystem\FilesystemManager($app));
    $app->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, new class($app) extends Illuminate\Foundation\Exceptions\Handler
    {
        public function report(Throwable $e): void
        {
            $GLOBALS['sc3Reported'][] = ['class' => get_class($e), 'message' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()];
        }
    });
    $app->singleton(Webkul\Shop\Services\Scenes\SceneOrderWriteContext::class);
    $app->instance('concord', new class
    {
        public function model($contract)
        {
            return str_replace('\\Contracts\\', '\\Models\\', $contract);
        }

        public function getConvention()
        {
            return new class
            {
                public function modelForProxy($proxy)
                {
                    return substr($proxy, 0, -5);
                }

                public function contractForModel($model)
                {
                    return str_replace('\\Models\\', '\\Contracts\\', $model);
                }
            };
        }
    });
    foreach (glob($app->basePath('packages/Webkul/*/src/Contracts/*.php')) as $file) {
        $package = basename(dirname($file, 3));
        $model = 'Webkul\\'.$package.'\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
        if (class_exists($model) && is_subclass_of($model, Illuminate\Database\Eloquent\Model::class) && ! (new ReflectionClass($model))->isAbstract()) {
            $app->bind('Webkul\\'.$package.'\\Contracts\\'.pathinfo($file, PATHINFO_FILENAME), $model);
        }
    }
    $app->instance(Webkul\Core\Core::class, new class
    {
        public function getBaseCurrencyCode()
        {
            return 'CNY';
        }

        public function getCurrentChannel()
        {
            return DB::table('channels')->find(1);
        }

        public function getConfigData($key)
        {
            return null;
        }

        public function getSingletonInstance($class)
        {
            if ($class === Webkul\Attribute\Repositories\AttributeRepository::class) {
                return new class
                {
                    public function getFamilyAttributes($family)
                    {
                        return collect();
                    }
                };
            }

            return app($class);
        }
    });
    config(['repository.cache.enabled' => false, 'scenes.order_conversion_enabled' => true,
        'payment_gateways.default' => 'mock', 'payment_gateways.gateways.mock.class' => Webkul\Shop\Services\Payment\MockGateway::class,
        'filesystems.disks.private' => ['driver' => 'local', 'root' => storage_path('app/private'), 'throw' => true],
        'skill_delivery.default' => 'app', 'skill_delivery.channels.app.class' => Webkul\Shop\Services\SkillDelivery\AppChannel::class,
        'product_types.virtual.class' => Webkul\Product\Type\Virtual::class, 'product_types.simple.class' => Webkul\Product\Type\Simple::class]);
    $app->instance(Webkul\Shop\Services\Deploy\KillSwitchService::class, (new ReflectionClass(Webkul\Shop\Services\Deploy\KillSwitchService::class))->newInstanceWithoutConstructor());
    Event::listen('checkout.order.save.after', [Webkul\Shop\Listeners\Order::class, 'afterCreated']);
    Event::listen('checkout.order.save.after', [Webkul\Shop\Listeners\SkillDelivery::class, 'afterOrderCreated']);
    Event::listen('checkout.order.save.before', [Webkul\Shop\Listeners\SceneOrderTransactionGuard::class, 'beforeCreated']);
    Event::listen('sales.order.cancel.before', [Webkul\Shop\Listeners\SceneOrderTransactionGuard::class, 'beforeCancelled']);
}
