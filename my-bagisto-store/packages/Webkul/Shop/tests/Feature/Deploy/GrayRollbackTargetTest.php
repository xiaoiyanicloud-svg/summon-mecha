<?php

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\GrayReleaseService;

/**
 * P5 · 灰度回滚的靶子可能是空的。
 *
 * 缺口：`GrayReleaseService::abort()` 原按 `install_record.status='updated'` 找"已回滚设备"，
 * 而 `updated` 只在"同 product 已有旧版已装记录"时才写（DeployTaskService::isUpgrade()）。
 * **首装**该技能的设备落的是 `installed` —— 永远进不了 updated 集合。
 * 于是对首装设备推坏版本后 abort()，靶子集合为空：坏版本原地留着、affected=0，
 * 而灰度却报告"已回滚"（这比报错更危险 —— 它看起来成功了）。
 *
 * 修复：靶子改从 **本次灰度的 deploy_task success** 反查 —— 谁成功装上/升级到这个版本，
 * 谁就得回滚。首装(installed)与升级(updated)因此都能被覆盖。
 */

// ==================== 造数据 ====================

function p5Binding(bool $online = true): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_p5_'.uniqid(),
        'sn'             => 'P5-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        'last_online_at' => $online ? now() : now()->subHours(3),
    ]);
}

function p5Version(int $productId, string $version = '1.0.0'): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => $version,
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

function p5ProductId(): int
{
    return 910000 + random_int(1, 9999);
}

/** 直接落一条 install 任务（绕开去重，用于精确控制"设备装的是哪个版本"）。 */
function p5InstallTask(DeviceBinding $binding, int $versionId, string $status = 'dispatching'): DeployTask
{
    return DeployTask::create([
        'task_no'          => testUniqueNo('DT'),
        'did'              => $binding->did,
        'channel'          => 'agent',
        'skill_id'         => p5ProductId(),
        'skill_version_id' => $versionId,
        'operation'        => DeployTask::OP_INSTALL,
        'status'           => $status,
        'created_by'       => 'test',
    ]);
}

// ==================== 用例 ====================

it('首装设备推坏版本 → abort → 回滚任务真发给该设备（旧实现 affected=0）', function () {
    $binding = p5Binding();
    $productId = p5ProductId();
    $badVersion = p5Version($productId, '2.0.0');

    $gray = app(GrayReleaseService::class);
    $release = $gray->start($badVersion->id, ['percent' => 5], 'P5-'.uniqid());

    $deploy = app(DeployTaskService::class);

    // 首装：该设备此前没装过同 product 的其它版本 → install_record 落 installed（不是 updated）
    $task = p5InstallTask($binding, $badVersion->id);
    $deploy->onEvent($task->task_no, 'done');

    // 前置事实：证明这确实是"旧实现找不到靶子"的现场
    expect(InstallRecord::query()
        ->where('did', $binding->did)
        ->where('skill_version_id', $badVersion->id)
        ->value('status'))->toBe('installed');

    $affected = $gray->abort($release->fresh(), '坏版本', 'manual');

    expect($affected)->toBe(1)
        ->and(DeployTask::query()
            ->where('did', $binding->did)
            ->where('operation', DeployTask::OP_ROLLBACK)
            ->where('skill_version_id', $badVersion->id)
            ->count())->toBe(1);
});

it('升级设备（旧版已装）也照样回滚', function () {
    $binding = p5Binding();
    $productId = p5ProductId();
    $old = p5Version($productId, '1.0.0');
    $bad = p5Version($productId, '2.0.0');

    $gray = app(GrayReleaseService::class);
    $release = $gray->start($bad->id, ['percent' => 5], 'P5-'.uniqid());

    $deploy = app(DeployTaskService::class);

    // 先装 1.0.0，再升到 2.0.0 → install_record 落 updated
    $deploy->onEvent(p5InstallTask($binding, $old->id)->task_no, 'done');
    $deploy->onEvent(p5InstallTask($binding, $bad->id)->task_no, 'done');

    expect(InstallRecord::query()
        ->where('did', $binding->did)
        ->where('skill_version_id', $bad->id)
        ->value('status'))->toBe('updated');

    expect($gray->abort($release->fresh(), '坏版本', 'manual'))->toBe(1)
        ->and(DeployTask::query()
            ->where('did', $binding->did)
            ->where('operation', DeployTask::OP_ROLLBACK)
            ->count())->toBe(1);
});

it('只回滚本次灰度成功的设备：装失败的设备不背锅', function () {
    $ok = p5Binding();
    $bad = p5Binding();
    $productId = p5ProductId();
    $version = p5Version($productId, '3.0.0');

    $gray = app(GrayReleaseService::class);
    $release = $gray->start($version->id, ['percent' => 100], 'P5-'.uniqid());

    $deploy = app(DeployTaskService::class);

    // 一台成功
    $deploy->onEvent(p5InstallTask($ok, $version->id)->task_no, 'done');
    // 一台失败（根本没装成这个版本，不该被回滚）
    // ⚠ 直接落终态而**不走 onEvent**：onEvent 会触发 evaluateGrayIfAny，
    //    50% 成功率会当场命中回滚阈值而提前 abort —— 那测的就不是"靶子筛选"了。
    p5InstallTask($bad, $version->id, 'failed');

    $affected = $gray->abort($release->fresh(), '坏版本', 'manual');

    expect($affected)->toBe(1)
        ->and(DeployTask::query()
            ->where('did', $ok->did)
            ->where('operation', DeployTask::OP_ROLLBACK)
            ->count())->toBe(1)
        ->and(DeployTask::query()
            ->where('did', $bad->did)
            ->where('operation', DeployTask::OP_ROLLBACK)
            ->count())->toBe(0);
});

it('同一设备多条成功记录只回滚一次（按 did 去重）', function () {
    $binding = p5Binding();
    $productId = p5ProductId();
    $version = p5Version($productId, '4.0.0');

    $gray = app(GrayReleaseService::class);
    $release = $gray->start($version->id, ['percent' => 100], 'P5-'.uniqid());

    $deploy = app(DeployTaskService::class);

    // 同一版本"重装"两次，两条 success
    $deploy->onEvent(p5InstallTask($binding, $version->id)->task_no, 'done');
    $deploy->onEvent(p5InstallTask($binding, $version->id)->task_no, 'done');

    $affected = $gray->abort($release->fresh(), '坏版本', 'manual');

    expect($affected)->toBe(1)
        ->and(DeployTask::query()
            ->where('did', $binding->did)
            ->where('operation', DeployTask::OP_ROLLBACK)
            ->count())->toBe(1);
});
