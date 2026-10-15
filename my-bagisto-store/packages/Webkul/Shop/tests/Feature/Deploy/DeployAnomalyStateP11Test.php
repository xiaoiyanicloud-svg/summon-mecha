<?php

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\GrayRelease;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\GrayReleaseService;

use function Pest\Laravel\get;

/**
 * P11 · 异常态被压平 —— dry install 报"成功"、`download_failed` 只存在于注释、
 *        离线/无包/未确权全无 error_code。
 *
 * 三处后果:
 *   1. **空跑被当成真安装**:设备的 `package_url` 为空时做 dry install 后仍报 `done`
 *      → 服务端落 `success` + 一条 `install_record(installed)`,**凭空造出安装事实**,
 *      并计入灰度成功率(把"没装上"判成健康)。现在约定 `PKG_MISSING` 为"空跑"标记。
 *   2. **`download_failed` 不存在于状态机**:类注释的状态图写了它,`TRANSITIONS` 与
 *      onEvent 的 map 都没有 → 设备报"下载失败"落成泛化的 `failed`,
 *      与"安装失败(不可重试)"在库里长得一模一样。
 *   3. 离线 / 未登记无码:事后只能靠中文 `error_msg` 反推。现在分别落
 *      `OFFLINE` / `NOT_BOUND`。
 */

function p11Version(int $productId, string $version = '1.0.0'): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => $version,
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

function p11Binding(bool $online): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_p11_'.uniqid(),
        'sn'             => 'P11-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        'last_online_at' => $online ? now() : now()->subHours(5),
    ]);
}

function p11SkillId(): int
{
    return 950000 + random_int(1, 9999);
}

function p11Task(DeviceBinding $binding, ?int $versionId, string $status = 'dispatching'): DeployTask
{
    return DeployTask::create([
        'task_no'          => 'P11'.now()->format('ymdHis').random_int(100, 999),
        'did'              => $binding->did,
        'channel'          => 'agent',
        'skill_id'         => p11SkillId(),
        'skill_version_id' => $versionId,
        'operation'        => DeployTask::OP_INSTALL,
        'status'           => $status,
        'created_by'       => 'test',
    ]);
}

// ───────────── 1 · 空跑(dry install)必须可区分 ─────────────

it('P11 · dry install 报 done + PKG_MISSING → success 被标成空跑,且不落安装事实', function () {
    $binding = p11Binding(true);
    $version = p11Version(p11SkillId());
    $task = p11Task($binding, $version->id);

    app(DeployTaskService::class)->onEvent(
        $task->task_no,
        'done',
        DeployTask::ERR_PKG_MISSING,
        '未分发技能包(dry install)',
    );

    $fresh = $task->fresh();

    expect($fresh->status)->toBe('success')
        ->and($fresh->error_code)->toBe(DeployTask::ERR_PKG_MISSING)
        ->and($fresh->isDryRun())->toBeTrue()
        // ⚠ 关键:凭空造一条 installed 会让灰度回滚靶子/事故影响面把这台设备算进去
        ->and(InstallRecord::query()->where('did', $binding->did)->exists())->toBeFalse();
});

it('P11 · 正常 done(无码)照常落 installed —— 闸门不误伤真实安装', function () {
    $binding = p11Binding(true);
    $version = p11Version(p11SkillId());
    $task = p11Task($binding, $version->id);

    app(DeployTaskService::class)->onEvent($task->task_no, 'done');

    $fresh = $task->fresh();

    expect($fresh->status)->toBe('success')
        ->and($fresh->isDryRun())->toBeFalse()
        ->and(InstallRecord::query()->where('did', $binding->did)->value('status'))->toBe('installed');
});

// ───────────── 2 · download_failed 进入状态机 ─────────────

it('P11 · downloading 报下载失败 → 进 download_failed 并带 DOWNLOAD_FAILED 码', function () {
    $binding = p11Binding(true);
    $version = p11Version(p11SkillId());
    $task = p11Task($binding, $version->id, 'downloading');

    app(DeployTaskService::class)->onEvent($task->task_no, 'download_failed');

    $fresh = $task->fresh();

    expect($fresh->status)->toBe('download_failed')
        ->and($fresh->error_code)->toBe(DeployTask::ERR_DOWNLOAD_FAILED);
});

