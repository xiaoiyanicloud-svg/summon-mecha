<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Services\Scenes\SceneOperationsService;

class SceneOperations extends Command
{
    protected $signature = 'scenes:operations {--consume=0 : 最多消费条数，0 为只读} {--sweep : 扫描过期预留与待办} {--metrics : 输出全景运营指标}';

    protected $description = '场景流程只读准入报告，或显式消费 outbox / 扫描待办 / 全景指标分析';

    public function handle(SceneOperationsService $ops): int
    {
        $count = filter_var($this->option('consume'), FILTER_VALIDATE_INT);
        if ($count === false || $count < 0 || $count > 1000) {
            $this->error('--consume 应为 0 至 1000 的整数。');

            return self::FAILURE;
        }
        $tables = ['business_idempotency_keys', 'business_outbox', 'business_event_receipts', 'scene_revisions', 'scene_requests', 'scene_quotes', 'scene_resources', 'scene_reservations', 'scene_projects', 'scene_order_links', 'scene_operation_todos', 'scene_policy_versions', 'scene_policy_kinds', 'scene_service_clocks', 'scene_site_surveys', 'scene_proposals', 'scene_attachment_holds', 'scene_content_evidence', 'scene_sku_mappings', 'scene_quote_commercial', 'scene_order_parts', 'scene_project_changes', 'scene_service_tickets'];
        $missing = array_values(array_filter($tables, fn ($t) => ! Schema::hasTable($t)));
        $report = ['database_driver' => DB::connection()->getDriverName(), 'missing_tables' => $missing,
            'features' => collect(config('scenes'))->only(['publication_v2', 'requests_enabled', 'quotes_enabled', 'reservations_enabled', 'project_actions_enabled', 'operations_enabled', 'order_conversion_enabled'])->all(),
            'order_conversion' => config('scenes.order_conversion_enabled', false) && config('scenes.reservations_enabled', false) ? 'enabled' : 'disabled'];
        if ($missing) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }
        if ($this->option('sweep')) {
            $report['sweep'] = $ops->sweep();
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

        return collect($report['delivery'])->contains(fn ($row) => $row['state'] === 'failed') ? self::FAILURE : self::SUCCESS;
    }
}
