<?php

use App\Http\Middleware\EncryptCookies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Webkul\Core\Http\Middleware\SecureHeaders;
use Webkul\Installer\Http\Middleware\CanInstall;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /**
         * API routes are loaded under the `web` middleware group (for locale/currency),
         * but are consumed by token-authenticated clients (mobile app / uni-app) that
         * cannot present a CSRF token. Exclude them so Bearer-token requests pass.
         */
        $middleware->validateCsrfTokens(except: ['api/*', 'sanctum/*']);

        /**
         * Remove the default Laravel middleware that prevents requests during maintenance mode. There are three
         * middlewares in the shop that need to be loaded before this middleware. Therefore, we need to remove this
         * middleware from the list and add the overridden middleware at the end of the list.
         *
         * As of now, this has been added in the Admin and Shop providers. I will look for a better approach in Laravel 11 for this.
         */
        $middleware->remove(PreventRequestsDuringMaintenance::class);

        /**
         * Remove the default Laravel middleware that converts empty strings to null. First, handle all nullable cases,
         * then remove this line.
         */
        $middleware->remove(ConvertEmptyStringsToNull::class);

        $middleware->append(SecureHeaders::class);
        $middleware->append(CanInstall::class);

        /**
         * Add the overridden middleware at the end of the list.
         */
        $middleware->replaceInGroup('web', BaseEncryptCookies::class, EncryptCookies::class);

        $middleware->trustProxies('*');
    })
    ->withSchedule(function (Schedule $schedule) {
        /**
         * 平台自身的定时任务（v3.4 第 10 步）。
         *
         * ⚠ 这些任务**只有在 `schedule:work`（或 cron）常驻后才会跑**。
         *   没起调度器时它们等于不存在 —— 而失败是静默的（不会有任何报错）。
         *   部署清单里必须包含这一条：
         *     php artisan schedule:work        # 开发/小规模
         *     或 crontab: * * * * * php artisan schedule:run >> /dev/null 2>&1
         *
         * 全部支持 --dry-run，出问题时可以先手工跑一遍看它想干什么：
         *   php artisan ops:alert-compliance-due --dry-run
         *   php artisan ops:escalate-overdue-tickets --dry-run
         *   php artisan ops:alert-open-incidents --dry-run
         *
         * 为什么是每天 09:10 而不是每 5 分钟：这三件事都是**天级**的
         *   （备案到期、工单超时、事故未结案），跑太勤只会增加去重压力，
         *   并不会让响应更快。真的需要分钟级响应的是严重事故 —— 那走的是
         *   `IncidentService::alertIfEscalated()`：**报案那一刻就直接通知运营**。
         *   下面这条 `ops:alert-open-incidents` 只作兜底（防止有人漏看即时告警）。
         *
         * withoutOverlapping：防止上一轮没跑完下一轮又起来（尤其是加了真实通知渠道之后）。
         */
        $schedule->command('ops:alert-compliance-due')
            ->dailyAt('09:10')
            ->withoutOverlapping();

        $schedule->command('ops:escalate-overdue-tickets')
            ->dailyAt('09:15')
            ->withoutOverlapping();

        // 事故的时限是小时级（严重 4 小时），所以单独跑得勤一些
        $schedule->command('ops:alert-open-incidents')
            ->hourlyAt(25)
            ->withoutOverlapping();

        // 队列无人消费也是小时级问题（worker 挂了不会自己回来）
        $schedule->command('ops:alert-queue-backlog')
            ->hourlyAt(40)
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
