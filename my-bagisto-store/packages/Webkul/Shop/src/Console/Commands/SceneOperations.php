<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Services\Scenes\SceneOperationsService;

class SceneOperations extends Command
{
    protected $signature = 'scenes:operations {--consume=0 : 最多消费条数，0 为只读} {--sweep : 扫描过期预留与待办}';

    protected $description = '场景流程只读准入报告，或显式消费 outbox / 扫描待办';

    public function handle(SceneOperationsService $ops): int
    {
        $tables = ['business_idempotency_keys', 'business_outbox', 'scene_revisions', 'scene_requests', 'scene_quotes', 'scene_resources', 'scene_reservations', 'scene_projects', 'scene_operation_todos'];
        $missing = array_values(array_filter($tables, fn ($t) => ! Schema::hasTable($t)));
        $report = ['database_driver' => DB::connection()->getDriverName(), 'missing_tables' => $missing,
            'features'               => collect(config('scenes'))->only(['publication_v2', 'requests_enabled', 'quotes_enabled', 'reservations_enabled', 'project_actions_enabled', 'operations_enabled'])->all(),
            'order_conversion'       => 'blocked_pending_mysql_transaction_verification'];
        if ($missing) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }
        if ($this->option('sweep')) {
            $report['sweep'] = $ops->sweep();
        }
        $count = (int) $this->option('consume');
        if ($count < 0 || $count > 1000) {
            $this->error('--consume 应为 0 至 1000。');

            return self::FAILURE;
        }
        $report['delivery'] = [];
        for ($i = 0; $i < $count; $i++) {
            $result = $ops->consumeOne();
            $report['delivery'][] = $result;
            if ($result['state'] === 'idle') {
                break;
            }
        }
        $report['metrics'] = $ops->metrics();
        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
