<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\Deploy\GaOperationalMetrics;

class GaOperationalMetricsCommand extends Command
{
    protected $signature = 'skill-ga:metrics {--json= : 输出 JSON 文件路径}';

    protected $description = '只读输出五机型技能交付运行指标与告警信号';

    public function handle(GaOperationalMetrics $metrics): int
    {
        $snapshot = $metrics->snapshot();
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = $this->option('json');

        if (is_string($path) && $path !== '') {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($path, $json.PHP_EOL);
        }

        $this->line($json);

        return self::SUCCESS;
    }
}