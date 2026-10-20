<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\Ai\AiAnalyticsIngestService;

class AiAnalyticsReport extends Command
{
    protected $signature = 'ai:analytics-report {--days=7} {--json=}';

    protected $description = '汇总小召埋点事件与核心漏斗';

    public function handle(AiAnalyticsIngestService $analytics): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $report = $analytics->report($days);

        $jsonPath = (string) $this->option('json');
        if ($jsonPath !== '') {
            $dir = dirname($jsonPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents(
                $jsonPath,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $this->info('小召埋点 · 最近 '.$days.' 天');
        $this->table(
            ['事件', '次数'],
            collect($report['counts'])->map(fn (int $count, string $event): array => [$event, $count])->values()->all()
        );

        $this->newLine();
        $this->info('核心漏斗');
        foreach ($report['funnel'] as $event => $count) {
            $this->line(sprintf('%-20s %d', $event, $count));
        }

        return self::SUCCESS;
    }
}
