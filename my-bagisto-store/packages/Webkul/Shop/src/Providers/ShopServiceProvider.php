<?php

namespace Webkul\Shop\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;
use Webkul\Shop\Http\Middleware\AuthenticateCustomer;
use Webkul\Shop\Http\Middleware\AuthenticateDeveloper;
use Webkul\Shop\Http\Middleware\CacheResponse;
use Webkul\Shop\Http\Middleware\Currency;
use Webkul\Shop\Http\Middleware\Locale;
use Webkul\Shop\Http\Middleware\RequireDeveloperLogin;
use Webkul\Shop\Http\Middleware\ResolveApiCustomer;
use Webkul\Shop\Http\Middleware\ResolveDeveloper;
use Webkul\Shop\Http\Middleware\ResolveDeveloperLogin;
use Webkul\Shop\Http\Middleware\Theme;
use Webkul\Shop\Http\Middleware\VerifyAgentKey;
use Webkul\Shop\Http\Middleware\VerifyInternalKey;

class ShopServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();
    }

    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $router->middlewareGroup('shop', [
            Theme::class,
            Locale::class,
            Currency::class,
        ]);

        $router->aliasMiddleware('theme', Theme::class);
        $router->aliasMiddleware('locale', Locale::class);
        $router->aliasMiddleware('currency', Currency::class);
        $router->aliasMiddleware('cache.response', CacheResponse::class);
        $router->aliasMiddleware('customer', AuthenticateCustomer::class);
        $router->aliasMiddleware('api.customer', ResolveApiCustomer::class);
        $router->aliasMiddleware('internal.key', VerifyInternalKey::class);
        $router->aliasMiddleware('agent.key', VerifyAgentKey::class);

        // v3.4 步骤 9 · 开发者门户：会话鉴权 / 轻量登录门槛 / token 鉴权
        $router->aliasMiddleware('developer', AuthenticateDeveloper::class);
        $router->aliasMiddleware('developer.session', RequireDeveloperLogin::class);
        $router->aliasMiddleware('api.developer', ResolveDeveloper::class);
        $router->aliasMiddleware('api.developer.login', ResolveDeveloperLogin::class);

        Route::middleware(['web', 'shop', PreventRequestsDuringMaintenance::class])->group(__DIR__.'/../Routes/web.php');
        Route::middleware(['web', 'shop', 'api.customer', PreventRequestsDuringMaintenance::class])->group(__DIR__.'/../Routes/api.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'shop');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'shop');

        Paginator::defaultView('shop::partials.pagination');
        Paginator::defaultSimpleView('shop::partials.pagination');

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'shop');

        $this->app->register(EventServiceProvider::class);
    }

    /**
     * Register package config.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php',
            'menu.customer'
        );
    }
}
