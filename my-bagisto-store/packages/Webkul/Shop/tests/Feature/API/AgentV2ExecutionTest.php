<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\Contracts\ExecutionFact;
use Webkul\Shop\Services\Deploy\Contracts\StopObservation;
use Webkul\Shop\Services\Deploy\DeployTaskService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 设备端 Agent v2 协议、执行（OP 7/8）、ACK 与对账端到端测试。
 *
 * 验证核心纪律：
 * 1. 旧 Agent（未声明 agent-task/v2）拉取指令时不被下发 OP 7/8（防旧端崩溃）。
 * 2. v2 Agent 正确拉取到 OP_EXECUTE 与 OP_CANCEL_EXECUTION。
 * 3. OP_EXECUTE 成功仅推进 SkillExecution 为 completed，绝不污染 InstallRecord。
 * 4. POST api/agent/ack 正确处理事件确认。
 * 5. POST api/agent/reconcile 准确比对端侧安装状态与服务端记录。
 * 6. 客户侧发起执行 POST /api/skills/entitlements/{id}/executions 与取消 POST /api/skills/executions/{id}/cancel 正常受理。
 */

function createV2Customer(): Customer
{
    return Customer::create([
        'first_name'  => '执行',
        'last_name'   => '测试',
        'email'       => 'v2_'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

it('旧 Agent 轮询时过滤掉 OP_EXECUTE(7) 与 OP_CANCEL_EXECUTION(8)', function () {
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);

    $did = 'did_legacy_'.uniqid();
    DeviceBinding::create([
        'did'         => $did,
        'sn'          => 'SN_LEGACY_'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    // 创建 OP_EXECUTE 任务
    $task = DeployTask::create([
        'task_no'          => 'T_V2_OLD_'.uniqid(),
        'command_seq'      => 1,
        'did'              => $did,
        'skill_id'         => 101,
        'operation'        => DeployTask::OP_EXECUTE,
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'status'           => 'dispatching',
        'payload'          => [],
    ]);

    // 1. 旧 Agent 不传 protocol_version -> 查不到任务
    $resOld = getJson(route('shop.api.agent.commands', ['did' => $did]), [
        'X-Agent-Key' => $agentKey,
    ]);
    $resOld->assertOk();
    expect($resOld->json('data'))->toBeEmpty();

    // 2. v2 Agent 带协议版本 -> 查到 OP_EXECUTE 任务
    $resV2 = getJson(route('shop.api.agent.commands', [
        'did'              => $did,
        'protocol_version' => DeployTask::PROTOCOL_V2,
    ]), [
        'X-Agent-Key' => $agentKey,
    ]);
    $resV2->assertOk();
    $data = $resV2->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['task_id'])->toBe($task->task_no);
    expect((int)$data[0]['operation'])->toBe(DeployTask::OP_EXECUTE);
});

it('OP_EXECUTE 执行成功仅更新 SkillExecution 状态，绝不污染 InstallRecord', function () {
    $did = 'did_exec_'.uniqid();
    $execId = 'exec_fact_'.uniqid();

    $task = DeployTask::create([
        'task_no'          => 'T_EXEC_FACT_'.uniqid(),
        'command_seq'      => 1,
        'did'              => $did,
        'skill_id'         => 202,
        'skill_version_id' => 303,
        'operation'        => DeployTask::OP_EXECUTE,
        'execution_id'     => $execId,
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'status'           => 'dispatching',
        'delivered_at'     => now(),
        'payload'          => [],
    ]);

    $execution = SkillExecution::create([
        'execution_id'     => $execId,
        'did'              => $did,
        'skill_id'         => 202,
        'skill_version_id' => 303,
        'entitlement_id'   => 404,
        'deploy_task_id'   => $task->id,
        'status'           => SkillExecution::STATUS_RUNNING,
    ]);

    // 上报执行完成：SUCCEEDED 必须携带独立停止观察
    // ⚠ 本观察器是 synthetic-test-only，仅用于契约测试，不得作为 HIL 证据。
    $service = app(DeployTaskService::class);
    $service->onEvent(
        $task->task_no,
        'done',
        executionFact: new ExecutionFact(
            executionId: $execId,
            observedStatus: 'SUCCEEDED',
            actualStarted: true,
            actualStopped: true,
            startedAt: now()->subSeconds(2)->timestamp,
            stoppedAt: now()->timestamp,
            errorCode: null,
            evidence: ['observer' => 'device_adapter_observe', 'status' => 'SUCCEEDED'],
            reportedAt: now()->timestamp,
            stopObservation: new StopObservation(
                executionId: $execId,
                sourceType: 'independent_watchdog',
                sensorId: 'synthetic:test-watchdog',
                observedAt: now()->timestamp,
                actualStarted: true,
                actualStopped: true,
                velocity: 0.0,
                current: 0.0,
                contact: true,
                brake: true,
                power: true,
                quality: 'good',
                rawEvidenceSha256: str_repeat('a', 64),
                collectorVersion: 'synthetic-test-only',
            ),
        ),
        eventSeq: 3,
    );

    $task->refresh();
    $execution->refresh();

    expect($task->status)->toBe('success');
    expect($execution->status)->toBe(SkillExecution::STATUS_SUCCEEDED);

    // 核心红线断言：绝不插入 InstallRecord
    $installRecordCount = InstallRecord::where('did', $did)->where('skill_version_id', 303)->count();
    expect($installRecordCount)->toBe(0);
});

it('POST api/agent/evt 对执行事实幂等入账，并拒绝同键异载荷', function () {
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);
    $did = 'did_fact_'.uniqid();
    $execId = 'exec_fact_api_'.uniqid();

    DeviceBinding::create([
        'did' => $did,
        'sn' => 'SN_FACT_'.uniqid(),
        'source' => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $task = DeployTask::create([
        'task_no' => 'T_FACT_'.uniqid(),
        'command_seq' => 1,
        'did' => $did,
        'skill_id' => 901,
        'skill_version_id' => 902,
        'operation' => DeployTask::OP_EXECUTE,
        'execution_id' => $execId,
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'status' => 'dispatching',
        'delivered_at' => now(),
    ]);
    SkillExecution::create([
        'execution_id' => $execId,
        'deploy_task_id' => $task->id,
        'did' => $did,
        'skill_id' => 901,
        'skill_version_id' => 902,
        'status' => SkillExecution::STATUS_ACCEPTED,
    ]);

    $headers = ['X-Agent-Key' => $agentKey];
    $now = now()->timestamp;

    $accepted = postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did' => $did,
        'event_id' => 'evt_accept_'.uniqid(),
        'event_seq' => 1,
        'phase' => 'accepted',
        'execution_id' => $execId,
        'actual_started' => false,
        'actual_stopped' => false,
        'evidence' => ['observer' => 'device_adapter_observe', 'stage' => 'accepted'],
        'reported_at' => $now,
    ], $headers);
    $accepted->assertOk();

    $running = postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did' => $did,
        'event_id' => 'evt_running_'.uniqid(),
        'event_seq' => 2,
        'phase' => 'running',
        'execution_id' => $execId,
        'actual_started' => true,
        'actual_stopped' => false,
        'started_at' => $now,
        'evidence' => ['observer' => 'device_adapter_observe', 'stage' => 'running'],
        'reported_at' => $now,
    ], $headers);
    $running->assertOk();

    $donePayload = [
        'task_no' => $task->task_no,
        'did' => $did,
        'event_id' => 'evt_done_'.uniqid(),
        'event_seq' => 3,
        'phase' => 'done',
        'execution_id' => $execId,
        'actual_started' => true,
        'actual_stopped' => true,
        'started_at' => $now,
        'stopped_at' => $now + 1,
        'evidence' => ['observer' => 'device_adapter_observe', 'stage' => 'done'],
        // ⚠ synthetic-test-only：仅验证平台侧 StopObservation 契约，不代表真机 HIL。
        'stop_observation' => [
            'execution_id'        => $execId,
            'source_type'         => 'independent_watchdog',
            'sensor_id'           => 'synthetic:test-watchdog',
            'observed_at'         => $now + 1,
            'actual_started'      => true,
            'actual_stopped'      => true,
            'velocity'            => 0.0,
            'current'             => 0.0,
            'contact'             => true,
            'brake'               => true,
            'power'               => true,
            'quality'             => 'good',
            'raw_evidence_sha256' => str_repeat('a', 64),
            'collector_version'   => 'synthetic-test-only',
        ],
        'reported_at' => $now + 1,
    ];
    $done = postJson(route('shop.api.agent.event'), $donePayload, $headers);
    $done->assertOk()->assertJsonPath('data.status', 'success');

    $duplicate = postJson(route('shop.api.agent.event'), $donePayload, $headers);
    $duplicate->assertOk()->assertJsonPath('data.duplicate', true);
    expect($duplicate->json('data.ack_id'))->toBe($done->json('data.ack_id'));

    $conflict = postJson(
        route('shop.api.agent.event'),
        array_replace($donePayload, ['error_code' => 'TAMPERED']),
        $headers,
    );
    $conflict->assertStatus(409)->assertJsonPath('code', 'CONFLICT');

    $execution = SkillExecution::where('execution_id', $execId)->firstOrFail();
    expect($execution->status)->toBe(SkillExecution::STATUS_SUCCEEDED);
    expect($execution->actual_started)->toBeTrue();
    expect($execution->actual_stopped)->toBeTrue();
    expect($execution->execution_evidence['observer'])->toBe('device_adapter_observe');
    expect(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(3);
});

it('POST api/agent/evt 拒绝没有停止证据的执行成功', function () {
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);
    $did = 'did_no_stop_'.uniqid();
    $execId = 'exec_no_stop_'.uniqid();

    DeviceBinding::create([
        'did' => $did,
        'sn' => 'SN_NO_STOP_'.uniqid(),
        'source' => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $task = DeployTask::create([
        'task_no' => 'T_NO_STOP_'.uniqid(),
        'command_seq' => 1,
        'did' => $did,
        'skill_id' => 911,
        'operation' => DeployTask::OP_EXECUTE,
        'execution_id' => $execId,
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'status' => 'installing',
        'delivered_at' => now(),
    ]);
    SkillExecution::create([
        'execution_id' => $execId,
        'deploy_task_id' => $task->id,
        'did' => $did,
        'skill_id' => 911,
        'status' => SkillExecution::STATUS_RUNNING,
    ]);

    $response = postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did' => $did,
        'event_id' => 'evt_no_stop_'.uniqid(),
        'event_seq' => 1,
        'phase' => 'done',
        'execution_id' => $execId,
        'actual_started' => true,
        'actual_stopped' => false,
        'started_at' => now()->timestamp,
        'evidence' => ['observer' => 'device_adapter_observe'],
        'reported_at' => now()->timestamp,
    ], ['X-Agent-Key' => $agentKey]);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    expect($task->fresh()->status)->toBe('installing');
    expect(SkillExecution::where('execution_id', $execId)->value('status'))->toBe(SkillExecution::STATUS_RUNNING);
    expect(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(0);
});

it('取消请求不是物理停止，UNKNOWN 终态不被迟到成功静默改写', function () {
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);
    $did = 'did_unknown_'.uniqid();
    $execId = 'exec_unknown_'.uniqid();

    DeviceBinding::create([
        'did' => $did,
        'sn' => 'SN_UNKNOWN_'.uniqid(),
        'source' => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $task = DeployTask::create([
        'task_no' => 'T_UNKNOWN_'.uniqid(),
        'command_seq' => 1,
        'did' => $did,
        'skill_id' => 921,
        'operation' => DeployTask::OP_EXECUTE,
        'execution_id' => $execId,
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'status' => 'dispatching',
        'delivered_at' => now(),
    ]);
    SkillExecution::create([
        'execution_id' => $execId,
        'deploy_task_id' => $task->id,
        'did' => $did,
        'skill_id' => 921,
        'status' => SkillExecution::STATUS_RUNNING,
        'started_at' => now()->subSecond(),
    ]);

    $headers = ['X-Agent-Key' => $agentKey];
    $base = [
        'task_no' => $task->task_no,
        'did' => $did,
        'execution_id' => $execId,
        'actual_started' => true,
        'started_at' => now()->subSecond()->timestamp,
        'evidence' => ['observer' => 'device_adapter_observe'],
        'reported_at' => now()->timestamp,
    ];

    $cancelRequested = postJson(route('shop.api.agent.event'), array_replace($base, [
        'event_id' => 'evt_cancel_req_'.uniqid(),
        'event_seq' => 1,
        'phase' => 'cancel_requested',
        'actual_stopped' => false,
    ]), $headers);
    $cancelRequested->assertOk();
    expect(SkillExecution::where('execution_id', $execId)->value('status'))
        ->toBe(SkillExecution::STATUS_CANCEL_REQUESTED);
    expect($task->fresh()->status)->toBe('dispatching');

    $unknown = postJson(route('shop.api.agent.event'), array_replace($base, [
        'event_id' => 'evt_unknown_'.uniqid(),
        'event_seq' => 2,
        'phase' => 'unknown',
        'actual_stopped' => false,
        'error_code' => 'STOP_UNCONFIRMED',
    ]), $headers);
    $unknown->assertOk();
    expect(SkillExecution::where('execution_id', $execId)->value('status'))->toBe(SkillExecution::STATUS_UNKNOWN);
    expect($task->fresh()->status)->toBe('timeout');

    $late = postJson(route('shop.api.agent.event'), array_replace($base, [
        'event_id' => 'evt_late_done_'.uniqid(),
        'event_seq' => 3,
        'phase' => 'done',
        'actual_stopped' => true,
        'stopped_at' => now()->timestamp,
    ]), $headers);
    $late->assertOk()->assertJsonPath('data.late_report', true)->assertJsonPath('data.status', 'timeout');

    expect(SkillExecution::where('execution_id', $execId)->value('status'))->toBe(SkillExecution::STATUS_UNKNOWN);
    expect($task->fresh()->status)->toBe('timeout');
    expect(DB::table('deploy_inbox_event')->where('did', $did)->where('late_report', true)->count())->toBe(1);
});
it('POST api/agent/ack 支持端云双向事件确认', function () {
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);

    $did = 'did_ack_'.uniqid();
    DeviceBinding::create([
        'did'         => $did,
        'sn'          => 'SN_ACK_'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $task = DeployTask::create([
        'task_no'          => 'T_ACK_'.uniqid(),
        'command_seq'      => 1,
        'did'              => $did,
        'skill_id'         => 101,
        'operation'        => DeployTask::OP_INSTALL,
        'status'           => 'dispatching',
        'payload'          => [],
    ]);

    $res = postJson(route('shop.api.agent.ack'), [
        'task_no'  => $task->task_no,
        'did'      => $did,
        'event_id' => 'evt_local_001',
        'stage'    => 'staged',
    ], [
        'X-Agent-Key' => $agentKey,
    ]);

    $res->assertOk();
    expect($res->json('data.status'))->toBe('acknowledged');
    expect($res->json('data.event_id'))->toBe('evt_local_001');
    expect($res->json('data.ack_id'))->not->toBeEmpty();
});

it('POST api/agent/reconcile 端侧安装事实与云端记录准确对账', function () {
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);

    $did = 'did_rec_'.uniqid();
    DeviceBinding::create([
        'did'         => $did,
        'sn'          => 'SN_REC_'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    InstallRecord::create([
        'did'              => $did,
        'skill_version_id' => 5001,
        'status'           => 'installed',
        'current_version'  => '1.0.0',
        'installed_at'     => now(),
        'channel'          => 'agent',
    ]);

    $reportedAt = now()->timestamp;
    $res = postJson(route('shop.api.agent.reconcile'), [
        'did' => $did,
        'model_code' => 'mock',
        'observed_generation' => 7,
        'skills' => [
            [
                'skill_version_id' => 5001,
                'status' => 'installed',
            ],
            [
                'skill_version_id' => 5002, // 云端没有
                'status' => 'installed',
            ],
        ],
        'active_executions' => [],
        'outbox_cursor' => 'evt_cursor_5001',
        'reported_at' => $reportedAt,
    ], [
        'X-Agent-Key' => $agentKey,
    ]);

    $res->assertOk();
    expect($res->json('data.aligned'))->toBeFalse();
    $details = $res->json('data.details');
    expect($details)->toHaveCount(2);
    expect($details[0]['skill_version_id'])->toBe(5001);
    expect($details[0]['match'])->toBeTrue();
    expect($details[1]['skill_version_id'])->toBe(5002);
    expect($details[1]['match'])->toBeFalse();
    expect($res->json('data.observed_generation'))->toBe(7);
    expect($res->json('data.report_hash'))->not->toBeEmpty();
    expect(DB::table('device_reconcile_report')->where('did', $did)->count())->toBe(1);

    $duplicate = postJson(route('shop.api.agent.reconcile'), [
        'did' => $did,
        'model_code' => 'mock',
        'observed_generation' => 7,
        'skills' => [
            ['skill_version_id' => 5001, 'status' => 'installed'],
            ['skill_version_id' => 5002, 'status' => 'installed'],
        ],
        'active_executions' => [],
        'outbox_cursor' => 'evt_cursor_5001',
        'reported_at' => $reportedAt,
    ], ['X-Agent-Key' => $agentKey]);
    $duplicate->assertOk();
    expect(DB::table('device_reconcile_report')->where('did', $did)->count())->toBe(1);
});

it('旧 event_seq 不得覆盖新 event_seq', function () {
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);
    $did = 'did_seq_'.uniqid();
    $execId = 'exec_seq_'.uniqid();

    DeviceBinding::create([
        'did' => $did,
        'sn' => 'SN_SEQ_'.uniqid(),
        'source' => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $task = DeployTask::create([
        'task_no' => 'T_SEQ_'.uniqid(),
        'command_seq' => 1,
        'did' => $did,
        'skill_id' => 911,
        'skill_version_id' => 912,
        'operation' => DeployTask::OP_EXECUTE,
        'execution_id' => $execId,
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'status' => 'dispatching',
        'delivered_at' => now(),
    ]);
    SkillExecution::create([
        'execution_id' => $execId,
        'deploy_task_id' => $task->id,
        'did' => $did,
        'skill_id' => 911,
        'skill_version_id' => 912,
        'status' => SkillExecution::STATUS_ACCEPTED,
    ]);

    $headers = ['X-Agent-Key' => $agentKey];
    $now = now()->timestamp;
    $running = postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did' => $did,
        'event_id' => 'evt_seq_2_'.uniqid(),
        'event_seq' => 2,
        'phase' => 'running',
        'execution_id' => $execId,
        'actual_started' => true,
        'actual_stopped' => false,
        'started_at' => $now,
        'evidence' => ['observer' => 'device_adapter_observe', 'stage' => 'running'],
        'reported_at' => $now,
    ], $headers);
    $running->assertOk();

    $stale = postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did' => $did,
        'event_id' => 'evt_seq_1_'.uniqid(),
        'event_seq' => 1,
        'phase' => 'installing',
        'execution_id' => $execId,
        'actual_started' => true,
        'actual_stopped' => false,
        'started_at' => $now,
        'evidence' => ['observer' => 'device_adapter_observe', 'stage' => 'stale'],
        'reported_at' => $now + 1,
    ], $headers);
    $stale->assertStatus(409)->assertJsonPath('code', 'TASK_TRANSITION_INVALID');

    $execution = SkillExecution::where('execution_id', $execId)->firstOrFail();
    expect($execution->last_event_seq)->toBe(2)
        ->and($execution->status)->toBe(SkillExecution::STATUS_RUNNING);
});
it('客户侧能够发起技能执行与取消，流转为 OP 7/8 任务', function () {
    $customer = createV2Customer();
    $did = 'did_cust_exec_'.uniqid();

    $binding = DeviceBinding::create([
        'did'               => $did,
        'sn'                => 'SN_CUST_'.uniqid(),
        'source'            => DeviceBinding::SOURCE_AGENT,
        'bind_status'       => 'bound',
        'owner_customer_id' => $customer->id,
    ]);

    $device = Device::create([
        'customer_id' => $customer->id,
        'binding_id'  => $binding->id,
        'name'        => '我的机器人',
        'sn'          => $binding->sn,
        'model_code'  => 'unitree_g1',
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'sku'        => 'v2-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $version = SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);

    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'device_id'        => $device->id,
        'product_id'       => $productId,
        'skill_version_id' => $version->id,
        'delivery_channel' => 'agent',
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'meta'             => [
            'installed_did' => $did,
        ],
    ]);

    // 1. 发起执行
    actingAs($customer, 'customer');
    $execRes = postJson(route('shop.api.skills.executions.trigger', ['id' => $entitlement->id]), [
        'params' => ['speed' => 1.2],
    ]);

    $execRes->assertStatus(202);
    $executionId = $execRes->json('data.execution_id');
    expect($executionId)->not->toBeEmpty();

    // 检查 DeployTask 生成
    $task = DeployTask::where('execution_id', $executionId)->first();
    expect($task)->not->toBeNull();
    expect((int)$task->operation)->toBe(DeployTask::OP_EXECUTE);
    expect($task->protocol_version)->toBe(DeployTask::PROTOCOL_V2);

    // 2. 查看执行状态
    $statusRes = getJson(route('shop.api.skills.executions.status', ['id' => $executionId]));
    $statusRes->assertOk();
    expect($statusRes->json('data.status'))->toBe(SkillExecution::STATUS_ACCEPTED);

    // 3. 取消执行
    $cancelRes = postJson(route('shop.api.skills.executions.cancel', ['id' => $executionId]));
    $cancelRes->assertStatus(202);
    expect($cancelRes->json('data.status'))->toBe(SkillExecution::STATUS_CANCEL_REQUESTED);

    // 检查取消任务生成
    $cancelTask = DeployTask::where('execution_id', $executionId)
        ->where('operation', DeployTask::OP_CANCEL_EXECUTION)
        ->first();
    expect($cancelTask)->not->toBeNull();
});