it('P11 · download_failed 可重试(device 再报 installing → 继续走)', function () {
    $binding = p11Binding(true);
    $version = p11Version(p11SkillId());
    $task = p11Task($binding, $version->id, 'downloading');
    $deploy = app(DeployTaskService::class);

    $deploy->onEvent($task->task_no, 'download_failed');
    $deploy->onEvent($task->task_no, 'installing');
    $deploy->onEvent($task->task_no, 'done');

    expect($task->fresh()->status)->toBe('success');
});

it('P11 · 安装失败报 failed 仍是泛化 failed(与下载失败区分开)', function () {
    $binding = p11Binding(true);
    $version = p11Version(p11SkillId());
    $task = p11Task($binding, $version->id, 'installing');

    app(DeployTaskService::class)->onEvent($task->task_no, 'failed', null, '装到一半炸了');

    expect($task->fresh()->status)->toBe('failed')
        ->and($task->fresh()->error_code)->not->toBe(DeployTask::ERR_DOWNLOAD_FAILED);
});

// ───────────── 3 · 服务端判定的码:NOT_BOUND / OFFLINE ─────────────

it('P11 · 下发给从未登记的设备 → 直接 failed + NOT_BOUND(不再空等 24 小时)', function () {
    $task = app(DeployTaskService::class)->create(
        did: 'did_p11_never_registered_'.uniqid(),
        skillId: p11SkillId(),
        skillVersionId: null,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
        createdBy: 'test',
    );

    expect($task->status)->toBe('failed')
        ->and($task->error_code)->toBe(DeployTask::ERR_NOT_BOUND);
});

it('P11 · 下发给已登记但离线的设备 → pending_online + OFFLINE(仍保留等它上线)', function () {
    $binding = p11Binding(false);

    $task = app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: p11SkillId(),
        skillVersionId: null,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
        createdBy: 'test',
    );

    expect($task->status)->toBe('pending_online')
        ->and($task->error_code)->toBe(DeployTask::ERR_OFFLINE);
});

// ───────────── 4 · 空跑不污染灰度成功率 ─────────────

it('P11 · 灰度指标把空跑排除在外(既不算成功也不算失败)', function () {
    $binding = p11Binding(true);
    $version = p11Version(p11SkillId());
    $deploy = app(DeployTaskService::class);

    // 两条 dry install(空跑)+ 一条真失败
    $dryA = p11Task($binding, $version->id);
    $deploy->onEvent($dryA->task_no, 'done', DeployTask::ERR_PKG_MISSING, 'dry');

    $other = p11Binding(true);
    $dryB = p11Task($other, $version->id);
    $deploy->onEvent($dryB->task_no, 'done', DeployTask::ERR_PKG_MISSING, 'dry');

    $third = p11Binding(true);
    $bad = p11Task($third, $version->id);
    $deploy->onEvent($bad->task_no, 'failed', null, '真实失败');

    $release = GrayRelease::create([
        'skill_version_id' => $version->id,
        'strategy'         => ['percent' => 5],
        'batch_no'         => 'P11-'.uniqid(),
        'status'           => 'releasing',
        'metrics'          => ['installs' => 0, 'successes' => 0, 'failures' => 0],
    ]);

    app(GrayReleaseService::class)->evaluateFromRecords($release);

    $metrics = $release->fresh()->metrics;

    // 剩下那 1 条真失败 —— 成功率 0%,而不是被两条空跑抬成 66%
    expect($metrics['installs'])->toBe(1)
        ->and($metrics['successes'])->toBe(0)
        ->and($metrics['failures'])->toBe(1);
});

// ───────────── 5 · 参考 Agent 脚本如实报告 ─────────────

it('P11 · 生成的 agent.py 用常量替换了占位符,空跑带 PKG_MISSING', function () {
    $body = get(route('shop.agent.py'))->assertOk()->getContent();

    expect($body)->toContain(DeployTask::ERR_PKG_MISSING)
        ->and($body)->toContain(DeployTask::ERR_SIG_INVALID)
        // 占位符没被替换掉 = 脚本会把这些字面量直接报给服务端
        ->and($body)->not->toContain('__ERR_PKG_MISSING__')
        ->and($body)->not->toContain('__ERR_SIG_INVALID__');
});
