<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\DeployMetricCounter;
use Webkul\Shop\Services\Deploy\GaOperationalMetrics;

use function Pest\Laravel\postJson;

/**
 * A3 · 运行指标：闭合键集 + 原子累加 + 批次幂等 + 单一埋点。
 *
 * 这套用例的核心不是"计数器能加一"，而是三条**结构性**约束：
 *   1. 键集闭合 —— 写第 10 个键必须炸。基数有界靠的是代码约束，不是使用者自律。
 *   2. 累加是原子的 —— 并发下不丢计数（`ON DUPLICATE KEY UPDATE`，不是读改写）。
 *   3. 上报是按批次幂等的 —— Agent 重发不能把计数刷高。
 */

function a3Counter(): DeployMetricCounter
{
    return app(DeployMetricCounter::class);
}

function a3Key(): string
{
    return 'pest-a3-key';
}

function a3Headers(): array
{
    return ['X-Agent-Key' => a3Key()];
}

function a3Binding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'         => 'did_a3_'.uniqid(),
        'sn'          => 'A3-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
        'bound_at'    => now(),
    ]);
}

beforeEach(function () {
    config(['deploy_gateways.agent_key' => a3Key()]);
});

// ==================== ① 键集闭合（基数有界的实现） ====================

it('A3-01 指标键恰好 12 个，且设备侧 8 个 / 平台侧 4 个不重不漏', function () {
    $all = DeployMetricCounter::METRIC_KEYS;

    expect($all)->toHaveCount(12)
        ->and(array_unique($all))->toHaveCount(12)
        ->and(DeployMetricCounter::AGENT_KEYS)->toHaveCount(8)
        ->and(DeployMetricCounter::PLATFORM_KEYS)->toHaveCount(4)
        ->and(DeployMetricCounter::GAUGE_KEYS)->toHaveCount(1)
        ->and(array_values(array_merge(DeployMetricCounter::AGENT_KEYS, DeployMetricCounter::PLATFORM_KEYS)))
        ->toBe($all);
});

