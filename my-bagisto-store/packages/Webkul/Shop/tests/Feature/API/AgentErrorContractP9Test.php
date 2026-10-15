<?php

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * P9 · 机器消费者(设备端 Agent)的错误码契约。
 *
 * 两处缺口:
 *   1. `AgentController` 的成功体是裸 `{data}`、失败体只有中文 message 无 code ——
 *      Agent 是机器消费者,没有"人看着办"的余地,结构不一致比 UI 更致命。
 *      统一走 X1 信封 `ok()`/`fail()`。
 *   2. `DeployTaskService::onEvent()` 忽略 `transition()` 的返回值:对一条**已 success**
 *      的任务报 `failed`,服务端什么都没做却回 **200 'ok'** —— 端侧认为报成功了、
 *      云侧状态没动,**端云永久分叉**,且服务端一行日志都不留。
 *      现在非法回报 → 409 + `TASK_TRANSITION_INVALID`。
 */

function p9Key(): string
{
    return 'pest-p9-key';
}

function p9Headers(): array
{
    return ['X-Agent-Key' => p9Key()];
}

function p9Task(string $did, string $status): DeployTask
{
    return DeployTask::create([
        'task_no'    => 'P9'.now()->format('ymdHis').random_int(100, 999),
        'did'        => $did,
        'channel'    => 'agent',
        'skill_id'   => 930000 + random_int(1, 9999),
        'operation'  => DeployTask::OP_INSTALL,
        'status'     => $status,
        'created_by' => 'test',
    ]);
}

function p9Binding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'         => 'did_p9_'.uniqid(),
        'sn'          => 'P9-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
        'bound_at'    => now(),
    ]);
}

beforeEach(function () {
    config(['deploy_gateways.agent_key' => p9Key()]);
});

// ───────────── 1 · 非法流转必须被明确拒绝(而不是 200 'ok') ─────────────

it('P9 · 对已 success 的任务报 failed → 409 + TASK_TRANSITION_INVALID,状态不动', function () {
    $task = p9Task('did_p9_illegal', 'success');

    postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did'     => $task->did,
        'phase'   => 'failed',
    ], p9Headers())
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'TASK_TRANSITION_INVALID')
        // 端侧要能知道云侧的当前状态,才谈得上"分叉后自愈"
        ->assertJsonPath('data.status', 'success');

    expect($task->fresh()->status)->toBe('success');
});

it('P9 · 重复报 done(已是 success)是幂等短路,不报错', function () {
    $task = p9Task('did_p9_idem', 'success');

    postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did'     => $task->did,
        'phase'   => 'done',
    ], p9Headers())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'success');
});

it('P9 · 乱序回报(downloading 晚于 installing 到达)也被拒,不静默吞掉', function () {
    $task = p9Task('did_p9_outoforder', 'installing');

    postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did'     => $task->did,
        'phase'   => 'downloading',
    ], p9Headers())
        ->assertStatus(409)
        ->assertJsonPath('code', 'TASK_TRANSITION_INVALID');

    expect($task->fresh()->status)->toBe('installing');
});

// ───────────── 2 · 全部出口统一 X1 信封 ─────────────

it('P9 · event 成功体是信封(success:true + data.status)', function () {
    $task = p9Task('did_p9_ok', 'dispatching');

    postJson(route('shop.api.agent.event'), [
        'task_no' => $task->task_no,
        'did'     => $task->did,
        'phase'   => 'done',
    ], p9Headers())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'success');
});

it('P9 · commands 是信封(data 为数组,含 success 键)', function () {
    $binding = p9Binding();

    $res = getJson(route('shop.api.agent.commands').'?did='.$binding->did, p9Headers())
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($res->json('data'))->toBeArray();
});

it('P9 · heartbeat 是信封', function () {
    $binding = p9Binding();

    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did], p9Headers())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.online', true);
});

it('P9 · register 是信封且维持 201', function () {
    postJson(route('shop.api.agent.register'), ['sn' => 'P9-'.uniqid()], p9Headers())
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data' => ['did', 'bind_status', 'online']]);
});

it('P9 · 失败体一律带 code(未注册设备 → 404 + DEVICE_NOT_REGISTERED)', function () {
    getJson(route('shop.api.agent.commands').'?did=did_p9_nope', p9Headers())
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'DEVICE_NOT_REGISTERED');
});

it('P9 · 任务不存在 → 404 + TASK_NOT_FOUND(不再返回裸 message)', function () {
    postJson(route('shop.api.agent.event'), [
        'task_no' => 'P9-not-exist',
        'did'     => 'did_p9_x',
        'phase'   => 'done',
    ], p9Headers())
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'TASK_NOT_FOUND');
});
