<?php

declare(strict_types=1);

/**
 * RaaS 分类目录（tob -> rent/buy）与品牌主数据系统隔离回归测试。
 *
 * 硬隔离：不加载 .env / bootstrap/app.php，仅运行内存 SQLite（:memory:），
 * 验证迁移、taxonomy 安装、Brand CRUD/校验/删除保护、EAV 对齐及商品聚合注入。
 */
require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/my-bagisto-store/packages/Webkul/Core/src/Http/helpers.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use Webkul\Admin\Http\Controllers\Catalog\CatalogBrandController;
use Webkul\Category\Models\Category;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Shop\Http\Controllers\API\BrandController;
use Webkul\Shop\Models\CatalogBrand;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Services\Catalog\CatalogBrandService;

if (! extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "缺少 pdo_sqlite 扩展，无法运行隔离测试。\n");
    exit(1);
}

$base = dirname(__DIR__, 2).'/my-bagisto-store';
$app = new Application($base);
$storageRoot = tempnam(sys_get_temp_dir(), 'raas-brand-test-');
@unlink($storageRoot);
mkdir($storageRoot.'/app/public', 0755, true);
$app->useStoragePath($storageRoot);

register_shutdown_function(function () use ($storageRoot): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storageRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($storageRoot);
});

$app->instance('config', new Repository([
    'app' => ['key' => str_repeat('a', 32), 'locale' => 'zh_CN', 'fallback_locale' => 'zh_CN'],
    'filesystems' => [
        'default' => 'public',
        'disks'   => [
            'public' => [
                'driver' => 'local',
                'root'   => $storageRoot.'/app/public',
                'url'    => '/storage',
            ],
        ],
    ],
]));

$db = new Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->instance('db.schema', $db->schema());
$app->instance('files', new Filesystem);
$app->instance('filesystem', new FilesystemManager($app));
Facade::setFacadeApplication($app);

$validator = new ValidatorFactory(new Translator(new ArrayLoader, 'zh_CN'), $app);
$validator->setPresenceVerifier(new DatabasePresenceVerifier($db->getDatabaseManager()));
$app->instance('validator', $validator);
$app->instance('translator', $validator->getTranslator());

Request::macro('validate', function (array $rules, array $messages = [], array $customAttributes = []) use ($app) {
    /** @var Request $this */
    return $app->make('validator')->validate($this->all(), $rules, $messages, $customAttributes);
});

$routes = new \Illuminate\Routing\RouteCollection;
foreach ([
    'admin/catalog/brands'         => 'admin.catalog.brands.index',
    'admin/catalog/brands/create'  => 'admin.catalog.brands.create',
] as $uri => $name) {
    $route = new \Illuminate\Routing\Route(['GET'], $uri, fn () => '');
    $route->name($name);
    $routes->add($route);
}
$url = new UrlGenerator($routes, Request::create('http://localhost/'));
$app->instance('url', $url);
$session = new SessionStore('test', new ArraySessionHandler(120));
$session->start();
$app->instance('session', $session);
$redirector = new Redirector($url);
$redirector->setSession($session);
$app->instance('redirect', $redirector);
$viewStub = new class implements \Illuminate\Contracts\View\Factory {
    public function exists($view) { return false; }
    public function file($path, $data = [], $mergeData = []) { throw new \RuntimeException('stub'); }
    public function make($view, $data = [], $mergeData = []) { throw new \RuntimeException('stub'); }
    public function share($key, $value = null) { return null; }
    public function composer($views, $callback) { return []; }
    public function creator($views, $callback) { return []; }
    public function addNamespace($namespace, $hints) { return $this; }
    public function replaceNamespace($namespace, $hints) { return $this; }
};
$app->instance('view', $viewStub);
$app->instance(\Illuminate\Contracts\View\Factory::class, $viewStub);
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new \Illuminate\Routing\ResponseFactory($viewStub, $redirector));

// 建立隔离 Schema
$schema = $db->schema();
$schema->create('locales', function (Blueprint $t) {
    $t->increments('id');
    $t->string('code', 16)->unique();
    $t->string('name', 64);
});
DB::table('locales')->insert([
    ['code' => 'zh_CN', 'name' => '中文'],
    ['code' => 'en', 'name' => 'English'],
]);