it('A3-02 写闭合键集之外的键 → InvalidArgumentException（不允许动态键）', function () {
    $thrown = null;

    try {
        a3Counter()->increment('device_'.uniqid().'_events_total');
    } catch (InvalidArgumentException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('未知指标键');
});

it('A3-03 增量必须为正（计数器只增不减）', function () {
    expect(fn () => a3Counter()->increment('idempotency_hit_total', 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => a3Counter()->increment('idempotency_hit_total', -5))
        ->toThrow(InvalidArgumentException::class);
});

// ==================== ② 累加与读取口径 ====================

it('A3-04 累加正确；所有键读出整数（0 表示确实是 0，null 表示没埋点）', function () {
    $counter = a3Counter();

    // ⚠ 计数器是**全局累加值**，不是本用例的私有状态：
    //   `agent/tests/test_live_http_integration.py` 会拉起真实 PHP 服务并真实落库，
    //   历史运行可能已经给某些键留下基线。所以一律按**增量**断言，绝不假设从 0 开始。
    $before = $counter->total('fact_auth_rejected_total');

    $counter->increment('fact_auth_rejected_total');
    $counter->increment('fact_auth_rejected_total', 4);

    $all = $counter->counters();

    expect($counter->total('fact_auth_rejected_total'))->toBe($before + 5)
        ->and(array_keys($all))->toBe(DeployMetricCounter::METRIC_KEYS)
        ->and(collect($all)->every(fn ($v) => is_int($v)))->toBeTrue();
});

// ==================== ③ 设备增量批次幂等 ====================

it('A3-05 同一 (did,batch_id) 只生效一次：重复上报不刷高计数', function () {
    $counter = a3Counter();
    $did = 'did_a3_batch_'.uniqid();
    $batch = 'abatch_'.substr(uniqid(), -12);

    $first = $counter->applyAgentBatch($did, $batch, ['agent_restart_total' => 2, 'staging_orphan_total' => 3]);
    $second = $counter->applyAgentBatch($did, $batch, ['agent_restart_total' => 2, 'staging_orphan_total' => 3]);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($counter->total('agent_restart_total'))->toBe(2)
        ->and($counter->total('staging_orphan_total'))->toBe(3);
});

it('A3-06 设备端不得上报平台侧键；负增量一律拒绝', function () {
    $counter = a3Counter();
    $did = 'did_a3_guard_'.uniqid();

    expect(fn () => $counter->applyAgentBatch($did, 'abatch_'.uniqid(), ['idempotency_hit_total' => 1]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $counter->applyAgentBatch($did, 'abatch_'.uniqid(), ['agent_restart_total' => -1]))
        ->toThrow(InvalidArgumentException::class);
});

// ==================== ④ HTTP 通道：心跳上报 ====================

it('A3-07 心跳带 metrics 增量 → 平台计数生效', function () {
    $binding = a3Binding();
    $batch = 'abatch_'.substr(uniqid(), -12);

    postJson(route('shop.api.agent.heartbeat'), [
        'did'           => $binding->did,
        'agent_version' => '0.1.0',
        'metrics'       => [
            'batch_id' => $batch,
            'counters' => ['agent_restart_total' => 1, 'outbox_flush_failed_total' => 2],
        ],
    ], a3Headers())->assertOk();

    expect(a3Counter()->total('agent_restart_total'))->toBe(1)
        ->and(a3Counter()->total('outbox_flush_failed_total'))->toBe(2);

    // 同一批次再报一次：仍是 200（对 Agent 而言"报过了"就算成功），但计数不涨
    postJson(route('shop.api.agent.heartbeat'), [
        'did'           => $binding->did,
        'agent_version' => '0.1.0',
        'metrics'       => [
            'batch_id' => $batch,
            'counters' => ['agent_restart_total' => 1, 'outbox_flush_failed_total' => 2],
        ],
    ], a3Headers())->assertOk();

    expect(a3Counter()->total('agent_restart_total'))->toBe(1)
        ->and(a3Counter()->total('outbox_flush_failed_total'))->toBe(2);
});

it('A3-08 心跳上报未注册的键 → 422（让 Agent 停止重试并丢弃该批）', function () {
    $binding = a3Binding();

    postJson(route('shop.api.agent.heartbeat'), [
        'did'           => $binding->did,
        'agent_version' => '0.1.0',
        'metrics'       => [
            'batch_id' => 'abatch_'.substr(uniqid(), -12),
            'counters' => ['some_dynamic_label_total' => 1],
        ],
    ], a3Headers())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

// ==================== ⑤ 平台侧单一埋点 ====================

it('A3-09 重复事件命中 Inbox 幂等 → idempotency_hit_total 每次 +1', function () {
    $binding = a3Binding();
    $task = DeployTask::create([
        'task_no'    => testUniqueNo('A3'),
        'did'        => $binding->did,
        'channel'    => 'agent',
        'skill_id'   => 900123,
        'operation'  => DeployTask::OP_INSTALL,
        'status'     => 'success',
        'created_by' => 'test',
    ]);

    $eventId = 'evt_a3_'.substr(uniqid(), -10);
    $payload = [
        'task_no'  => $task->task_no,
        'did'      => $binding->did,
        'event_id' => $eventId,
        'phase'    => 'done',
    ];

    $before = a3Counter()->total('idempotency_hit_total');

    postJson(route('shop.api.agent.event'), $payload, a3Headers())->assertOk();
    expect(a3Counter()->total('idempotency_hit_total'))->toBe($before);

    postJson(route('shop.api.agent.event'), $payload, a3Headers())->assertOk();
    expect(a3Counter()->total('idempotency_hit_total'))->toBe($before + 1);

    postJson(route('shop.api.agent.event'), $payload, a3Headers())->assertOk();
    expect(a3Counter()->total('idempotency_hit_total'))->toBe($before + 2);
});

it('A3-10 事实验签被拒 → fact_auth_rejected_total +1，且请求本身仍被拒', function () {
    $binding = a3Binding();

    $task = DeployTask::create([
        'task_no'    => testUniqueNo('A3F'),
        'did'        => $binding->did,
        'channel'    => 'agent',
        'skill_id'   => 900124,
        'operation'  => DeployTask::OP_INSTALL,
        'status'     => 'accepted',
        'created_by' => 'test',
    ]);

    $before = a3Counter()->total('fact_auth_rejected_total');

    // 伪造一个与正文不符的 fact_envelope：验签必然失败
    postJson(route('shop.api.agent.event'), [
        'task_no'  => $task->task_no,
        'did'      => $binding->did,
        'event_id' => 'evt_a3_forged_'.substr(uniqid(), -8),
        'phase'    => 'accepted',
        'fact_envelope' => [
            'protocol'  => 'agent-fact/v3',
            'key_id'    => str_repeat('0', 64),
            'signature' => base64_encode('not-a-signature'),
        ],
    ], a3Headers())
        // 未知 key_id 走 401（凭据不认），签名不符走 422；两种都必须在拒绝处计数
        ->assertStatus(401);

    expect(a3Counter()->total('fact_auth_rejected_total'))->toBe($before + 1);
});

// ==================== ⑥ 快照口径 ====================

it('A3-11 快照里 12 个计数器全部是整数，键数恒为 12；label 契约有界', function () {
    $before = a3Counter()->total('reconcile_drift_total');
    a3Counter()->increment('reconcile_drift_total', 2);

    $snapshot = app(GaOperationalMetrics::class)->snapshot();

    foreach (DeployMetricCounter::METRIC_KEYS as $key) {
        expect($snapshot)->toHaveKey($key)
            ->and($snapshot[$key])->toBeInt();
    }

    expect($snapshot['metric_key_total'])->toBe(12)
        ->and($snapshot['metric_keys'])->toBe(DeployMetricCounter::METRIC_KEYS)
        ->and($snapshot['gauge_key_total'])->toBe(1)
        ->and($snapshot['reconcile_drift_total'])->toBe($before + 2)
        // 12 个计数器必须全部标为 instrumented
        ->and(collect(DeployMetricCounter::METRIC_KEYS)
            ->every(fn ($key) => str_starts_with((string) $snapshot['metric_status'][$key], 'instrumented')))->toBeTrue()
        // 唯一允许留 null 的是"确实没有生产者"的那一个，且必须写明原因
        ->and($snapshot['package_signature_failed_total'])->toBeNull()
        ->and(array_keys(array_filter(
            $snapshot['metric_status'],
            fn ($v) => str_contains((string) $v, 'not_instrumented')
        )))->toBe(['package_signature_failed_total'])
        // A3.1：允许的 label 只有 5 个；无界维度明确列在拒绝列表
        ->and($snapshot['label_contract']['allowed'])->toBe(['did', 'model_code', 'operation', 'status', 'error_code'])
        ->and($snapshot['label_contract']['rejected_unbounded'])->toContain('event_id', 'task_id', 'skill_id')
        ->and($snapshot['label_contract']['cardinality']['operation'])->toBeLessThanOrEqual(count(DeployTask::OPERATIONS));
});

// ==================== ⑦ 重启告警判据 ====================

it('A3-12 重启告警按"累计启动次数 > 上报设备数"判定：首次启动不算重启', function () {
    $counter = a3Counter();
    $did = 'did_a3_restart_'.uniqid();

    $before = app(GaOperationalMetrics::class)->snapshot();
    $baselineDiff = $before['agent_restart_total'] - $before['agent_reporting_device_total'];

    // ① 一台新设备第一次启动：启动次数与设备数同时 +1，"多出来的启动"不变
    $counter->applyAgentBatch($did, 'abatch_'.uniqid(), ['agent_restart_total' => 1]);
    $first = app(GaOperationalMetrics::class)->snapshot();

    expect($first['agent_reporting_device_total'])->toBe($before['agent_reporting_device_total'] + 1)
        ->and($first['agent_restart_total'])->toBe($before['agent_restart_total'] + 1)
        ->and($first['agent_restart_total'] - $first['agent_reporting_device_total'])->toBe($baselineDiff);

    // 基线平衡（每台设备各启动一次）时，首启绝不能报"有重启"
    if ($baselineDiff === 0) {
        expect($first['alerts']['agent_restart_observed'])->toBeFalse();
    }

    // ② 同一台设备再启动一次：设备数不涨，启动次数 +1 —— 这才算发生过重启
    $counter->applyAgentBatch($did, 'abatch_'.uniqid(), ['agent_restart_total' => 1]);
    $second = app(GaOperationalMetrics::class)->snapshot();

    expect($second['agent_reporting_device_total'])->toBe($before['agent_reporting_device_total'] + 1)
        ->and($second['agent_restart_total'])->toBe($before['agent_restart_total'] + 2)
        ->and($second['agent_restart_total'] - $second['agent_reporting_device_total'])->toBe($baselineDiff + 1)
        // 基线差值非负时（正常情况下必成立），第二次启动必然让累计启动数超过设备数
        ->and($second['alerts']['agent_restart_observed'])->toBeTrue();
});

// ==================== ⑧ A3.1 / A3.2 字面项补齐 ====================

it('A3-13 设备 gauge 是覆盖语义：同一设备第二次上报覆盖第一次', function () {
    $binding = a3Binding();

    foreach ([120, 45] as $age) {
        postJson(route('shop.api.agent.heartbeat'), [
            'did'           => $binding->did,
            'agent_version' => '0.1.0',
            'metrics'       => ['gauges' => ['outbox_pending_age_seconds' => $age]],
        ], a3Headers())->assertOk();
    }

    $stored = DB::table('deploy_agent_metric_gauges')
        ->where('did', $binding->did)
        ->where('metric_key', 'outbox_pending_age_seconds')
        ->value('metric_value');

    expect((int) $stored)->toBe(45, 'gauge 必须覆盖而不是累加（120 + 45 ≠ 45）');
});

it('A3-14 心跳上报未注册的 gauge 键 → 422（与未知计数键同等处理）', function () {
    $binding = a3Binding();

    postJson(route('shop.api.agent.heartbeat'), [
        'did'           => $binding->did,
        'agent_version' => '0.1.0',
        'metrics'       => ['gauges' => ['some_dynamic_gauge' => 1]],
    ], a3Headers())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('A3-15 同 event_id 异载荷（409 冲突）→ event_conflict_total +1，且不覆盖原事件', function () {
    $binding = a3Binding();
    $task = DeployTask::create([
        'task_no'    => testUniqueNo('A3C'),
        'did'        => $binding->did,
        'channel'    => 'agent',
        'skill_id'   => 900125,
        'operation'  => DeployTask::OP_INSTALL,
        'status'     => 'success',
        'created_by' => 'test',
    ]);

    $eventId = 'evt_a3c_'.substr(uniqid(), -10);
    $base = [
        'task_no'  => $task->task_no,
        'did'      => $binding->did,
        'event_id' => $eventId,
        'phase'    => 'done',
    ];

    $before = a3Counter()->total('event_conflict_total');

    postJson(route('shop.api.agent.event'), $base, a3Headers())->assertOk();

    postJson(route('shop.api.agent.event'), array_merge($base, ['phase' => 'failed']), a3Headers())
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONFLICT');

    expect(a3Counter()->total('event_conflict_total'))->toBe($before + 1);
});
