<?php

declare(strict_types=1);

// 只读预检：先验证进程环境，再 bootstrap；绝不自动迁移、建库、seed 或清理数据。
function scenePreflightOutput(array $report, int $exit): never
{
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
    exit($exit);
}

if (in_array('--help', $argv, true)) {
    scenePreflightOutput(['command' => 'php qa/scenes/preflight.php [--check-env-only]',
        'required_process_environment' => ['APP_ENV' => 'testing', 'DB_DATABASE' => 'summon_mecha_scenes_test'],
        'mode' => 'read_only', 'database_password_required_in_report' => false], 0);
}
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--check-env-only') {
        scenePreflightOutput(['ok' => false, 'code' => 'UNKNOWN_ARGUMENT', 'bootstrap_loaded' => false], 2);
    }
}
$environment = getenv('APP_ENV');
$database = getenv('DB_DATABASE');
if ($environment !== 'testing' || $database !== 'summon_mecha_scenes_test') {
    scenePreflightOutput(['ok' => false, 'code' => 'SCENE_TEST_ENVIRONMENT_REQUIRED', 'bootstrap_loaded' => false,
        'required' => ['APP_ENV' => 'testing', 'DB_DATABASE' => 'summon_mecha_scenes_test'], 'database_identity_verified' => false, 'writes_performed' => false], 2);
}
if (in_array('--check-env-only', $argv, true)) {
    scenePreflightOutput(['ok' => true, 'stage' => 'process_environment_only', 'bootstrap_loaded' => false,
        'database_identity_verified' => false, 'writes_performed' => false], 0);
}