$schema->create('categories', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('position')->default(0);
    $t->boolean('status')->default(1);
    $t->string('display_mode', 64)->default('products_and_description');
    $t->unsignedInteger('_lft')->default(0);
    $t->unsignedInteger('_rgt')->default(0);
    $t->unsignedInteger('parent_id')->nullable();
    $t->timestamps();
});
$schema->create('category_translations', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('category_id');
    $t->string('name');
    $t->string('slug');
    $t->string('url_path')->nullable();
    $t->string('locale', 16);
});

$rootId = DB::table('categories')->insertGetId(['position' => 1, 'status' => 1, '_lft' => 1, '_rgt' => 2, 'parent_id' => null]);
DB::table('category_translations')->insert([
    ['category_id' => $rootId, 'name' => 'Root', 'slug' => 'root', 'url_path' => '', 'locale' => 'zh_CN'],
    ['category_id' => $rootId, 'name' => 'Root', 'slug' => 'root', 'url_path' => '', 'locale' => 'en'],
]);
// 原扁平分类：rent 与 buy 先挂在 root 下，验证 installRaaSTaxonomy 会自动归并到 tob 下
$legacyRentId = DB::table('categories')->insertGetId(['position' => 1, 'status' => 1, '_lft' => 3, '_rgt' => 4, 'parent_id' => $rootId]);
DB::table('category_translations')->insert(['category_id' => $legacyRentId, 'name' => '租赁', 'slug' => 'rent', 'url_path' => 'rent', 'locale' => 'zh_CN']);
$legacyBuyId = DB::table('categories')->insertGetId(['position' => 2, 'status' => 1, '_lft' => 5, '_rgt' => 6, 'parent_id' => $rootId]);
DB::table('category_translations')->insert(['category_id' => $legacyBuyId, 'name' => '购买', 'slug' => 'buy', 'url_path' => 'buy', 'locale' => 'zh_CN']);

$schema->create('attributes', function (Blueprint $t) {
    $t->increments('id');
    $t->string('code')->unique();
    $t->string('admin_name');
    $t->string('type');
    $t->boolean('is_required')->default(0);
    $t->boolean('is_unique')->default(0);
    $t->boolean('is_filterable')->default(0);
    $t->boolean('is_configurable')->default(0);
    $t->boolean('is_user_defined')->default(1);
    $t->boolean('is_visible_on_front')->default(1);
    $t->boolean('use_in_flat')->default(1);
    $t->timestamps();
});
$schema->create('attribute_translations', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('attribute_id');
    $t->string('locale', 16);
    $t->string('name');
});
$schema->create('attribute_families', function (Blueprint $t) {
    $t->increments('id');
    $t->string('code');
    $t->string('name');
});
$schema->create('attribute_groups', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('attribute_family_id');
    $t->string('name');
    $t->integer('position')->default(1);
});
$schema->create('attribute_group_mappings', function (Blueprint $t) {
    $t->unsignedInteger('attribute_id');
    $t->unsignedInteger('attribute_group_id');
    $t->integer('position')->default(1);
});
$schema->create('attribute_options', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('attribute_id');
    $t->string('admin_name');
    $t->integer('sort_order')->default(0);
});
$schema->create('attribute_option_translations', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('attribute_option_id');
    $t->string('locale', 16);
    $t->string('label');
});

$brandAttrId = DB::table('attributes')->insertGetId(['code' => 'brand', 'admin_name' => 'Brand', 'type' => 'select', 'is_filterable' => 1]);
$famId = DB::table('attribute_families')->insertGetId(['code' => 'default', 'name' => 'Default']);
$grpId = DB::table('attribute_groups')->insertGetId(['attribute_family_id' => $famId, 'name' => 'General', 'position' => 1]);

$schema->create('device_model', function (Blueprint $t) {
    $t->increments('id');
    $t->string('brand', 64);
    $t->string('model_code', 64);
    $t->string('model_name', 120);
    $t->integer('degrees_of_freedom')->default(0);
    $t->string('compute_platform', 64)->nullable();
    $t->json('comm_channels')->nullable();
    $t->string('status', 32)->default('active');
    $t->integer('sort_order')->default(100);
});
DB::table('device_model')->insert([
    ['brand' => 'unitree', 'model_code' => 'g1', 'model_name' => 'Unitree G1', 'degrees_of_freedom' => 23, 'compute_platform' => 'Orin NX', 'comm_channels' => json_encode(['DDS', 'Agent']), 'status' => 'active', 'sort_order' => 1],
    ['brand' => 'unitree', 'model_code' => 'go2', 'model_name' => 'Unitree Go2', 'degrees_of_freedom' => 12, 'compute_platform' => 'Jetson', 'comm_channels' => json_encode(['ROS2']), 'status' => 'inactive', 'sort_order' => 2],
]);

