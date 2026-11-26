<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\SkillCompile\BuildCommandService;

/**
 * 构建命令 outbox worker（B1）。
 *
 *   php artisan skill:build-worker [--limit=10] [--once]
 *
 * ⚠ 本命令只跑**一轮有界批次**，不常驻、不无限循环。默认开关
 *   `skill_build.async_enabled=false` —— 同步入口不会留下 queued 命令；
 *   打开异步后由运维定时触发本命令。**注册不等于在跑**，没有 scheduler/cron
 *   时需手工执行。
 */
class SkillBuildWorker extends Command
{
    protected $signature = 'skill:build-worker
        {--limit= : 本轮最多领取条数（默认 skill_build.worker_batch）}
        {--once : 跑一轮有界批次后退出（本命令始终只跑一轮，显式便于脚本）}';

    protected $description = '领取并执行 skill_build_commands 的一轮有界批次（B1 outbox worker）';

    public function handle(BuildCommandService $service): int
    {
        $limit = (int) ($this->option('limit') ?: config('skill_build.worker_batch', 10));

        $this->line(sprintf(
            'async_enabled=%s（本命令为手工/调度触发，不假设常驻 worker 存在）',
            config('skill_build.async_enabled', false) ? 'true' : 'false',
        ));

        $summary = $service->runQueued($limit);

        $this->info(json_encode($summary, JSON_UNESCAPED_UNICODE));

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
