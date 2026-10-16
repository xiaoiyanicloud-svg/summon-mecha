<?php

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\DeployTaskService;

use function Pest\Laravel\getJson;

/**
 * P7 · 状态流转是 check-then-act；同设备并发双任务无串行化。
 *
 * 缺口一(流转):`transition()` 原为 读 status → 校验 → 赋值 → save()。两个并发回报
 *   会双双读到同一个 from、双双通过校验、双双写库 —— 一次流转凭空发生两次
 *   (例如 success 之后又被另一个请求按旧状态推成 failed)。改为**条件更新**,
 *   命中 0 行即视为本次流转未发生。
 *
 * 缺口二(串行化):`commands()` 一次返回该 did 的**全部**待执行任务,设备可能并行起多个
 *   安装 —— 而同一台物理机的技能装卸本应串行。改为每设备每轮**限 1 条**。
 */

function p7Binding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_p7_'.uniqid(),
        'sn'             => 'P7-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        'last_online_at' => now(),
    ]);
}

function p7Task(DeviceBinding $binding, string $status): DeployTask
{
    return DeployTask::create([
        'task_no'          => testUniqueNo('DT'),
        'did'              => $binding->did,
        'channel'          => 'agent',
        'skill_id'         => 930000 + random_int(1, 9999),
        'skill_version_id' => null,
        'operation'        => DeployTask::OP_INSTALL,
        'status'           => $status,
        'created_by'       => 'test',
    ]);
}

beforeEach(function () {
    config(['deploy_gateways.agent_key' => 'pest-p7-key']);
});

// ==================== 1 · 条件更新（check-then-act 竞态） ====================

it('并发竞态：用过期状态去流转命中 0 行 → 返回 false 且不改库', function () {
    $binding = p7Binding();
    $task = p7Task($binding, 'pending');

    // 手里这份是"旧快照"（status=pending）
    $stale = DeployTask::find($task->id);

    // 模拟另一个请求抢先把它推到了 dispatching
    DeployTask::query()->whereKey($task->id)->update(['status' => 'dispatching']);

    // 旧快照仍以为自己是 pending，尝试 pending → dispatching
    $ok = app(DeployTaskService::class)->transition($stale, 'dispatching');

    expect($ok)->toBeFalse()
        ->and($stale->fresh()->status)->toBe('dispatching'); // 没有被后写覆盖
});

it('正常流转仍然生效，且内存态与库同步', function () {
    $binding = p7Binding();
    $task = p7Task($binding, 'dispatching');

    $ok = app(DeployTaskService::class)->transition($task, 'success');

    expect($ok)->toBeTrue()
        ->and($task->status)->toBe('success')            // 内存态已同步
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->fresh()->status)->toBe('success');  // 库已落
});

it('非法流转返回 false（pending 不能直接到 success）', function () {
    $binding = p7Binding();
    $task = p7Task($binding, 'pending');

    $ok = app(DeployTaskService::class)->transition($task, 'success');

    expect($ok)->toBeFalse()
        ->and($task->fresh()->status)->toBe('pending');
});

it('条件更新不丢调用方先设的字段（error_code/error_msg 随流转一起落库）', function () {
    $binding = p7Binding();
    $task = p7Task($binding, 'dispatching');

    // onEvent() 的真实用法：先设 error 字段，再 transition
    $task->error_code = 'SIG_INVALID';
    $task->error_msg = '签名校验失败';

    app(DeployTaskService::class)->transition($task, 'failed');

    expect($task->fresh()->status)->toBe('failed')
        ->and($task->fresh()->error_code)->toBe('SIG_INVALID')
        ->and($task->fresh()->error_msg)->toBe('签名校验失败');
});

// ==================== 2 · 每设备每轮限 1 条 ====================

it('每设备每轮只发一条指令（设备并行双任务 → 串行化）', function () {
    $binding = p7Binding();
    p7Task($binding, 'pending_online');
    p7Task($binding, 'pending_online');
    p7Task($binding, 'pending_online');

    $commands = getJson(route('shop.api.agent.commands').'?did='.$binding->did, ['X-Agent-Key' => 'pest-p7-key'])
        ->assertOk()
        ->json('data');

    expect($commands)->toHaveCount(1);
});