$schema->create('products', function (Blueprint $t) {
    $t->increments('id');
    $t->string('sku')->unique();
    $t->string('name')->nullable();
});
$schema->create('product_attribute_values', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('product_id');
    $t->unsignedInteger('attribute_id');
    $t->string('channel')->nullable();
    $t->string('locale')->nullable();
    $t->string('unique_id')->nullable()->unique();
    $t->unsignedInteger('integer_value')->nullable();
    $t->string('text_value')->nullable();
});
$schema->create('merchant_product_exts', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('product_id');
    $t->string('fulfillment_type', 32)->nullable();
    $t->json('compatible_models')->nullable();
    $t->json('param_specs')->nullable();
    $t->integer('daily_rent_cents')->default(0);
    $t->integer('deposit_cents')->default(0);
    $t->integer('min_rent_days')->default(1);
    $t->integer('rentable_stock')->default(0);
    $t->integer('sellable_stock')->default(0);
});
$schema->create('merchant_rental_plans', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('product_id');
    $t->string('name', 120);
    $t->string('period_unit', 32)->default('day');
    $t->integer('min_days')->default(1);
    $t->integer('max_days')->default(365);
    $t->integer('base_price_cents')->default(0);
    $t->boolean('enabled')->default(1);
});
$schema->create('orders', function (Blueprint $t) {
    $t->increments('id');
    $t->string('status', 32);
});
$schema->create('order_items', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('order_id');
    $t->unsignedInteger('product_id');
    $t->integer('qty_invoiced')->default(0);
    $t->integer('qty_refunded')->default(0);
});

// 轻量 CategoryRepository 桩：避免拉起整套 Bagisto 核心事件总线，仍完整操作真实 Category 模型与翻译树
$app->instance(CategoryRepository::class, new class {
    public function create(array $data): Category
    {
        $cat = new Category;
        $cat->parent_id = $data['parent_id'];
        $cat->status = $data['status'] ?? 1;
        $cat->position = $data['position'] ?? 0;
        $cat->display_mode = $data['display_mode'] ?? 'products_and_description';
        $cat->_lft = 0;
        $cat->_rgt = 0;
        $cat->save();
        foreach (['zh_CN', 'en'] as $loc) {
            if (! empty($data[$loc])) {
                DB::table('category_translations')->insert([
                    'category_id' => $cat->id,
                    'locale'      => $loc,
                    'name'        => $data[$loc]['name'],
                    'slug'        => $data[$loc]['slug'],
                    'url_path'    => $data[$loc]['url_path'] ?? $data[$loc]['slug'],
                ]);
            }
        }
        return $cat;
    }
});

function assertTrue(bool $cond, string $msg): void
{
    if (! $cond) {
        throw new RuntimeException('ASSERTION FAILED: '.$msg);
    }
}

// 1. 执行真实迁移文件并检验幂等性
$migration = require $base.'/packages/Webkul/Shop/src/Database/Migrations/2026_11_25_000001_create_catalog_brands_table.php';
$migration->up();
$migration->up(); // 第二次幂等不报错
assertTrue(Schema::hasTable('catalog_brands'), 'catalog_brands 表应已创建');

$service = new CatalogBrandService;

// 2. 未跑种子前兜底返回 TOP 20 品牌
$fallback = $service->publicMetadata();
assertTrue(count($fallback['brands']) === 20, '未入库兜底应返回 20 个初始品牌');
assertTrue(count($fallback['robot_types']) === 8, '形态枚举应包含 8 种机器人形态');

// 3. Dry-run 与正式执行 installRaaSTaxonomy
$dry = $service->installRaaSTaxonomy(false);
assertTrue(CatalogBrand::count() === 0, 'dry-run 不应写入数据库');
assertTrue(DB::table('attributes')->where('code', 'robot_type')->count() === 0, 'dry-run 不应创建 robot_type');

$report = $service->installRaaSTaxonomy(true);
assertTrue(CatalogBrand::count() === 20, 'apply 后应种子化 20 个品牌');

