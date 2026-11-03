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

    // 上报执行完成
    $service = app(DeployTaskService::class);
    $service->onEvent($task->task_no, 'done');

    $task->refresh();
    $execution->refresh();

    expect($task->status)->toBe('success');
    expect($execution->status)->toBe(SkillExecution::STATUS_SUCCEEDED);

    // 核心红线断言：绝不插入 InstallRecord
    $installRecordCount = InstallRecord::where('did', $did)->where('skill_version_id', 303)->count();
    expect($installRecordCount)->toBe(0);
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

    $res = postJson(route('shop.api.agent.reconcile'), [
        'did'    => $did,
        'skills' => [
            [
                'skill_version_id' => 5001,
                'status'           => 'installed',
            ],
            [
                'skill_version_id' => 5002, // 云端没有
                'status'           => 'installed',
            ],
        ],
    ], [
        'X-Agent-Key' => $agentKey,
    ]);

    $res->assertOk();
    expect($res->json('data.aligned'))->toBeTrue();
    $details = $res->json('data.details');
    expect($details)->toHaveCount(1);
    expect($details[0]['skill_version_id'])->toBe(5001);
    expect($details[0]['match'])->toBeTrue();
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
