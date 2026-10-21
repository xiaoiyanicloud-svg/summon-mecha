<?php

declare(strict_types=1);

// 真实控制器继承及 Laravel Router 分发；不加载 .env / 业务 bootstrap / 数据库。
require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Webkul\Shop\Http\Controllers\API\SceneWorkflowController;
use Webkul\Shop\Models\Scene;

$base = dirname(__DIR__, 2).'/my-bagisto-store';
$app = new Application($base);
$app->instance('config', new Repository(['app' => ['debug' => false, 'locale' => 'en', 'timezone' => 'UTC'], 'scenes' => require $base.'/config/scenes.php']));
$events = new Dispatcher($app);
$app->instance('events', $events);
$app->instance('log', new Psr\Log\NullLogger);
Facade::setFacadeApplication($app);
$router = new Router($events, $app);
$app->instance('router', $router);
Illuminate\Support\Facades\Route::swap($router);
$view = new Factory(new EngineResolver, new FileViewFinder(new Filesystem, []), $events);
$factory = new ResponseFactory($view, new Redirector(new UrlGenerator($router->getRoutes(), Request::create('/'))));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $factory);

// 阻断任何潜在数据库访问；本测试不依赖本机是否装有 MySQL。
$app->bind('db', function () {
    throw new RuntimeException('DATABASE_ACCESS_FORBIDDEN_IN_CONTROLLER_SMOKE');
});
$router->prefix('api')->group($base.'/packages/Webkul/Shop/src/Routes/scene-workflow.php');
$router->prefix('admin/catalog')->group($base.'/packages/Webkul/Admin/src/Routes/scene-workflow-routes.php');
$tests = [];
function runtimeCase(string $name, callable $fn): void
{
    global $tests;
    try {
        $fn();
        $tests[] = ['name' => $name, 'pass' => true];
    } catch (Throwable $e) {
        $tests[] = ['name' => $name, 'pass' => false, 'exception' => get_class($e), 'error' => $e->getMessage()];
    }
}
function runtimeExpect($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(json_encode(['expected' => $expected, 'actual' => $actual]));
    }
}
runtimeCase('全部场景路由控制器及父类实际装载，处理方法必须公开且可调用', function () use ($router) {
    foreach ($router->getRoutes() as $route) {
        $method = new ReflectionMethod($route->getControllerClass(), $route->getActionMethod());
        runtimeExpect(true, $method->isPublic());
    }
    runtimeExpect(true, (new ReflectionMethod(SceneWorkflowController::class, 'reject'))->isProtected());
    runtimeExpect(true, (new ReflectionMethod(SceneWorkflowController::class, 'rejectQuote'))->isPublic());
    new ReflectionClass(Webkul\Admin\Http\Controllers\Catalog\SceneWorkflowController::class);
});
function dispatchRead(string $path)
{
    global $app, $router;
    $request = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->setUserResolver(fn () => null);
    $app->instance('request', $request);

    return $router->dispatch($request);
}
runtimeCase('真实控制器能力接口可执行；订单转换始终关闭', function () {
    $response = dispatchRead('/api/scene-workflow/capabilities');
    runtimeExpect(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), true)['data'];
    runtimeExpect(false, $data['order_conversion']);
    runtimeExpect('scene-contact-v1', $data['consent_version']);
});
runtimeCase('schema 路由参数避开全局 category 模型绑定', function () use ($router) {
    $route = null;
    foreach ($router->getRoutes() as $candidate) {
        if ($candidate->uri() === 'api/scene-workflow/schemas/{sceneCategory}') {
            $route = $candidate;
            break;
        }
    }
    runtimeExpect(true, $route !== null);
    runtimeExpect(['sceneCategory'], $route->parameterNames());
});
foreach (array_keys(Scene::CATEGORIES) as $category) {
    runtimeCase('真实 Router 分发表单 '.$category.'，无数据库访问', function () use ($category) {
        $response = dispatchRead('/api/scene-workflow/schemas/'.$category);
        runtimeExpect(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true)['data'];
        runtimeExpect($category, $data['category']);
        runtimeExpect(true, count($data['fields']) > 0);
    });
}
runtimeCase('schema 参数避开 Bagisto 全局 category 模型绑定', function () use ($router) {
    foreach ($router->getRoutes() as $route) {
        if ($route->getName() === 'shop.api.scene-workflow.schema') {
            runtimeExpect(['sceneCategory'], $route->parameterNames());

            return;
        }
    }
    throw new RuntimeException('schema 路由缺失');
});
runtimeCase('未知表单类别为稳定 422；不是无路由 404', function () {
    $response = dispatchRead('/api/scene-workflow/schemas/not-a-category');
    runtimeExpect(422, $response->getStatusCode());
    runtimeExpect('SCENE_SCHEMA_UNSUPPORTED', json_decode($response->getContent(), true)['code']);
});
foreach (['/api/scene-requests/1', '/api/scene-projects/1', '/api/scene-requests/1/attachments/1'] as $path) {
    runtimeCase('未登录不读私有记录 '.$path, function () use ($path) {
        $response = dispatchRead($path);
        runtimeExpect(401, $response->getStatusCode());
        runtimeExpect('SCENE_ACCESS_REQUIRED', json_decode($response->getContent(), true)['code']);
    });
}
$failed = count(array_filter($tests, fn ($t) => ! $t['pass']));
echo json_encode(['environment' => 'real controller inheritance + Laravel Router, no business bootstrap/database', 'tests' => $tests,
    'passed' => count($tests) - $failed, 'failed' => $failed, 'business_database_accessed' => false, 'full_application_http_verified' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed ? 1 : 0);