$tobId = (int) DB::table('category_translations')->where('slug', 'tob')->value('category_id');
$rentCat = Category::findOrFail($legacyRentId);
$buyCat = Category::findOrFail($legacyBuyId);
assertTrue($tobId > 0 && (int) $rentCat->parent_id === $tobId && (int) $buyCat->parent_id === $tobId, 'rent 与 buy 必须安全归并到 tob 子节点下且保留原 slug');
assertTrue(DB::table('categories')->where('id', $rentCat->id)->exists(), '原 rent ID 保持不变，不破坏既有关联');
$rentAncestorIds = $rentCat->ancestors()->pluck('id')->map(fn ($id) => (int) $id)->all();
assertTrue(in_array($tobId, $rentAncestorIds, true), 'tob 应位于 rent 的 nestedset ancestors 路径中');
assertTrue(DB::table('category_translations')->where('category_id', $rentCat->id)->value('slug') === 'rent', 'rent 自身 slug 必须维持为 rent');
assertTrue(DB::table('category_translations')->where('category_id', $buyCat->id)->value('slug') === 'buy', 'buy 自身 slug 必须维持为 buy');

$robotTypeAttrId = (int) DB::table('attributes')->where('code', 'robot_type')->value('id');
assertTrue($robotTypeAttrId > 0, '应已安装 robot_type 属性');
assertTrue(DB::table('attribute_options')->where('attribute_id', $robotTypeAttrId)->count() === 8, 'robot_type 应同步 8 个形态选项');
assertTrue(DB::table('attribute_group_mappings')->where('attribute_id', $robotTypeAttrId)->where('attribute_group_id', $grpId)->exists(), 'robot_type 应挂载到默认属性组');
assertTrue(DB::table('attribute_options')->where('attribute_id', $brandAttrId)->count() === 20, 'brand 属性应同步 20 个品牌选项');

// 4. 测试 CatalogBrandController CRUD、EAV 同步、校验与删除保护
$controller = new CatalogBrandController($service);

// 4.1 创建新品牌 + 表单校验
$badReq = Request::create('/admin/catalog/brands/create', 'POST', ['code' => 'bad code!', 'name' => '非法']);
$badCaught = false;
try {
    $controller->store($badReq);
} catch (ValidationException $e) {
    $badCaught = true;
}
assertTrue($badCaught, '非法品牌编码必须被验证规则拦截');

$createReq = Request::create('/admin/catalog/brands/create', 'POST', [
    'code'         => 'deeprobotics',
    'name'         => '云深处',
    'name_en'      => 'DEEP Robotics',
    'aliases'      => '云深处, 绝影, X30',
    'device_brand' => 'deeprobotics',
    'sort_order'   => 25,
    'is_hot'       => '1',
    'status'       => '1',
]);
$controller->store($createReq);
$deep = CatalogBrand::where('code', 'deeprobotics')->first();
assertTrue($deep !== null && (int) $deep->attribute_option_id > 0, '新建品牌应自动写入 attribute_option_id 并同步至 EAV');
assertTrue(DB::table('attribute_options')->where('id', $deep->attribute_option_id)->value('admin_name') === '云深处', 'EAV 选项名称应同步为 云深处');

// 4.2 停用状态 -> 前台 API 应立即隐藏该品牌
$controller->toggleStatus(Request::create('/', 'POST', ['status' => false]), $deep->id);
$api = new BrandController;
$publicPayload = $api->index($service)->getData(true)['data'];
assertTrue(collect($publicPayload['brands'])->where('code', 'deeprobotics')->isEmpty(), '停用品牌不应出现在公开 GET /api/brands 响应中');

// 4.3 重新启用并修改名称 + toggleHot
$controller->toggleStatus(Request::create('/', 'POST', ['status' => true]), $deep->id);
$controller->toggleHot($deep->id);
$controller->update(Request::create('/', 'PUT', [
    'code'       => 'deeprobotics',
    'name'       => '云深处科技',
    'name_en'    => 'DEEP Robotics',
    'aliases'    => '云深处, 绝影',
    'sort_order' => 25,
    'status'     => '1',
]), $deep->id);
$deep->refresh();
assertTrue(! $deep->is_hot, 'toggleHot 应切换热门状态');
assertTrue(DB::table('attribute_options')->where('id', $deep->attribute_option_id)->value('admin_name') === '云深处科技', '编辑品牌后 EAV option 名称应同步更新');