$stage = 'bootstrap';
try {
    $base = dirname(__DIR__, 2);
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    if ($app->configurationIsCached()) {
        $cached = require $app->getCachedConfigPath();
        $default = $cached['database']['default'] ?? '';
        $cachedConnection = $cached['database']['connections'][$default] ?? [];
        if (($cached['app']['env'] ?? null) !== 'testing' || ($cachedConnection['driver'] ?? null) !== 'mysql'
            || ($cachedConnection['database'] ?? null) !== $database || ! empty($cachedConnection['url'])) {
            scenePreflightOutput(['ok' => false, 'code' => 'SCENE_CACHED_ENVIRONMENT_MISMATCH', 'bootstrap_loaded' => false,
                'database_identity_verified' => false, 'writes_performed' => false], 2);
        }
    }
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = Illuminate\Support\Facades\DB::connection();
    // 配置缓存可能盖过进程变量；不相符时在打开数据库连接前拒绝。
    if (! $app->environment('testing') || $connection->getConfig('driver') !== 'mysql'
        || $connection->getDatabaseName() !== $database) {
        scenePreflightOutput(['ok' => false, 'code' => 'SCENE_RUNTIME_ENVIRONMENT_MISMATCH', 'bootstrap_loaded' => true,
            'database_identity_verified' => false, 'writes_performed' => false], 2);
    }
    $stage = 'database_identity';
    $identity = $connection->selectOne('SELECT DATABASE() AS db_name, VERSION() AS db_version');
    if ($identity->db_name !== $database) {
        scenePreflightOutput(['ok' => false, 'code' => 'SCENE_DATABASE_IDENTITY_MISMATCH', 'database_identity_verified' => false, 'writes_performed' => false], 2);
    }
    $stage = 'schema';
    $schema = $connection->getSchemaBuilder();
    $tables = ['scene_revisions', 'scene_requests', 'scene_request_revisions', 'scene_request_contacts', 'scene_activities',
        'scene_attachments', 'scene_guest_capabilities', 'scene_evidence', 'scene_assessments', 'scene_price_codes', 'scene_price_items',
        'scene_quotes', 'scene_quote_items', 'scene_quote_acceptances', 'scene_quote_decisions', 'scene_resources', 'scene_reservations',
        'scene_projects', 'scene_order_links', 'scene_project_tasks', 'scene_acceptance_records', 'scene_offline_acceptances', 'scene_project_changes',
        'scene_service_tickets', 'scene_commercial_records', 'scene_operation_todos', 'business_outbox', 'business_event_receipts',
        'business_idempotency_keys', 'operation_log', 'scene_policy_kinds', 'scene_policy_versions', 'scene_content_evidence', 'scene_service_clocks', 'scene_attachment_holds', 'scene_site_surveys', 'scene_proposals'];
    $missingTables = array_values(array_filter($tables, fn ($table) => ! $schema->hasTable($table)));
    $requiredColumns = ['scenes' => ['active_revision_id', 'public_seq'],
        'business_outbox' => ['lease_token', 'lease_until', 'processed_at'], 'scene_resources' => ['binding_id', 'row_version'],
        'scene_acceptance_records' => ['occurred_at', 'offline_acceptance_id'],
        'scene_offline_acceptances' => ['row_version', 'project_version', 'content_hash', 'encrypted_attestation'],
        'scene_request_revisions' => ['source_snapshot'], 'scene_revisions' => ['last_edited_by'], 'scene_evidence' => ['binding_snapshot', 'constraint_snapshot', 'skill_snapshot', 'approved_by', 'approved_at', 'review_note', 'revocation_reason'],
        'scene_assessments' => ['survey_id', 'survey_hash', 'rule_version', 'channel_id'], 'scene_proposals' => ['row_version', 'content_hash', 'components', 'review_note'],
        'scene_attachments' => ['retention_version', 'deleted_at', 'revoked_at', 'revocation_reason'], 'scene_operation_todos' => ['row_version'],
        'scene_quotes' => ['row_version', 'customer_visible', 'sent_at', 'proposal_id', 'proposal_snapshot']];
    $missingColumns = [];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                $missingColumns[] = $table.'.'.$column;
            }
        }
    }
    $engines = $connection->table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->whereIn('TABLE_NAME', $tables)->get(['TABLE_NAME', 'ENGINE']);
    $nonTransactional = $engines->filter(fn ($row) => strcasecmp((string) $row->ENGINE, 'InnoDB') !== 0)->pluck('TABLE_NAME')->all();
    $expectedMigrations = array_map(fn ($file) => pathinfo($file, PATHINFO_FILENAME), array_merge(glob($base.'/packages/Webkul/Shop/src/Database/Migrations/2026_10_20_10000*.php'), glob($base.'/packages/Webkul/Shop/src/Database/Migrations/2026_10_21_10000*.php')));
    $ran = $schema->hasTable('migrations') ? $connection->table('migrations')->whereIn('migration', $expectedMigrations)->pluck('migration')->all() : [];
    $pendingMigrations = array_values(array_diff($expectedMigrations, $ran));
    $indexes = [];
    foreach (['scene_quotes' => ['request_id', 'version'], 'scene_quote_decisions' => ['quote_id', 'kind'], 'scene_order_links' => ['quote_id'], 'scene_offline_acceptances' => ['project_id', 'project_version'], 'scene_proposals' => ['request_id', 'version'], 'scene_service_clocks' => ['request_id'], 'scene_policy_versions' => ['kind', 'version']] as $table => $columns) {
        $indexes[$table] = $schema->hasTable($table) && count(array_filter($schema->getIndexes($table), fn ($index) => $index['unique'] && $index['columns'] === $columns)) > 0;
    }
    $stage = 'routes';
    $routeNames = ['shop.api.scene-workflow.capabilities', 'shop.api.scene-requests.store', 'shop.api.scene-requests.compare',
        'shop.api.scene-quotes.accept', 'shop.api.scene-quotes.reject', 'shop.api.scene-projects.action', 'admin.catalog.scene-workflow.quote-action',
        'admin.catalog.scene-workflow.offline-record', 'admin.catalog.scene-workflow.offline-review', 'shop.api.scene-workflow.ai-draft', 'shop.api.scene-requests.upload-receipt',
        'admin.catalog.scene-workflow.survey', 'admin.catalog.scene-workflow.solution', 'admin.catalog.scene-workflow.solution-decision', 'admin.catalog.scene-workflow.evidence-review',
        'admin.catalog.scene-workflow.policy-draft', 'admin.catalog.scene-workflow.policy-decision', 'admin.catalog.scene-workflow.preserved-attachment'];
    $routes = [];
    $collection = app('router')->getRoutes();
    foreach ($routeNames as $name) {
        $route = $collection->getByName($name);
        $routes[$name] = $route ? ['methods' => $route->methods(), 'uri' => $route->uri()] : null;
    }
    $flags = [];
    foreach (['publication_v2', 'requests_enabled', 'quotes_enabled', 'reservations_enabled', 'project_actions_enabled', 'operations_enabled'] as $flag) {
        $flags[$flag] = (bool) config('scenes.'.$flag, false);
    }
    $ok = ! $missingTables && ! $missingColumns && ! $nonTransactional && ! $pendingMigrations
        && ! in_array(false, $indexes, true) && ! in_array(null, $routes, true);
    $policies = [];
    if (! $missingTables && ! $missingColumns) {
        foreach (['commercial', 'retention', 'service_calendar'] as $kind) {
            $policy = app(Webkul\Shop\Services\Scenes\ScenePolicyService::class)->effective($kind);
            $policies[$kind] = $policy ? ['id' => $policy['id'], 'version' => $policy['version'], 'state' => 'approved'] : ['state' => 'not_approved'];
        }
    }
    scenePreflightOutput(['ok' => $ok, 'stage' => 'schema_routes_only', 'mode' => 'read_only', 'app_env' => $app->environment(), 'database' => $identity->db_name,
        'database_version' => $identity->db_version, 'database_identity_verified' => true, 'missing_tables' => $missingTables,
        'missing_columns' => $missingColumns, 'non_transactional_tables' => $nonTransactional, 'pending_migrations' => $pendingMigrations,
        'required_unique_indexes' => $indexes, 'routes' => $routes, 'flags' => $flags, 'order_conversion_available' => false,
        'approved_policies' => $policies,
        'commercial_configuration_present' => ($policies['commercial']['state'] ?? null) === 'approved' || (! empty(config('scenes.terms.version')) && ! empty(config('scenes.terms.text')) && ! empty(config('scenes.commercial_policy.version'))),
        'attachment_scanner_configured' => is_array(config('scenes.attachment_scan_command')),
        'business_fixtures_verified' => false, 'mysql_concurrency_verified' => false, 'writes_performed' => false], $ok ? 0 : 1);
} catch (Throwable $e) {
    // 不输出连接串、SQL、密码、原始异常或业务数据。
    scenePreflightOutput(['ok' => false, 'code' => 'SCENE_PREFLIGHT_FAILED', 'stage' => $stage, 'exception_class' => get_class($e),
        'database_identity_verified' => false, 'writes_performed' => false], 2);
}
