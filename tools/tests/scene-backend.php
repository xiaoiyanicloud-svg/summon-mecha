<?php

declare(strict_types=1);

// 隔离测试：不加载 bootstrap/app.php、.env 或业务数据库，只使用 SQLite :memory:。
// php -d extension=pdo_sqlite tools/tests/scene-backend.php
$base = dirname(__DIR__, 2).'/my-bagisto-store';
require $base.'/vendor/autoload.php';
require_once $base.'/packages/Webkul/Core/src/Http/helpers.php';
require_once $base.'/packages/Webkul/User/src/Http/helpers.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Admin\Http\Middleware\AuthorizeSceneAction;
use Webkul\Admin\Http\Requests\SceneRequest;
use Webkul\Shop\Http\Controllers\API\SceneController;
use Webkul\Shop\Models\Scene;
use Webkul\Shop\Services\Scenes\SceneCatalogService;
use Webkul\Shop\Services\Scenes\SceneOrderGuard;

if (! extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('需显式加载 pdo_sqlite；测试不会回退连接业务数据库。');
}
$app = new Application($base);
$app->instance('config', new Repository(['app' => ['locale' => 'en', 'key' => 'scene-test-only']]));
$db = new Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->instance('db.schema', $db->schema());
Facade::setFacadeApplication($app);
if ($db->getConnection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('隔离条件不满足。');
}
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class
{
    public function json($data, $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }
});
Webkul\Core\Facades\Core::swap(new class
{
    public function getCurrentChannel(): object
    {
        return (object) ['id' => 1];
    }
});
Storage::swap(new class
{
    public function url($path): string
    {
        return '/storage/'.$path;
    }
});
$permissions = new class
{
    public array $allowed = [];

    public function hasPermission($permission): bool
    {
        return in_array($permission, $this->allowed, true);
    }
};
Webkul\User\Facades\Bouncer::swap($permissions);

// 实际执行现有四份场景迁移，不手写一份“看起来相同”的场景表。
foreach (glob($base.'/packages/Webkul/Shop/src/Database/Migrations/*scenes_table.php') as $migration) {
    (require $migration)->up();
}
$schema = $db->schema();
foreach ([
    'products' => ['type' => 'string'],
    'channels' => ['code' => 'string'],
    'product_flat' => ['product_id' => 'integer', 'channel' => 'string', 'status' => 'integer', 'visible_individually' => 'integer'],
    'product_channels' => ['product_id' => 'integer', 'channel_id' => 'integer'],
    'product_categories' => ['product_id' => 'integer', 'category_id' => 'integer'],
    'category_translations' => ['category_id' => 'integer', 'slug' => 'string'],
] as $table => $columns) {
    $schema->create($table, function (Blueprint $blueprint) use ($columns) {
        $blueprint->increments('id');
        foreach ($columns as $column => $type) {
            $blueprint->$type($column);
        }
    });
}
$db->table('channels')->insert([['id' => 1, 'code' => 'default'], ['id' => 2, 'code' => 'other']]);
$db->table('category_translations')->insert([['category_id' => 1, 'slug' => 'rent'], ['category_id' => 2, 'slug' => 'buy']]);
foreach ([
    [11, 'simple', 1, 1, 1, 1], // 当前渠道租用
    [12, 'booking', 1, 1, 1, 1],
    [13, 'simple', 1, 0, 1, 1], // 停用
    [14, 'simple', 2, 1, 1, 1], // 其他渠道
    [15, 'simple', 1, 1, 0, 1], // 隐藏
    [16, 'simple', 1, 1, 1, 2], // 购买类目
] as [$id, $type, $channel, $status, $visible, $category]) {
    $db->table('products')->insert(['id' => $id, 'type' => $type]);
    $db->table('product_flat')->insert(['product_id' => $id, 'channel' => $channel === 1 ? 'default' : 'other', 'status' => $status, 'visible_individually' => $visible]);
    $db->table('product_channels')->insert(['product_id' => $id, 'channel_id' => $channel]);
    $db->table('product_categories')->insert(['product_id' => $id, 'category_id' => $category]);
}
Scene::create(['id' => 1, 'name' => '真实场景', 'status' => true, 'category' => 'enterprise', 'robots' => [11, 12, 13, 14, 15, 16, 99], 'consult_count' => 99, 'stats' => ['roi' => '4个月'], 'scheme_scenes' => [['name' => '样板', 'desc' => '说明', 'data' => '+300%']]]);
Scene::create(['id' => 2, 'name' => '已停用', 'status' => false]);
$catalog = new SceneCatalogService;
$guard = new SceneOrderGuard($catalog);
$tests = [];
function check(string $name, Closure $test): void
{
    global $tests;
    try {
        $test();
        $tests[] = ['name' => $name, 'pass' => true];
    } catch (Throwable $e) {
        $tests[] = ['name' => $name, 'pass' => false, 'error' => $e->getMessage()];
    }
}
function expectSame($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('断言失败：'.json_encode(['expected' => $expected, 'actual' => $actual], JSON_UNESCAPED_UNICODE));
    }
}