// 4.4 删除保护：关联商品或 device_model 时必须 422 拒绝，解绑后可正常删除并清理 EAV option
$yushu = CatalogBrand::where('code', 'yushu')->firstOrFail();
$delYushu = $controller->destroy($yushu->id);
assertTrue($delYushu->getStatusCode() === 422, '关联 device_model(unitree) 的品牌必须返回 422 禁止删除');

$p1Id = DB::table('products')->insertGetId(['sku' => 'DEEP-X30', 'name' => '绝影 X30 四足巡检']);
DB::table('product_attribute_values')->insert([
    'product_id'    => $p1Id,
    'attribute_id'  => $brandAttrId,
    'integer_value' => $deep->attribute_option_id,
]);
$delDeepBlocked = $controller->destroy($deep->id);
assertTrue($delDeepBlocked->getStatusCode() === 422, '已被商品引用的品牌必须返回 422 禁止删除');

DB::table('product_attribute_values')->where('product_id', $p1Id)->where('attribute_id', $brandAttrId)->delete();
$optToDelete = $deep->attribute_option_id;
$delDeepOk = $controller->destroy($deep->id);
assertTrue($delDeepOk->getStatusCode() === 200, '无引用的品牌允许删除');
assertTrue(CatalogBrand::find($deep->id) === null, '删除后记录应不存在');
assertTrue(! DB::table('attribute_options')->where('id', $optToDelete)->exists(), '删除无引用品牌时应同步回收其 EAV attribute_option');

// 5. 测试 CatalogBrandService::attachCatalogMeta（真实销量计算 + EAV/回退双路径 + RaaS 元信息）
$pUnitreeId = DB::table('products')->insertGetId(['sku' => 'UNI-G1', 'name' => 'G1 租用机器人']); // 商品名故意不写“宇树”，测 EAV 驱动
$pFallbackId = DB::table('products')->insertGetId(['sku' => 'X30-DOG', 'name' => '智元 远征 A2 机器']); // 测商品名别名与形态回退
$pUnclassifiedId = DB::table('products')->insertGetId(['sku' => 'RAW-01', 'name' => '实验原型测试机']);

$humanoidOptId = (int) DB::table('attribute_options')->where('attribute_id', $robotTypeAttrId)->where('admin_name', '人形')->value('id');
DB::table('product_attribute_values')->insert([
    ['product_id' => $pUnitreeId, 'attribute_id' => $brandAttrId, 'integer_value' => $yushu->attribute_option_id],
    ['product_id' => $pUnitreeId, 'attribute_id' => $robotTypeAttrId, 'integer_value' => $humanoidOptId],
]);
DB::table('merchant_product_exts')->insert([
    'product_id'       => $pUnitreeId,
    'fulfillment_type' => 'rent',
    'daily_rent_cents' => 280000,
    'deposit_cents'    => 1000000,
    'min_rent_days'    => 3,
    'rentable_stock'   => 5,
    'sellable_stock'   => 0,
]);

$oCompleted = DB::table('orders')->insertGetId(['status' => 'completed']);
$oCanceled = DB::table('orders')->insertGetId(['status' => 'canceled']);
DB::table('order_items')->insert([
    ['order_id' => $oCompleted, 'product_id' => $pUnitreeId, 'qty_invoiced' => 7, 'qty_refunded' => 2],
    ['order_id' => $oCanceled, 'product_id' => $pUnitreeId, 'qty_invoiced' => 99, 'qty_refunded' => 0],
]);

$models = collect([
    new class(['id' => $pUnitreeId, 'name' => 'G1 租用机器人']) extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
    },
    new class(['id' => $pFallbackId, 'name' => '智元 远征 A2 机器']) extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
    },
    new class(['id' => $pUnclassifiedId, 'name' => '实验原型测试机']) extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
    },
]);

$service->attachCatalogMeta($models);
$m1 = $models[0];
$m2 = $models[1];
$m3 = $models[2];

assertTrue((int) $m1->getAttribute('sales_count') === 5, '销量应精确扣除退款并忽略已取消订单（7-2=5）');
assertTrue(($m1->getAttribute('resolved_brand')['code'] ?? null) === 'yushu', 'EAV 品牌选项应解析为 yushu');
assertTrue(($m1->getAttribute('resolved_robot_type')['code'] ?? null) === 'humanoid', 'EAV 形态选项应解析为 humanoid');
assertTrue(($m1->getAttribute('resolved_raas_meta')['daily_rent_yuan'] ?? null) === '2800.00', 'raas_meta 应正确折算日租金');
assertTrue(($m1->getAttribute('resolved_raas_meta')['min_rent_days'] ?? null) === 3, 'raas_meta 应包含最低起租天数');
assertTrue(($m1->getAttribute('resolved_raas_meta')['models_count'] ?? 0) === 2, 'raas_meta 应聚合 device_model 总机型数');
assertTrue(($m1->getAttribute('resolved_raas_meta')['comm_channels'] ?? []) === ['DDS', 'Agent'], 'raas_meta 仅应聚合 active 机型的通讯通道');

