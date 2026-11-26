<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\SkillCompile\BuildMetricsService;

/**
 * 构建指标只读快照（B8）。
 *
 *   php artisan skill:build-metrics [--build-key=]
 *
 * 只读，不触发构建、不改任何状态。
 */
class SkillBuildMetrics extends Command
{
    protected $signature = 'skill:build-metrics {--build-key= : 只统计指定 build_key（关联 ID）}';

    protected $description = '输出构建指标只读快照（B8，不写库）';

    public function handle(BuildMetricsService $metrics): int
    {
        $this->line(json_encode(
            $metrics->snapshot($this->option('build-key') ?: null),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