check('租用资格同时验证商品类型、类目、渠道、状态、可见性和真实引用', function () use ($catalog) {
    expectSame([11], $catalog->rentalProductIds(1));
    expectSame([], $catalog->rentalProductIds(1, []));
    expectSame([], $catalog->rentalProductIds(1, [12, 13, 14, 15, 16, 99]));
    expectSame([14], $catalog->rentalProductIds(2));
});
check('公开 DTO 不公开手编咨询数和无证据收益', function () use ($catalog) {
    $data = $catalog->detail(Scene::findOrFail(1), 1);
    expectSame(null, $data['consult_count']);
    expectSame(null, $data['scheme']['stats']);
    expectSame([11], $data['scheme']['robots']);
    expectSame(false, isset($data['scheme']['scenes'][0]['data']));
    expectSame('requires_confirmation', $data['delivery_status']);
});
check('停用、缺失和非法 ID 统一 404，不执行可交易示例回退', function () {
    $controller = new SceneController;
    foreach (['2', '999', 'undefined', '-1', '0', '1x', '99999999999999999999999', '4294967296'] as $id) {
        $response = $controller->show($id);
        expectSame(404, $response->getStatusCode());
        expectSame('SCENE_UNAVAILABLE', $response->getData(true)['code']);
    }
});
check('列表仅返回启用场景，分类过滤保留正常空结果', function () use ($app) {
    $app->instance('request', Request::create('/api/scenes'));
    expectSame([1], array_column((new SceneController)->index()->getData(true)['data'], 'id'));
    $app->instance('request', Request::create('/api/scenes?category=home'));
    expectSame([], (new SceneController)->index()->getData(true)['data']);
    foreach ([[], ['enterprise'], 'unknown'] as $category) {
        $app->instance('request', Request::create('/api/scenes', 'GET', ['category' => $category]));
        expectSame(422, (new SceneController)->index()->getStatusCode());
    }
});
check('直调交易校验拒绝未配置、停用、booking 和其他渠道商品', function () use ($guard) {
    foreach ([12, 13, 14, 15, 16, 99] as $id) {
        expectSame('SCENE_PRODUCT_UNAVAILABLE', $guard->check(['type' => 'rent', 'from' => 'scene', 'scene_id' => 1], [$id], 1)['code']);
    }
    expectSame('SCENE_UNAVAILABLE', $guard->check(['type' => 'rent', 'from' => 'scene', 'scene_id' => 2], [11], 1)['code']);
    expectSame('SCENE_REQUIRED', $guard->check(['type' => 'rent', 'from' => 'scene'], [11], 1)['code']);
    expectSame('SCENE_REQUIRED', $guard->check(['type' => 'buy', 'scene_id' => 1], [11], 1)['code']);
});
check('合法场景、多机自定义、定制咨询及普通租购语义分别处理', function () use ($guard) {
    expectSame(1, $guard->check(['type' => 'rent', 'from' => 'scene', 'scene_id' => 1], [11], 1)['scene_id']);
    expectSame('custom_plan', $guard->check(['type' => 'rent', 'from' => 'custom_plan'], [11], 1)['source']);
    expectSame(1, $guard->check(['type' => 'custom', 'scene_id' => 1], [], 1)['scene_id']);
    expectSame([], $guard->check(['type' => 'buy'], [11], 1));
});
check('重跑种子不覆盖停用状态、图片、名称或描述', function () {
    $seeder = new Database\Seeders\SceneSeeder;
    $seeder->run();
    $scene = Scene::findOrFail(6);
    $scene->update(['name' => '运营已修改', 'status' => false, 'banner_path' => 'kept.png', 'description' => '保留']);
    $seeder->run();
    $seeder->run();
    $scene->refresh();
    expectSame(false, $scene->status);
    expectSame('kept.png', $scene->banner_path);
    expectSame('运营已修改', $scene->name);
    expectSame('保留', $scene->description);
    expectSame(1, Scene::where('id', 6)->count());
});
check('每个 Admin 动作都有权限；空权限和未知动作默认拒绝', function () use ($permissions) {
    $middleware = new AuthorizeSceneAction;
    foreach (AuthorizeSceneAction::PERMISSIONS + ['unexpectedAction' => ''] as $action => $permission) {
        $request = Request::create('/admin/catalog/scenes', 'POST');
        $controllerAction = 'Webkul\\Admin\\Http\\Controllers\\Catalog\\SceneController@'.$action;
        $route = new Route('POST', '/admin/catalog/scenes', ['uses' => $controllerAction, 'controller' => $controllerAction]);
        $request->setRouteResolver(fn () => $route);
        foreach ([[], ['catalog.scenes'], [$permission]] as $allowed) {
            $permissions->allowed = $allowed;
            $shouldPass = $permission !== '' && in_array($permission, $allowed, true);
            try {
                $middleware->handle($request, fn () => true);
                expectSame(true, $shouldPass);
            } catch (HttpException $e) {
                expectSame(403, $e->getStatusCode());
                expectSame(false, $shouldPass);
            }
        }
    }
});
check('真实 Admin 路由文件中的全部场景入口均挂载动作鉴权', function () use ($app, $base) {
    $router = new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app), $app);
    Illuminate\Support\Facades\Route::swap($router);
    require $base.'/packages/Webkul/Admin/src/Routes/catalog-routes.php';
    $routes = array_filter($router->getRoutes()->getRoutes(), fn ($route) => str_starts_with((string) $route->getName(), 'admin.catalog.scenes.'));
    expectSame(11, count($routes));
    foreach ($routes as $route) {
        expectSame(true, in_array(AuthorizeSceneAction::class, $route->middleware(), true));
        expectSame(true, isset(AuthorizeSceneAction::PERMISSIONS[$route->getActionMethod()]));
    }
});
$factory = new Factory(new Translator(new ArrayLoader, 'en'), $app);
$factory->setPresenceVerifier(new DatabasePresenceVerifier($db->getDatabaseManager()));
$validate = function (array $input) use ($factory): array {
    $request = SceneRequest::create('/admin/catalog/scenes/create', 'POST', $input);
    $validator = $factory->make($input, $request->rules());
    $request->withValidator($validator);

    return [$request, $validator];
};
check('表单拒绝伪机型、跨渠道、非法分类、重复 ID、越界长度和坏结构', function () use ($validate) {
    foreach ([['robots' => [12]], ['robots' => [14]], ['robots' => [99]], ['robots' => [11, 11]], ['category' => 'fake'], ['name' => str_repeat('x', 256)], ['bg_color' => 'url(x)'], ['pains' => '只有一段']] as $invalid) {
        [, $validator] = $validate(array_replace(['name' => '测试', 'category' => 'enterprise'], $invalid));
        expectSame(true, $validator->fails());
    }
    [, $valid] = $validate(['name' => '测试', 'robots' => [11], 'category' => 'education', 'pains' => '|问题|解法']);
    expectSame(true, $valid->passes());
});
check('后台更新保留未提交字段，明确空值可以清空；取消全部机型有效', function () use ($validate) {
    [$request, $validator] = $validate(['name' => '新名', 'description' => '', 'robots_present' => 1]);
    $validator->validate();
    $request->setValidator($validator);
    $scene = Scene::findOrFail(1);
    $scene->icon = '保留图标';
    $method = new ReflectionMethod(Webkul\Admin\Http\Controllers\Catalog\SceneController::class, 'formData');
    $data = $method->invoke(new Webkul\Admin\Http\Controllers\Catalog\SceneController, $request, $scene);
    $scene->fill($data);
    expectSame('保留图标', $scene->icon);
    expectSame('', $scene->description);
    expectSame([], $scene->robots);
    expectSame('enterprise', $scene->category);
    expectSame(true, $scene->status);
});

$failures = count(array_filter($tests, fn ($test) => ! $test['pass']));
echo json_encode(['environment' => 'SQLite :memory:，无 .env/业务库/外网', 'groups' => count($tests), 'failed' => $failures, 'tests' => $tests], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
exit($failures ? 1 : 0);