assertTrue(($m2->getAttribute('resolved_brand')['code'] ?? null) === 'zhiyuan', '未填 EAV 属性时应按商品名别名匹配 zhiyuan');
assertTrue(($m2->getAttribute('resolved_robot_type')['code'] ?? null) === 'humanoid', '未填 EAV 形态时应按关键词“远征”匹配 humanoid');

assertTrue($m3->getAttribute('resolved_brand') === null, '未知品牌应返回 null，不能错标品牌');
assertTrue($m3->getAttribute('resolved_robot_type') === null, '未知形态应返回 null，不能伪造默认人形');
assertTrue($m3->getAttribute('resolved_raas_meta') === null, '无拓展字段与机型映射时 raas_meta 应返回 null');

// 6. 测试 resolveProductQueryParams（GET /api/products 参数转换）
$tobResolved = $service->resolveProductQueryParams([
    'category_slug' => 'tob',
    'brand'         => 'yushu',
    'robot_type'    => 'humanoid',
]);
$tobResolvedIds = array_map('intval', explode(',', (string) $tobResolved['category_id']));
assertTrue(in_array($tobId, $tobResolvedIds, true) && in_array((int) $rentCat->id, $tobResolvedIds, true) && in_array((int) $buyCat->id, $tobResolvedIds, true), 'category_slug=tob 应展开为 tob,rent,buy 全部分类 ID');
assertTrue((int) $tobResolved['brand'] === (int) $yushu->attribute_option_id, 'brand=yushu 应转换为 EAV attribute_option_id');
assertTrue((int) $tobResolved['robot_type'] === $humanoidOptId, 'robot_type=humanoid 应转换为 robot_type EAV option_id');

$unknownResolved = $service->resolveProductQueryParams(['brand' => 'nonexistent_brand_xyz']);
assertTrue(($unknownResolved['__force_empty'] ?? false) === true, '未知品牌查询必须触发 __force_empty 防止全量露出');

// 7. 测试商家扩展字段聚合（compatible_models / param_specs / rental_plans）与回扫绑定
DB::table('merchant_product_exts')->where('product_id', $pUnitreeId)->update([
    'compatible_models' => json_encode(['g1']),
    'param_specs'       => json_encode([['name' => '续航时间', 'value' => '4小时'], ['name' => '空', 'value' => '']]),
]);
DB::table('merchant_rental_plans')->insert([
    'product_id'       => $pUnitreeId,
    'name'             => '五日起租特惠',
    'period_unit'      => 'day',
    'min_days'         => 5,
    'max_days'         => 90,
    'base_price_cents' => 260000,
    'enabled'          => 1,
]);
$service->attachCatalogMeta(collect([$m1]));
$meta1 = $m1->getAttribute('resolved_raas_meta');
assertTrue(($meta1['min_rent_days'] ?? 0) === 5, '存在启用租期方案时最低起租天数应取 ext 与方案最大值(5)');
assertTrue(($meta1['compatible_model_names'] ?? []) === ['Unitree G1'], 'compatible_model_names 应匹配商家指定的兼容机型');
assertTrue(count($meta1['param_specs'] ?? []) === 1 && ($meta1['param_specs'][0]['name'] ?? '') === '续航时间', 'param_specs 应过滤空值并结构化返回');
assertTrue(count($meta1['rental_plans'] ?? []) === 1 && ($meta1['rental_plans'][0]['base_price_yuan'] ?? '') === '2600.00', 'rental_plans 应输出有效租期摘要');

