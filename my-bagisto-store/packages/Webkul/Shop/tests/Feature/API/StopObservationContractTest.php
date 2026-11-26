<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Services\Deploy\Contracts\StopObservation;
use Webkul\Shop\Services\Deploy\Contracts\StopObservationSourceForbiddenException;

use function Pest\Laravel\postJson;

/**
 * WBS A2：StopObservation 强类型契约。
 *
 * ⚠ 所有观察器均为 synthetic-test-only，仅验证平台侧契约，不代表真机 HIL。
 */

function stopObsSetup(): array
{
    $agentKey = 'test-agent-key-v2';
    config(['deploy_gateways.agent_key' => $agentKey]);

    $did = 'did_stop_'.uniqid();
    $execId = 'exec_stop_'.uniqid();

    DeviceBinding::create([
        'did'         => $did,
        'sn'          => 'SN_STOP_'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $task = DeployTask::create([
        'task_no'          => 'T_STOP_'.uniqid(),
        'command_seq'      => 1,
        'did'              => $did,
        'skill_id'         => 971,
        'operation'        => DeployTask::OP_EXECUTE,
        'execution_id'     => $execId,
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'status'           => 'installing',
        'delivered_at'     => now(),
    ]);

    SkillExecution::create([
        'execution_id'   => $execId,
        'deploy_task_id' => $task->id,
        'did'            => $did,
        'skill_id'       => 971,
        'status'         => SkillExecution::STATUS_RUNNING,
    ]);

    return [$task, $execId, $did, ['X-Agent-Key' => $agentKey]];
}

function stopObsPayload(DeployTask $task, string $did, string $execId, array $overrides = []): array
{
    return array_merge([
        'task_no'        => $task->task_no,
        'did'            => $did,
        'event_id'       => 'evt_stop_'.uniqid(),
        'event_seq'      => 1,
        'phase'          => 'done',
        'execution_id'   => $execId,
        'actual_started' => true,
        'actual_stopped' => true,
        'started_at'     => now()->subSeconds(2)->timestamp,
        'stopped_at'     => now()->timestamp,
        'evidence'       => ['observer' => 'synthetic-test-only'],
        'reported_at'    => now()->timestamp,
    ], $overrides);
}

function stopObsValid(string $execId, array $overrides = []): array
{
    return array_merge([
        'execution_id'        => $execId,
        'source_type'         => 'independent_watchdog',
        'sensor_id'           => 'synthetic:test-watchdog',
        'observed_at'         => now()->timestamp,
        'actual_started'      => true,
        'actual_stopped'      => true,
        'velocity'            => 0.0,
        'current'             => 0.0,
        'contact'             => true,
        'brake'               => true,
        'power'               => true,
        'quality'             => 'good',
        'raw_evidence_sha256' => str_repeat('b', 64),
        'collector_version'   => 'synthetic-test-only',
    ], $overrides);
}

it('A2 · 白名单内的 source_type 可构造，toArray 字段完整', function () {
    foreach (StopObservation::SOURCE_TYPES as $sourceType) {
        $observation = new StopObservation(
            executionId: 'exec_1',
            sourceType: $sourceType,
            sensorId: 'sensor_1',
            observedAt: 100,
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
        );

        expect($observation->toArray())->toHaveKeys([
            'execution_id', 'source_type', 'sensor_id', 'observed_at',
            'actual_started', 'actual_stopped', 'velocity', 'current',
            'contact', 'brake', 'power', 'quality', 'raw_evidence_sha256',
            'collector_version',
        ]);
    }
});

it('A2 · 8 类非物理停止来源一律拒绝', function () {
    foreach (StopObservation::FORBIDDEN_SOURCE_TYPES as $sourceType) {
        expect(fn () => new StopObservation(
            executionId: 'exec_1',
            sourceType: $sourceType,
            sensorId: 'sensor_1',
            observedAt: 100,
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
        ))->toThrow(StopObservationSourceForbiddenException::class);
    }
});

it('A2 · 质量/物理量不满足停止判据时 isAcceptable=false', function () {
    $base = [
        'executionId' => 'exec_1',
        'sourceType' => 'independent_watchdog',
        'sensorId' => 'sensor_1',
        'observedAt' => 100,
        'actualStarted' => true,
        'actualStopped' => true,
        'velocity' => 0.0,
        'current' => 0.0,
        'contact' => true,
        'brake' => true,
        'power' => true,
        'quality' => 'good',
        'rawEvidenceSha256' => str_repeat('a', 64),
        'collectorVersion' => 'synthetic-test-only',
    ];

    expect((new StopObservation(...$base))->isAcceptable())->toBeTrue()
        ->and((new StopObservation(...array_replace($base, ['quality' => 'bad'])))->isAcceptable())->toBeFalse()
        ->and((new StopObservation(...array_replace($base, ['velocity' => 10.0])))->isAcceptable())->toBeFalse()
        ->and((new StopObservation(...array_replace($base, ['current' => 99.0])))->isAcceptable())->toBeFalse()
        ->and((new StopObservation(...array_replace($base, ['brake' => false])))->isAcceptable())->toBeFalse();
});

it('A2 · 过期/未来时间戳判定', function () {
    $observation = new StopObservation(
        executionId: 'exec_1',
        sourceType: 'independent_watchdog',
        sensorId: 'sensor_1',
        observedAt: 1000,
        actualStarted: true,
        actualStopped: false,
        velocity: 1.0,
        current: 1.0,
        contact: null,
        brake: false,
        power: true,
        quality: 'good',
        rawEvidenceSha256: str_repeat('a', 64),
        collectorVersion: 'synthetic-test-only',
    );

    expect($observation->isFresh(1010))->toBeTrue()
        ->and($observation->isFresh(1100))->toBeFalse()
        ->and($observation->isFresh(900))->toBeFalse();
});

it('A2 · 终态缺少 StopObservation → 降级 UNKNOWN，绝不写 success', function () {
    [$task, $execId, $did, $headers] = stopObsSetup();

    $response = postJson(route('shop.api.agent.event'), stopObsPayload($task, $did, $execId, [
        'evidence' => ['observer' => 'synthetic-test-only'],
    ]), $headers);

    $response->assertOk()->assertJsonPath('data.status', 'timeout');

    expect($task->fresh()->status)->toBe('timeout')
        ->and(SkillExecution::where('execution_id', $execId)->value('status'))->toBe(SkillExecution::STATUS_UNKNOWN)
        ->and(SkillExecution::where('execution_id', $execId)->value('actual_stopped'))->toBeFalsy()
        ->and(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(1);
});

it('A2 · 禁止用 SDK 返回/云端 ACK/mock/SIL 冒充物理停止（422）', function () {
    foreach (['sdk_return', 'cloud_ack', 'mock', 'sil', 'bt_halt', 'script_assertion'] as $sourceType) {
        [$task, $execId, $did, $headers] = stopObsSetup();

        $response = postJson(route('shop.api.agent.event'), stopObsPayload($task, $did, $execId, [
            'stop_observation' => stopObsValid($execId, ['source_type' => $sourceType]),
        ]), $headers);

        $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        expect($task->fresh()->status)->toBe('installing')
            ->and(SkillExecution::where('execution_id', $execId)->value('status'))->toBe(SkillExecution::STATUS_RUNNING)
            ->and(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(0);
    }
});

it('A2 · 过期观察 → 降级 UNKNOWN', function () {
    [$task, $execId, $did, $headers] = stopObsSetup();

    $response = postJson(route('shop.api.agent.event'), stopObsPayload($task, $did, $execId, [
        'stop_observation' => stopObsValid($execId, ['observed_at' => now()->timestamp - 3600]),
    ]), $headers);

    $response->assertOk()->assertJsonPath('data.status', 'timeout');

    $execution = SkillExecution::where('execution_id', $execId)->firstOrFail();

    expect($task->fresh()->status)->toBe('timeout')
        ->and($execution->status)->toBe(SkillExecution::STATUS_UNKNOWN)
        ->and($execution->execution_evidence['stop_observation_downgrade'])->toBe('STALE_STOP_OBSERVATION');
});

it('A2 · 坏质量 / 物理量不合格 → 降级 UNKNOWN', function () {
    [$task, $execId, $did, $headers] = stopObsSetup();

    $response = postJson(route('shop.api.agent.event'), stopObsPayload($task, $did, $execId, [
        'stop_observation' => stopObsValid($execId, ['quality' => 'bad']),
    ]), $headers);

    $response->assertOk()->assertJsonPath('data.status', 'timeout');

    $execution = SkillExecution::where('execution_id', $execId)->firstOrFail();

    expect($task->fresh()->status)->toBe('timeout')
        ->and($execution->status)->toBe(SkillExecution::STATUS_UNKNOWN)
        ->and($execution->execution_evidence['stop_observation_downgrade'])->toBe('UNACCEPTABLE_STOP_OBSERVATION');
});

it('A2 · 有效独立观察 → SUCCEEDED', function () {
    [$task, $execId, $did, $headers] = stopObsSetup();

    $response = postJson(route('shop.api.agent.event'), stopObsPayload($task, $did, $execId, [
        'stop_observation' => stopObsValid($execId),
    ]), $headers);

    $response->assertOk()->assertJsonPath('data.status', 'success');

    $execution = SkillExecution::where('execution_id', $execId)->firstOrFail();

    expect($task->fresh()->status)->toBe('success')
        ->and($execution->status)->toBe(SkillExecution::STATUS_SUCCEEDED)
        ->and($execution->actual_stopped)->toBeTrue()
        ->and($execution->execution_evidence['stop_observation']['source_type'])->toBe('independent_watchdog');
});

it('A2 · 观察与事实的 actual_stopped 矛盾 → 422', function () {
    [$task, $execId, $did, $headers] = stopObsSetup();

    $response = postJson(route('shop.api.agent.event'), stopObsPayload($task, $did, $execId, [
        'actual_stopped' => false,
        'stopped_at' => null,
        'stop_observation' => stopObsValid($execId, ['actual_stopped' => true]),
    ]), $headers);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    expect($task->fresh()->status)->toBe('installing');
});
