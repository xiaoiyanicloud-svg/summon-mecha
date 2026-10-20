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
        $schedule->command('scenes:operations --consume=100 --sweep')
            ->everyMinute()->withoutOverlapping()
            ->when(fn () => (bool) config('scenes.operations_enabled', false));
        // Inspection/ledger scanning is separately opt-in. Execution additionally
        // requires the hard safety gate in skill_delivery.retry.safety_verified.
        $schedule->command('skills:scan-delivery-retries')
            ->everyMinute()->withoutOverlapping()
            ->when(fn () => (bool) config('skill_delivery.retry.scan_enabled', false));
        $schedule->command('skills:audit-delivery --notify')
            ->everyTenMinutes()->withoutOverlapping()
            ->when(fn () => (bool) config('skill_delivery.retry.scan_enabled', false));
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

        /**
         * P6 · 下发任务的服务端超时兜底。
         *
         * 下发任务离开"进行中"只有两条路:设备回报,或被取消 —— 而设备是随时会掉线的
         * 外部实体。没有这条兜底,给长期离线设备下发的任务会**永久**停在 pending_online,
         * 用户界面永远"下发中"(既不成功也不失败,且无人知晓)。
         *
         * 跑得比阈值勤一档(默认在途阈值 30min → 每 10 分钟扫一次):
         * 超时判定不需要秒级精度,但也不能让任务多挂一整个扫描周期。
         * 两个阈值都可用命令参数覆盖(--pending-hours / --inflight-minutes)。
         *
         * ⚠ 为什么不在这里给**灰度**也排一个自动评估任务:
         *   灰度的推进信号是"设备回报",事件驱动(evaluateFromRecords)比轮询**更准也更快**。
         *   项目此前已有 scheduler,仍刻意不给灰度加定时任务 —— 那是拿错误的模型覆盖正确的设计
         *   (详见 DeployTaskService::evaluateGrayIfAny 的说明)。此处同理,只排下发超时扫描。
         */
        $schedule->command('ops:timeout-deploy-tasks')
            ->everyTenMinutes()
            ->withoutOverlapping();

        /**
         * B-3 · 试用到期回收（D4 / 计划 §4.7「20 分钟自动回收」）。
         *
         * 为什么是**每分钟**而不是像上面几条那样天级/小时级：试用的窗口只有 20 分钟，
         * "到期"与"回收"之间的滞后必须远小于窗口本身，否则用户在倒计时归零后
         * 还能继续跑上一段时间。计划 §3.4 的 `(status, expires_at)` 索引就是为这条建的。
         *
         * 命令本身只做"发现 + 发回收任务"；设备回报成功后由
         * `DeployTaskService::onEvent()` 落 `expired` 并发站内通知 —— 与既有下发状态机同源。
         */
        $schedule->command('ops:reclaim-expired-trials')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /**
         * X2 · 全局异常渲染 —— 统一 JSON 错误信封。
         *
         * ⚠ 这里**故意留空**。本项目实际的异常处理器是 `Webkul\Core\Exceptions\Handler`
         *   (见 `packages/Webkul/Core/src/Exceptions/Handler.php`),它在 provider boot 阶段
         *   就把自己的 renderable 回调注册进了 `$renderCallbacks`;而 `withExceptions` 的
         *   回调是**之后**(handler 首次解析时)追加的 —— 数组靠后 = 优先级更低。
         *   实测:APP_DEBUG=false 时,Bagisto 的回调会**完全遮蔽**这里注册的任何回调。
         *
         *   所以统一信封注册在 `Core\Exceptions\Handler::register()` 的最前面(它才是
         *   真正优先的那一层),在此重复一份会造成两处同源逻辑。
         */
    })->create();
