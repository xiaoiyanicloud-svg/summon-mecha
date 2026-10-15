<?php

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\GrayRelease;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\GrayReleaseService;

use function Pest\Laravel\postJson;

/**
 * B-11 · 灰度缺「崩溃率」维度。
 *
 * 缺口:`GrayReleaseService::recordMetric()` 只算 installs/successes/failures → success_rate,
 *   而计划 §4.10 写的是「崩溃率 > 2% 回滚」——**崩溃率根本没有对应的维度**,那条规则无处生效。
 *
 * 关键区分:「装上了」与「装上之后不崩」是两件事。设备侧安装可以 100% success,
 *   技能在真机上一跑就崩 —— 只看 success_rate 会把这种批次判成"健康",而它恰恰最该回滚。
 *   故崩溃率是**与成功率正交**的独立回滚判据(本文件前四例锁的就是这点)。
 */

// ==================== 造数据 ====================

function b11Version(int $productId): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

function b11Binding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_b11_'.uniqid(),
        'sn'             => 'B11-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        'last_online_at' => now(),
    ]);
}

function b11Task(DeviceBinding $binding, int $versionId, string $status, ?string $errorCode = null): DeployTask
{
    return DeployTask::create([
        'task_no'          => 'B11'.now()->format('ymdHis').random_int(100000, 999999),
        'did'              => $binding->did,
        'channel'          => 'agent',
        'skill_id'         => 920000 + random_int(1, 9999),
        'skill_version_id' => $versionId,
        'operation'        => DeployTask::OP_INSTALL,
        'status'           => $status,
        'error_code'       => $errorCode,
        'created_by'       => 'test',
    ]);
}

function b11Release(int $versionId, string $status = 'releasing'): GrayRelease
{
    return GrayRelease::create([
        'skill_version_id' => $versionId,
        'strategy'         => ['percent' => 5],
        'batch_no'         => 'B11-'.uniqid(),
        'status'           => $status,
        // 刻意不给 crashes/crash_rate —— 模拟"旧的初始化结构"(start() 在 B-11 前就是这个形状)
        'metrics'          => ['installs' => 0, 'successes' => 0, 'failures' => 0],
    ]);
}

// ==================== 1 · 崩溃率是独立的回滚判据 ====================

it('B-11 · 成功率 100% 但崩溃率 > 2% → 仍回滚(崩溃维度真的在起作用)', function () {
    $release = b11Release(b11Version(920000 + random_int(1, 999))->id);

    // 100 台全装成功、0 失败 —— 单看 success_rate 是满分;但其中 5 台装上后崩了
    $result = app(GrayReleaseService::class)->recordMetric($release->id, 100, 100, 0, 5);

    $fresh = $release->fresh();

    expect($result['action'])->toBe('rollback')
        ->and($result['reason'])->toContain('崩溃率')
        ->and($fresh->metrics['crash_rate'])->toEqual(5.0)
        ->and($fresh->metrics['success_rate'])->toEqual(100.0)  // 成功率确实没触发,是崩溃率触发的
        ->and($fresh->status)->toBe('aborted');
});

it('B-11 · 崩溃率恰为阈值(2.0)不回滚 —— 判据是严格大于', function () {
    $release = b11Release(b11Version(921000 + random_int(1, 999))->id);

    $result = app(GrayReleaseService::class)->recordMetric($release->id, 100, 100, 0, 2);

    expect($result['action'])->toBe('continue')
        ->and($release->fresh()->status)->toBe('releasing');
});

it('B-11 · 不传崩溃数(旧调用方)行为不变:crash_rate=0,不误触发回滚', function () {
    $release = b11Release(b11Version(922000 + random_int(1, 999))->id);

    $result = app(GrayReleaseService::class)->recordMetric($release->id, 10, 10, 0);

    expect($result['action'])->toBe('continue')
        ->and($release->fresh()->metrics['crash_rate'])->toEqual(0.0);
});

it('B-11 · installs=0 时 crash_rate 为 null,不得被当成 0 之外的判据', function () {
    $release = b11Release(b11Version(923000 + random_int(1, 999))->id);

    $result = app(GrayReleaseService::class)->recordMetric($release->id, 0, 0, 0, 0);

    expect($result['action'])->toBe('continue')
        ->and($release->fresh()->metrics['crash_rate'])->toBeNull();
});

// ==================== 2 · 自驱路径从设备回报取崩溃数 ====================

it('B-11 · evaluateFromRecords 从 error_code=CRASH 反查崩溃数(自驱路径有真实来源)', function () {
    $version = b11Version(924000 + random_int(1, 999));
    $binding = b11Binding();

    // 10 条真装成
    for ($i = 0; $i < 10; $i++) {
        b11Task($binding, $version->id, 'success');
    }
    // 1 条:装成之后崩(error_code=CRASH,status=failed)
    b11Task($binding, $version->id, 'failed', DeployTask::ERR_CRASH);

    $release = b11Release($version->id);

    $result = app(GrayReleaseService::class)->evaluateFromRecords($release);
    $metrics = $release->fresh()->metrics;

    expect($metrics['installs'])->toBe(11)
        ->and($metrics['successes'])->toBe(10)
        ->and($metrics['crashes'])->toBe(1)
        ->and($metrics['crash_rate'])->toEqual(round(1 / 11 * 100, 2))
        // 成功率 90.9% 不低(>80),回滚只能是崩溃率(9.09% > 2%)触发的
        ->and($result['action'])->toBe('rollback')
        ->and($result['reason'])->toContain('崩溃率');
});

// ==================== 3 · 接口层:崩溃数的参数校验 ====================

function b11Headers(): array
{
    return ['X-Internal-Key' => 'b11-internal-key'];
}

beforeEach(function () {
    config(['skill_delivery.upload_key' => 'b11-internal-key']);
});

it('B-11 · 崩溃数 > 安装数被拒 422(崩过的设备数不可能超过下发的设备数)', function () {
    $release = b11Release(b11Version(925000 + random_int(1, 999))->id);

    postJson(route('shop.api.gray-releases.metrics', $release->id), [
        'installs'  => 10,
        'successes' => 10,
        'failures'  => 0,
        'crashes'   => 11,
    ], b11Headers())->assertStatus(422);

    // 脏计数未落库
    expect($release->fresh()->metrics['crashes'] ?? null)->toBeNull();
});

it('B-11 · 带合法崩溃数上报 → 200,crash_rate 落库', function () {
    $release = b11Release(b11Version(926000 + random_int(1, 999))->id);

    postJson(route('shop.api.gray-releases.metrics', $release->id), [
        'installs'  => 100,
        'successes' => 100,
        'failures'  => 0,
        'crashes'   => 3,
    ], b11Headers())->assertOk();

    $fresh = $release->fresh();

    expect($fresh->metrics['crashes'])->toBe(3)
        ->and($fresh->metrics['crash_rate'])->toEqual(3.0)
        ->and($fresh->status)->toBe('aborted'); // 3% > 2% → 自动回滚
});
