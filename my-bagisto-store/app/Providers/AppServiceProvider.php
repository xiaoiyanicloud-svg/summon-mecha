<?php

namespace App\Providers;

use Barryvdh\Debugbar\Facades\Debugbar;
use App\Health\PlatformHealthCheck;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema; // 新增这一行
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Overtrue\Flysystem\Cos\CosAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $allowedIPs = array_map('trim', explode(',', config('app.debug_allowed_ips')));

        $allowedIPs = array_filter($allowedIPs);

        if (empty($allowedIPs)) {
            return;
        }

        if (in_array(Request::ip(), $allowedIPs)) {
            Debugbar::enable();
        } else {
            Debugbar::disable();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 新增这一行：设置默认字符串长度，解决索引长度问题
        Schema::defaultStringLength(191);

        // 保留原有的测试数据库设置代码
        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });

        /**
         * 注册腾讯云 COS 文件系统驱动（v3.4 步骤 3 · 技能包对象存储）。
         *
         * 为什么需要手动注册：overtrue/flysystem-cos 是**纯 Flysystem 适配器**，
         * 不像 Laravel 那些 driver 包会自动 extend。缺了这段，config/filesystems.php
         * 里的 'cos' 盘会在运行时报 "Driver [cos] is not supported"。
         *
         * 为什么是 COS 而不是 S3：S3 驱动依赖 aws/aws-sdk-php，被本项目的
         * composer 安全策略拦下（见 config/filesystems.php 的注释）。
         */
        Storage::extend('cos', function ($app, array $config) {
            // ⚠ FilesystemAdapter 的签名是 (FilesystemOperator $driver, FlysystemAdapter $adapter, array $config)
            //   —— 第二个参数是**适配器实例**，不是配置数组。传错会在 Storage::disk('cos') 时报类型错误。
            $adapter = new CosAdapter($config);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });

        /**
         * 健康深检（v3.4 第 10 步）。
         *
         * Laravel 的内建 `/up` 默认**只回 200，什么都不探** ——
         * 数据库挂了的实例照样回 200，负载均衡器照常打流量过来。
         * 这里挂上 DB / Cache / Queue 三个探针，让它真的说明问题。
         *
         * ⚠ 探针失败 → 抛异常 → `/up` 返回 500（框架语义），异常内容进日志不进响应。
         *   健康端点若是公开的，别把内部结构泄露出去。
         */
        Event::listen(DiagnosingHealth::class, PlatformHealthCheck::class);
    }
}
