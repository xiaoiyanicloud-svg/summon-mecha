<?php

use Illuminate\Support\Facades\Artisan;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;

/**
 * P6 · pending_online 无超时回收（三列是死列）。
 *
 * 缺口：下发任务离开"进行中"只有两条路 —— 设备回报、或被取消；而设备是随时会掉线的
 * 外部实体。服务端**没有任何兜底**，于是给长期离线的设备下发，任务永久停在
 * `pending_online`，用户界面永远"下发中"（既不成功也不失败，且没人知道）。
 *
 * 修复：新增 `ops:timeout-deploy-tasks` 命令并挂进 scheduler，扫描两类靶子置 `timeout`：
 *   · pending_online —— 等设备上线（小时级阈值）；
 *   · dispatching/downloading/installing —— 已下发但在途不回报（分钟级阈值）。
 */

function p6Binding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_p6_'.uniqid(),
        'sn'             => 'P6-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        'last_online_at' => now()->subHours(3), // 离线
    ]);
}

function p6Task(DeviceBinding $binding, string $status, ?string $createdAgo = null, ?string $deliveredAgo = null): DeployTask
{
    $task = DeployTask::create([
        'task_no'          => 'DT'.now()->format('ymdHis').random_int(1000, 9999),
        'did'              => $binding->did,
        'channel'          => 'agent',
        'skill_id'         => 920000 + random_int(1, 9999),
        'skill_version_id' => null,
        'operation'        => DeployTask::OP_INSTALL,
        'status'           => $status,
        'created_by'       => 'test',
    ]);

    // 用 DB 直改时间戳，避免 model 的 updated_at 自动刷新干扰"在途以 updated_at 判定"的意图
    $patch = [];
    if ($createdAgo !== null) {
        $patch['created_at'] = now()->sub($createdAgo);
    }
    if ($deliveredAgo !== null) {
        $patch['delivered_at'] = now()->sub($deliveredAgo);
    }
    if ($patch) {
        DeployTask::query()->whereKey($task->id)->update($patch);
    }

    return $task->fresh();
}

it('24 小时前的 pending_online → 命令转 timeout（用户界面不再永远下发中）', function () {
    $binding = p6Binding();
    $task = p6Task($binding, 'pending_online', createdAgo: '25 hours');

    Artisan::call('ops:timeout-deploy-tasks');

    expect($task->fresh()->status)->toBe('timeout')
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

it('在途任务（dispatching）超过阈值未回报 → 转 timeout', function () {
    $binding = p6Binding();
    $task = p6Task($binding, 'dispatching', deliveredAgo: '40 minutes');

    Artisan::call('ops:timeout-deploy-tasks');

    expect($task->fresh()->status)->toBe('timeout');
});

it('未到阈值的不动：刚建 1 小时的 pending_online / 5 分钟前的 dispatching 都留着', function () {
    $binding = p6Binding();
    $fresh = p6Task($binding, 'pending_online', createdAgo: '1 hour');
    $recent = p6Task($binding, 'dispatching', deliveredAgo: '5 minutes');

    Artisan::call('ops:timeout-deploy-tasks');

    expect($fresh->fresh()->status)->toBe('pending_online')
        ->and($recent->fresh()->status)->toBe('dispatching');
});

it('dry-run 只报告不写入', function () {
    $binding = p6Binding();
    $task = p6Task($binding, 'pending_online', createdAgo: '25 hours');

    Artisan::call('ops:timeout-deploy-tasks', ['--dry-run' => true]);

    expect($task->fresh()->status)->toBe('pending_online');
});

it('完成的（success）任务不被超时扫描误伤', function () {
    $binding = p6Binding();
    $done = p6Task($binding, 'success', createdAgo: '48 hours');

    Artisan::call('ops:timeout-deploy-tasks');

    expect($done->fresh()->status)->toBe('success');
});
