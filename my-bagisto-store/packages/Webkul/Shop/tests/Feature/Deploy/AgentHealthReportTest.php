<?php

use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\DeviceHealthService;

use function Pest\Laravel\postJson;

/**
 * A2 · 健康快照上报：字段白名单、越界拒绝、错误计数键闭合、覆盖语义。
 *
 * 这些用例的目标不是"能存进去"，而是回答三个生产问题：
 *   1. Agent 传了非法值，平台会不会把坏数据写进 meta？
 *   2. 错误码趋势会不会变成高基数标签（键不在 A3 闭合集）？
 *   3. 上一次的温度会不会被当成"现在仍然正常"保留下来？
 */

function a2HealthBinding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'         => 'did_a2_'.uniqid(),
        'sn'          => 'A2-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
        'bound_at'    => now(),
        'meta'        => ['capability_snapshot' => ['model_code' => 'mock']],
    ]);
}

function a2HealthHeaders(): array
{
    return ['X-Agent-Key' => 'pest-a2-key'];
}

beforeEach(function () {
    config(['deploy_gateways.agent_key' => 'pest-a2-key']);
});

it('A2-H-01 完整健康快照落库：数值规整、错误计数只留失败键', function () {
    $binding = a2HealthBinding();

    postJson(route('shop.api.agent.heartbeat'), [
        'did'      => $binding->did,
        'health'   => [
            'battery_pct'     => 87,
            'temperature_c'   => 41.56,
            'cpu_pct'         => 12.34,
            'memory_used_mb'  => 512,
            'memory_total_mb' => 1024,
            'disk_free_mb'    => 12000,
            'disk_total_mb'   => 32000,
            'uptime_seconds'  => 3600,
            'error_counters'  => ['command_signature_failed_total' => 3, 'outbox_flush_failed_total' => 0],
            'sampled_at'      => 1730000000,
        ],
    ], a2HealthHeaders())->assertOk();

    $health = app(DeviceHealthService::class)->snapshot($binding->fresh());

    expect($health)->not->toBeNull()
        ->and($health['battery_pct'])->toBe(87)
        ->and($health['temperature_c'])->toBe(41.6)
        ->and($health['cpu_pct'])->toBe(12.3)
        ->and($health['memory_used_mb'])->toBe(512)
        ->and($health['uptime_seconds'])->toBe(3600)
        ->and($health['sampled_at'])->toBe(1730000000)
        ->and($health['reported_at'])->toBeString()
        ->and($health['error_counters']['command_signature_failed_total'])->toBe(3)
        ->and($health['error_counters']['outbox_flush_failed_total'])->toBe(0);
});

it('A2-H-02 覆盖语义：本次没报的字段不保留旧值（避免"温度看起来还正常"）', function () {
    $binding = a2HealthBinding();

    postJson(route('shop.api.agent.heartbeat'), [
        'did'    => $binding->did,
        'health' => ['battery_pct' => 90, 'temperature_c' => 42.0],
    ], a2HealthHeaders())->assertOk();

    postJson(route('shop.api.agent.heartbeat'), [
        'did'    => $binding->did,
        'health' => ['battery_pct' => 88],
    ], a2HealthHeaders())->assertOk();

    $fresh = $binding->fresh();
    $health = app(DeviceHealthService::class)->snapshot($fresh);

    expect($health['battery_pct'])->toBe(88)
        ->and($health)->not->toHaveKey('temperature_c')
        // 与健康无关的 meta 必须保留（能力快照是另一根轴）
        ->and($fresh->meta['capability_snapshot']['model_code'] ?? null)->toBe('mock');
});

it('A2-H-03 越界电量 → 422 且不落库', function () {
    $binding = a2HealthBinding();

    postJson(route('shop.api.agent.heartbeat'), [
        'did'    => $binding->did,
        'health' => ['battery_pct' => 180],
    ], a2HealthHeaders())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    expect(app(DeviceHealthService::class)->snapshot($binding->fresh()))->toBeNull();
});

it('A2-H-04 错误计数键不在失败键集 → 422，且整批不落库', function () {
    $binding = a2HealthBinding();

    postJson(route('shop.api.agent.heartbeat'), [
        'did'    => $binding->did,
        'health' => ['battery_pct' => 80, 'error_counters' => ['device_xyz_events_total' => 1]],
    ], a2HealthHeaders())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    // 正常计数（如重启次数）不属于"错误码趋势"，同样拒绝
    postJson(route('shop.api.agent.heartbeat'), [
        'did'    => $binding->did,
        'health' => ['battery_pct' => 80, 'error_counters' => ['agent_restart_total' => 1]],
    ], a2HealthHeaders())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    expect(app(DeviceHealthService::class)->snapshot($binding->fresh()))->toBeNull();
});

it('A2-H-05 不带 health 的心跳不创建健康快照（未知 ≠ 0）', function () {
    $binding = a2HealthBinding();

    postJson(route('shop.api.agent.heartbeat'), [
        'did' => $binding->did,
    ], a2HealthHeaders())->assertOk();

    expect(app(DeviceHealthService::class)->snapshot($binding->fresh()))->toBeNull();
});
