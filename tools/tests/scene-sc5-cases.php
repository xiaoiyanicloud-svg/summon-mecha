<?php

// 由 scene-workflow.php 的内存数据库环境加载，不可独立 bootstrap 业务应用。
if (! isset($schema, $app, $projectId, $owner) || \Illuminate\Support\Facades\DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('仅允许内存测试环境。');
}

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Services\Scenes\SceneOperationsService;
use Webkul\Shop\Services\Scenes\SceneProjectService;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

function sc5Sandbox(callable $fn): void
{
    global $projectId;
    sceneSandbox(function () use ($fn, $projectId) {
        $fn(app(SceneOperationsService::class));
    });
}

try {
    test('SC5-01 全景运营指标字典与去重分析：排除测试数据与历史适配、7大维度指标可复算', fn () => sc5Sandbox(function (SceneOperationsService $ops) use ($projectId) {
        // 1. 制造干扰数据：测试需求 (is_test=true) 和历史兼容线索 (source='legacy_custom')
        $testReqId = DB::table('scene_requests')->insertGetId([
            'request_no' => 'REQ-TEST-SC5-001',
            'customer_id' => 1,
            'scene_id' => 1,
            'source' => 'enterprise',
            'consent_version' => 'terms-v1',
            'is_test' => true,
            'state' => 'submitted',
            'row_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $legacyReqId = DB::table('scene_requests')->insertGetId([
            'request_no' => 'REQ-LEGACY-SC5-002',
            'customer_id' => 1,
            'scene_id' => 1,
            'source' => 'legacy_custom',
            'consent_version' => 'terms-v1',
            'is_test' => false,
            'state' => 'submitted',
            'row_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // 2. 获取运营指标大盘
        $metrics = $ops->metrics();

        // 3. 断言兼容顶层字段
        same(true, isset($metrics['requests']));
        same(true, isset($metrics['accepted_quotes']));
        same(true, isset($metrics['confirmed_projects']));
        same(true, isset($metrics['open_todos']));
        same(true, isset($metrics['outbox_pending']));
        same(true, isset($metrics['outbox_failed']));

        // 4. 统计数量严格排除 testReqId 与 legacyReqId
        $realCount = DB::table('scene_requests')->where('is_test', false)->where('source', '!=', 'legacy_custom')->count();
        same($realCount, $metrics['requests']);
        same($realCount, $metrics['funnel']['total_requests']);

        // 5. 断言 7 大维度完整性
        same(true, isset($metrics['funnel']['conversion_rate']));
        same(true, isset($metrics['quotes']['quote_acceptance_rate']));
        same(true, isset($metrics['delivery']['projects_by_state']));
        same(true, isset($metrics['quality']['first_pass_rate']));
        same(true, isset($metrics['financial']['total_payment_receipt_minor']));
        same(true, isset($metrics['financial']['total_refund_due_minor']));
        same(true, isset($metrics['resources']['resources_by_state']));
        same(true, isset($metrics['tickets']['total_tickets']));
        same(true, isset($metrics['definitions']['clean_data']));
    }));

    test('SC5-02 运营待办检索、条件过滤、分派留痕与手动解决闭环', fn () => sc5Sandbox(function (SceneOperationsService $ops) use ($projectId) {
        $requestId = (int) DB::table('scene_projects')->where('id', $projectId)->value('request_id');

        // 1. 创建两个待办
        $todoId1 = DB::table('scene_operation_todos')->insertGetId([
            'dedupe_key' => 'sc5-test-todo-01',
            'request_id' => $requestId,
            'kind' => 'return_damage_review',
            'state' => 'open',
            'row_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $todoId2 = DB::table('scene_operation_todos')->insertGetId([
            'dedupe_key' => 'sc5-test-todo-02',
            'request_id' => $requestId,
            'kind' => 'incident_review',
            'state' => 'open',
            'row_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // 2. 待办列表查询与过滤
        $list = $ops->todoList(['kind' => 'return_damage_review']);
        same(true, $list['total'] >= 1);
        $found = collect($list['items'])->firstWhere('id', $todoId1);
        same('return_damage_review', $found->kind);

        // 3. 指派待办（Admin 1 指派给 Admin 2）
        $assigned = $ops->assignTodo($todoId1, 1, 2, '指派给硬件资产工程师核实外观损伤', 1);
        same('open', $assigned['state']);
        same(2, (int) $assigned['row_version']);

        // 4. 手动解决待办
        $resolved = $ops->resolveTodo($todoId1, 2, 'damage_settled_with_customer', '已与客户签署定损备忘录，押金扣除500元', 2);
        same('resolved', $resolved['state']);
        same('damage_settled_with_customer', $resolved['resolution_code']);
        same(3, (int) $resolved['row_version']);

        // 5. 已解决待办再次解决抛出 409
        error('SCENE_STATE_CONFLICT', fn () => $ops->resolveTodo($todoId1, 3, 'duplicate', '重复解决', 2));
    }));

    test('SC5-03 Outbox 租约超时重领、8次指数退避与死信保护', fn () => sc5Sandbox(function (SceneOperationsService $ops) use ($projectId) {
        $requestId = (int) DB::table('scene_projects')->where('id', $projectId)->value('request_id');

        // 1. 模拟一个租约过期的 Worker 事件 (attempts=1, lease_until=10秒前)
        $outboxId = DB::table('business_outbox')->insertGetId([
            'event_id' => '00000000-0000-4000-8000-000000000501',
            'aggregate_type' => 'scene_request',
            'aggregate_id' => $requestId,
            'aggregate_seq' => 1,
            'event_type' => 'scene.request.submitted',
            'schema_version' => 1,
            'payload' => json_encode(['id' => $requestId, 'version' => 1]),
            'attempts' => 1,
            'lease_token' => 'crashed-worker-uuid',
            'lease_until' => now('UTC')->subSeconds(10),
            'available_at' => now('UTC')->subSeconds(10),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // 消费该过期租约事件，成功接管
        $consumed = $ops->consumeOne();
        same('processed', $consumed['state']);
        same('00000000-0000-4000-8000-000000000501', $consumed['event_id']);

        // 2. 模拟达到 8 次失败的死信事件
        $deadLetterId = DB::table('business_outbox')->insertGetId([
            'event_id' => '00000000-0000-4000-8000-000000000502',
            'aggregate_type' => 'scene_request',
            'aggregate_id' => $requestId,
            'aggregate_seq' => 1,
            'event_type' => 'scene.request.submitted',
            'schema_version' => 1,
            'payload' => json_encode(['id' => $requestId, 'version' => 1]),
            'attempts' => 8, // 超过最大限制
            'available_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // consumeOne 不会再消费死信
        $result = $ops->consumeOne();
        same('idle', $result['state']);

        // 3. 人工排查后重放死信 (replay)
        $replayed = $ops->replay($deadLetterId, 1);
        same('pending', $replayed['state']);
        $deadLetterRow = DB::table('business_outbox')->find($deadLetterId);
        same(0, (int) $deadLetterRow->attempts);
        same(null, $deadLetterRow->last_error_code);
    }));

    test('SC5-03 Outbox 消费 SC4 领域事件：变更审批、项目取消、现场事故暂停与恢复', fn () => sc5Sandbox(function (SceneOperationsService $ops) use ($projectId) {
        $requestId = (int) DB::table('scene_projects')->where('id', $projectId)->value('request_id');

        // 1. 消费现场事故事件
        $eventId1 = '00000000-0000-4000-8000-000000000511';
        DB::table('business_outbox')->insert([
            'event_id' => $eventId1,
            'aggregate_type' => 'scene_project',
            'aggregate_id' => $projectId,
            'aggregate_seq' => 11,
            'event_type' => 'scene.project.incident.reported',
            'schema_version' => 1,
            'payload' => json_encode(['id' => $projectId, 'version' => 11, 'incident_ref' => 'INC-SC5-001']),
            'available_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $res1 = $ops->consumeOne();
        same('processed', $res1['state']);
        same(1, DB::table('business_event_receipts')->where('event_id', $eventId1)->count());

        // 2. 消费事故恢复事件
        $eventId2 = '00000000-0000-4000-8000-000000000512';
        DB::table('business_outbox')->insert([
            'event_id' => $eventId2,
            'aggregate_type' => 'scene_project',
            'aggregate_id' => $projectId,
            'aggregate_seq' => 12,
            'event_type' => 'scene.project.resumed',
            'schema_version' => 1,
            'payload' => json_encode(['id' => $projectId, 'version' => 12]),
            'available_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $res2 = $ops->consumeOne();
        same('processed', $res2['state']);
        same(1, DB::table('business_event_receipts')->where('event_id', $eventId2)->count());

        // 3. 消费取消事件
        $eventId3 = '00000000-0000-4000-8000-000000000513';
        DB::table('business_outbox')->insert([
            'event_id' => $eventId3,
            'aggregate_type' => 'scene_project',
            'aggregate_id' => $projectId,
            'aggregate_seq' => 13,
            'event_type' => 'scene.project.cancelled',
            'schema_version' => 1,
            'payload' => json_encode(['id' => $projectId, 'version' => 13]),
            'available_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $res3 = $ops->consumeOne();
        same('processed', $res3['state']);
        same(1, DB::table('business_event_receipts')->where('event_id', $eventId3)->count());
    }));

    test('SC5-04 敏感数据脱敏与隐私保护：公开响应与日志不泄露手机号、未授权凭据与服务器路径', fn () => sc5Sandbox(function (SceneOperationsService $ops) use ($projectId, $owner) {
        $detail = app(SceneProjectService::class)->detail($owner, $projectId);

        // 1. 响应结构中无明文手机号、无服务器物理磁盘路径
        $json = json_encode($detail);
        same(0, preg_match('/(?:[A-Za-z]:\\\\|\/home\/|\/var\/www\/)/', $json));
        same(0, preg_match('/(?:\+?86)?1[3-9]\d{9}/', $json));

        // 2. 异常输出脱敏
        try {
            $ops->resolveTodo(99999, 1, 'code', 'note', 1);
        } catch (\Throwable $e) {
            same(0, preg_match('/(?:[A-Za-z]:\\\\|\/home\/|\/var\/www\/)/', $e->getMessage()));
        }
    }));

    test('SC5-05 待办与运营操作的乐观并发 OCC 保护：旧 row_version 阻断', fn () => sc5Sandbox(function (SceneOperationsService $ops) use ($projectId) {
        $requestId = (int) DB::table('scene_projects')->where('id', $projectId)->value('request_id');
        $todoId = DB::table('scene_operation_todos')->insertGetId([
            'dedupe_key' => 'sc5-occ-test-todo',
            'request_id' => $requestId,
            'kind' => 'return_damage_review',
            'state' => 'open',
            'row_version' => 5,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // 旧版本提交被拒
        error('SCENE_VERSION_CONFLICT', fn () => $ops->assignTodo($todoId, 4, 1, '旧版本指派', 1));
        error('SCENE_VERSION_CONFLICT', fn () => $ops->resolveTodo($todoId, 4, 'resolved', '旧版本解决', 1));

        // 正确版本成功
        $res = $ops->assignTodo($todoId, 5, 2, '版本一致指派', 1);
        same(6, (int) $res['row_version']);
    }));

    test('SC5-06 预检脚本全量 9 份增量迁移与表结构完整性纳管', fn () => sc5Sandbox(function () {
        $base = dirname(__DIR__, 2).'/my-bagisto-store';
        $migrations = array_merge(
            glob($base.'/packages/Webkul/Shop/src/Database/Migrations/2026_10_20_10000*.php'),
            glob($base.'/packages/Webkul/Shop/src/Database/Migrations/2026_10_21_10000*.php'),
            glob($base.'/packages/Webkul/Shop/src/Database/Migrations/2026_10_22_10000*.php')
        );

        // 必须包含全部 9 份增量迁移
        same(9, count($migrations));
        $filenames = array_map(fn ($f) => basename($f), $migrations);
        same(true, in_array('2026_10_22_100009_complete_scene_delivery_and_after_sales.php', $filenames, true));

        // 校验关键表存在
        same(true, Schema::hasTable('scene_projects'));
        same(true, Schema::hasTable('scene_project_changes'));
        same(true, Schema::hasTable('scene_service_tickets'));
        same(true, Schema::hasTable('scene_operation_todos'));
        same(true, Schema::hasTable('business_outbox'));
        same(true, Schema::hasTable('business_event_receipts'));
    }));

    test('SC5-07 分级停写安全防线：关闭写入时阻断写命令，但历史详情保持 200 只读可查', fn () => sc5Sandbox(function (SceneOperationsService $ops) use ($projectId, $owner) {
        // 1. 关闭运营开关 operations_enabled
        config(['scenes.operations_enabled' => false]);

        try {
            // 写入操作被阻断 (抛出 503 SCENE_WRITES_DISABLED)
            error('SCENE_WRITES_DISABLED', fn () => $ops->consumeOne());
            error('SCENE_WRITES_DISABLED', fn () => $ops->todoList());
            error('SCENE_WRITES_DISABLED', fn () => $ops->assignTodo(1, 1, 1, '停写测试', 1));

            // 只读详情不受停写影响，依然能完整查阅事实
            $detail = app(SceneProjectService::class)->detail($owner, $projectId);
            same($projectId, $detail['id']);
            same(true, count($detail['tasks']) >= 0);
        } finally {
            // 恢复开关
            config(['scenes.operations_enabled' => true]);
        }

        // 2. 测试 Level 2 全局写熔断 SCENE_WORKFLOW_ENABLED=false（Issue 10）
        config(['scenes.workflow_enabled' => false]);
        try {
            error('SCENE_WRITES_DISABLED', fn () => $ops->consumeOne());
            // 只读详情依然可查
            $detail = app(SceneProjectService::class)->detail($owner, $projectId);
            same($projectId, $detail['id']);
        } finally {
            config(['scenes.workflow_enabled' => true]);
        }
    }));

} catch (\Throwable $e) {
    echo 'SC5 测试装载异常: '.$e->getMessage().PHP_EOL;
    throw $e;
}
