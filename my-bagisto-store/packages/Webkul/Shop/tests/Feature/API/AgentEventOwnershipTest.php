<?php

use Webkul\Shop\Models\DeployTask;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * P2 · Agent 回报接口的归属校验（共享密钥下的指令劫持）。
 *
 * 背景：Agent 三接口共用一把 `agent.key`。`event` 此前**连 did 都不收**，只按
 * task_no 定位任务 —— 任何持 key 的调用方都能替**别人的**任务推进状态机。
 *
 * 短期修法（per-device 证书是目标形态，计划 §4.22 F1 / 附二推迟）：
 *   `event` 请求体纳入 `did`，且**在推进状态机之前**校验「任务 did == 上报 did」，
 *   不匹配按 404 返回（不泄漏他人任务的存在）。
 *
 * ⚠ 诚实边界：`commands?did=<他人did>` 在**共享密钥**下无法与真身区分（缺少 per-device
 *   身份）——这一半要等证书形态落地。本文件锁的是当前能兑现的那一半（event 归属）。
 */

function agentKey(): string
{
    return 'pest-agent-key';
}

function agentTask(string $did, string $status = 'dispatching'): DeployTask
{
    return DeployTask::create([
        'task_no'    => 'DT'.now()->format('ymdHis').random_int(1000, 9999),
        'did'        => $did,
        'channel'    => 'agent',
        'skill_id'   => 900000 + random_int(1, 9999),
        'operation'  => DeployTask::OP_INSTALL,
        'status'     => $status,
        'created_by' => 'test',
    ]);
}

beforeEach(function () {
    config(['deploy_gateways.agent_key' => agentKey()]);
});

function agentHeaders(): array
{
    return ['X-Agent-Key' => agentKey()];
}

it('event：用 B 的 did 报 A 的任务 → 被拒（404），且 A 的状态未被推进', function () {
    $taskA = agentTask('did_owner_A', 'dispatching');
    agentTask('did_owner_B', 'dispatching');

    // B 拿着共享 key,用 A 的 task_no + 自己的 did → 企图替 A 推进
    postJson(route('shop.api.agent.event'), [
        'task_no' => $taskA->task_no,
        'did'     => 'did_owner_B',
        'phase'   => 'done',
    ], agentHeaders())->assertStatus(404);

    // 闸门在 onEvent 之前 —— A 的任务状态绝不能被动
    expect($taskA->fresh()->status)->toBe('dispatching');
});

it('event：did 与任务一致时正常推进', function () {
    $taskA = agentTask('did_owner_A', 'dispatching');

    postJson(route('shop.api.agent.event'), [
        'task_no' => $taskA->task_no,
        'did'     => 'did_owner_A',
        'phase'   => 'done',
    ], agentHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'success');

    expect($taskA->fresh()->status)->toBe('success');
});

it('event：缺 did 直接 422（契约要求必带 did）', function () {
    $taskA = agentTask('did_owner_A', 'dispatching');

    postJson(route('shop.api.agent.event'), [
        'task_no' => $taskA->task_no,
        'phase'   => 'done',
    ], agentHeaders())->assertStatus(422);
});

it('commands：未注册的 did 无法拉指令（当前可兑现的归属边界）', function () {
    getJson(route('shop.api.agent.commands').'?did=did_not_registered', agentHeaders())
        ->assertStatus(404);
});
