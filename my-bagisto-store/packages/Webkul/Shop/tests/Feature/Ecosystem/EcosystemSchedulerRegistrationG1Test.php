<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * G1-09 · 调度注册事实（规划 §5.2 / §5.5）。
 *
 * 这组测试只证明“代码里注册了什么、默认开关是什么”，
 * **不证明常驻进程已运行**。没有 `schedule:work` / crontab 时任务不会执行。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-09 scheduler registration tests require summon_mecha_step10_test.');
    }
});

/**
 * @return \Illuminate\Console\Scheduling\Event
 */
function g9ScheduledEvent(string $command): \Illuminate\Console\Scheduling\Event
{
    // Laravel 11 的 withSchedule 回调挂在 Artisan 控制台应用的 bootstrap 上；
    // 测试里 HTTP 内核不会构造它。跑一个轻量命令触发 bootstrap，
    // 再断言 bootstrap/app.php 注册的调度事实（每个测试应用只 bootstrap 一次）。
    \Illuminate\Support\Facades\Artisan::call('list');

    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        if (is_string($event->command) && str_contains($event->command, $command)) {
            return $event;
        }
    }

    throw new LogicException('未找到调度任务：'.$command);
}

it('worker / scheduler 开关默认都是 false', function () {
    expect(config('ecosystem_outbox.worker_enabled'))->toBeFalse()
        ->and(config('ecosystem_publishing.scheduler_enabled'))->toBeFalse()
        ->and(config('ecosystem_public_read.emergency_disabled'))->toBeFalse();
});

it('两条 G1-09 任务已注册为每分钟且 withoutOverlapping，默认过滤器不通过', function () {
    $outbox = g9ScheduledEvent('ecosystem:process-outbox');
    $scheduled = g9ScheduledEvent('ecosystem:publish-scheduled');

    expect($outbox->expression)->toBe('* * * * *')
        ->and($outbox->withoutOverlapping)->toBeTrue()
        ->and($scheduled->expression)->toBe('* * * * *')
        ->and($scheduled->withoutOverlapping)->toBeTrue();

    expect($outbox->filtersPass(app()))->toBeFalse()
        ->and($scheduled->filtersPass(app()))->toBeFalse();
});

it('显式打开开关后过滤器才通过（仍不代表常驻进程已启动）', function () {
    $outbox = g9ScheduledEvent('ecosystem:process-outbox');
    $scheduled = g9ScheduledEvent('ecosystem:publish-scheduled');

    config([
        'ecosystem_outbox.worker_enabled'            => true,
        'ecosystem_publishing.scheduler_enabled'     => true,
    ]);

    expect($outbox->filtersPass(app()))->toBeTrue()
        ->and($scheduled->filtersPass(app()))->toBeTrue();
});
