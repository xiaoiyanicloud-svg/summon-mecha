<?php

namespace Webkul\Shop\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
use Webkul\Shop\Models\Notification;

class ShopServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();

        $this->registerCommands();
    }

    /**
     * 注册本包的 artisan 命令。
     *
     * 只在控制台运行时注册（与 Core / Product 包一致）—— Web 请求里不必实例化命令。
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Webkul\Shop\Console\Commands\BackfillVersionStatus::class,
            ]);
        }
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

        $this->registerRateLimiters();

        Paginator::defaultView('shop::partials.pagination');
        Paginator::defaultSimpleView('shop::partials.pagination');

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'shop');

        $this->registerDevNotificationBadge();

        $this->app->register(EventServiceProvider::class);
    }

    /**
     * 开发者门户顶栏的**未读角标**（任务卡 D-4）。
     *
     * 布局文件是所有 dev 页共用的，控制器没法往里塞数据 —— 用 view composer 挂在布局上，
     * 让每个门户页都能拿到未读数。口径与收件箱列表**同源**（`Notification::scopeDeveloperInbox`），
     * 不另写一份查询：否则会出现"列表里有 3 条未读、角标却是别的数"的裂缝。
     *
     * 未登录（登录页/入驻页前半程）时角标不存在，置 0 即可 —— 布局里 `$devUnreadCount ?? 0` 也会兜底。
     */
    protected function registerDevNotificationBadge(): void
    {
        View::composer('shop::dev.layouts.app', function ($view) {
            $customerId = auth('developer')->id();

            $view->with('devUnreadCount', $customerId
                ? Notification::query()->developerInbox((int) $customerId)->whereNull('read_at')->count()
                : 0);
        });
    }

    /**
     * X3 · 限流档位(RateLimiter::for)。
     *
     * 全仓此前 `throttle` 只出现 1 次(开发者取 token),而 login、register、summon 系列、
     * asr、tts、callback-payment 全部**裸奔**。这里落三档:
     *   - `login` : 按 email+IP 组合,20/分(登录、注册)
     *   - `ai`    : 按 IP,60/分(召唤/ASR/TTS/支付回调等公开且耗资源的无鉴权入口)
     *   - `write` : 按 customer+IP,30/分(公开写入口 tob-order / 站内评价,以及登录态的
     *               售后、工单、事故上报)。**只挂写路由,不挂同组的列表/详情 GET** ——
     *               否则正常的翻页/轮询会把自己限死。
     *
     * ⚠ 计划里提到的 `sms` 档**未做**:全仓没有短信验证码路由,为不存在的功能定义档位
     *   就是凭空造架子(见项目简报"不要为不存在的供给方设计功能")。
     *
     * ⚠ 为什么定义在 ServiceProvider 而不是 `bootstrap/app.php`:后者是
     *   `Application::configure()` 构建器,**没有 boot 钩子** —— 在那里解析 `RateLimiter`
     *   时容器/service provider 尚未注册,会直接报错。这里是本包已注册的 boot 阶段。
     *
     * ⚠ 429 也带统一信封 `{code:'RATE_LIMITED', message}`(与 X2 的错误形状一致)。
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(20)
                ->by($email.'|'.$request->ip())
                ->response(fn () => response()->json([
                    'code'    => 'RATE_LIMITED',
                    'message' => '尝试过于频繁,请稍后再试。',
                ], 429));
        });

        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'code'    => 'RATE_LIMITED',
                    'message' => '请求过于频繁,请稍后再试。',
                ], 429));
        });

        // 登录态按 customer 计,游客按 IP 计 —— 同一个出口 IP 下(公司/校园/展会现场)
        // 的正常用户不该互相拖累。取不到 customer 时退化为 IP,不会因此放行。
        RateLimiter::for('write', function (Request $request) {
            $customerId = $request->user('customer')?->id;

            return Limit::perMinute(30)
                ->by($customerId ? 'customer:'.$customerId : 'ip:'.$request->ip())
                ->response(fn () => response()->json([
                    'code'    => 'RATE_LIMITED',
                    'message' => '提交过于频繁,请稍后再试。',
                ], 429));
        });

        // 举报档(加固 §四 A1) —— 比 write 更紧:5/分。
        //   举报是**唯一**的用户侧违规通道,但它同时也是一条能把任意文本送进后台的入口,
        //   且举报对象是任意技能(不像工单只对自己的东西)。30/分 对滥用太松,
        //   而正常用户一分钟报 5 条违禁技能已经远超日常;去重(同对象只受理一条)
        //   挡的是重复,这一档挡的是"换个对象接着刷"。
        RateLimiter::for('report', function (Request $request) {
            $customerId = $request->user('customer')?->id;

            return Limit::perMinute(5)
                ->by($customerId ? 'customer:'.$customerId : 'ip:'.$request->ip())
                ->response(fn () => response()->json([
                    'code'    => 'RATE_LIMITED',
                    'message' => '举报提交过于频繁,请稍后再试。',
                ], 429));
        });
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