// 回扫存量未标注商品：先 dry-run 再实写，且不覆盖已有人工绑定
$backfillDry = $service->backfillProductAttributes(false);
assertTrue($backfillDry['brand_matched'] >= 1 && $backfillDry['updated'] === 0, 'backfill dry-run 不应写入数据库');
$backfillRes = $controller->backfillProducts(Request::create('/admin/catalog/brands/backfill-products', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
assertTrue($backfillRes->getStatusCode() === 200, '控制器回扫接口应返回 200 JSON');
$zhiyuanBrand = CatalogBrand::where('code', 'zhiyuan')->firstOrFail();
$fallbackBoundOpt = DB::table('product_attribute_values')->where('product_id', $pFallbackId)->where('attribute_id', $brandAttrId)->value('integer_value');
assertTrue((int) $fallbackBoundOpt === (int) $zhiyuanBrand->attribute_option_id, '未标注存量商品回扫后应自动绑定对应品牌 EAV 属性');
$unitreeBoundOpt = DB::table('product_attribute_values')->where('product_id', $pUnitreeId)->where('attribute_id', $brandAttrId)->value('integer_value');
assertTrue((int) $unitreeBoundOpt === (int) $yushu->attribute_option_id, '已有品牌属性的存量商品绝不能被回扫覆盖');

// 8. 测试 TobOrderController 最低起租天数强制校验（rent_days=2 < min_rent_days=5 => 422）
$app->instance('auth', new class {
    public function guard($name = null)
    {
        return new class {
            public function user() { return null; }
        };
    }
});
$app->instance('log', new class {
    public function error($msg, $ctx = []) { fwrite(STDERR, "LOG_ERR: {$msg} " . json_encode($ctx) . "\n"); }
    public function info($msg, $ctx = []) {}
    public function warning($msg, $ctx = []) {}
});
$coreStub = new class extends \Webkul\Core\Core {
    public function __construct() {}
    public function getCurrentChannel(?string $hostname = null) { return (object) ['id' => 1, 'name' => 'Default']; }
    public function getBaseCurrencyCode() { return 'CNY'; }
};
$app->instance('core', $coreStub);
$app->instance(\Webkul\Core\Core::class, $coreStub);
$app->instance(\Webkul\Shop\Services\SkillDelivery\SkillMarketService::class, new class {
    public function purchaseProblem(array $ids, ?int $customerId, string $type): ?array { return null; }
});
$app->instance(\Webkul\Shop\Services\Scenes\SceneOrderGuard::class, new class {
    public function check(array $ctx, array $ids, int $channelId): ?array { return null; }
});
$mockProduct = new class($pUnitreeId) {
    public int $id;
    public string $name = 'G1 租用机器人';
    public string $sku = 'UNI-G1';
    public string $type = 'simple';
    public float $price = 2800.0;
    public int $status = 1;
    public function __construct(int $id) { $this->id = $id; }
    public function getTypeInstance() { return new class { public function getMinimalPrice() { return 2800.0; } }; }
};
$prodRepo = new class($mockProduct) extends \Webkul\Product\Repositories\ProductRepository {
    private $p;
    public function __construct($p) { $this->p = $p; }
    public function with($relations) { return $this; }
    public function find($id, $columns = ['*']) { return (int) $id === $this->p->id ? $this->p : null; }
};
$refOrderCtrl = new \ReflectionClass(\Webkul\Shop\Http\Controllers\API\TobOrderController::class);
$tobOrderCtrl = $refOrderCtrl->newInstanceWithoutConstructor();
$pRepoProp = $refOrderCtrl->getProperty('productRepository');
$pRepoProp->setAccessible(true);
$pRepoProp->setValue($tobOrderCtrl, $prodRepo);
$storeTx = $refOrderCtrl->getMethod('storeWithinTransaction');
$storeTx->setAccessible(true);

$validator->extend('exists', fn () => true); // 简化测试隔离表的 validator exists 规则
$underMinReq = Request::create('/api/tob-order', 'POST', [
    'product_id' => $pUnitreeId,
    'qty'        => 1,
    'type'       => 'rent',
    'rent_days'  => 2,
    'name'       => '测试买家',
    'phone'      => '13800000000',
    'city'       => '上海',
    'address'    => '浦东新区',
]);
$underMinResp = $storeTx->invoke($tobOrderCtrl, $underMinReq);
assertTrue($underMinResp->getStatusCode() === 422, '租用天数低于 min_rent_days(5) 必须被后端拒绝返回 422');
assertTrue(($underMinResp->getData(true)['code'] ?? '') === 'ORDER_VALIDATION_FAILED', '错误码必须为标准契约 ORDER_VALIDATION_FAILED');

echo "RAAS_CATALOG_BRAND_PHP_PASS (8 suites, all assertions green)\n";
