<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeployInboxEvent;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\DeployInboxService;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\Contracts\AgentTaskEnvelopeV2;
use Webkul\Shop\Services\Deploy\Contracts\ExecutionFact;
use Webkul\Shop\Services\Deploy\Contracts\InboxEvent;
use Webkul\Shop\Services\Deploy\Contracts\OutboxLease;
use Webkul\Shop\Services\Deploy\Contracts\ReconcileReport;
use Webkul\Shop\Services\SkillCompile\BuildCommand;
use Webkul\Shop\Services\SkillCompile\GaSupportMatrixService;
use Webkul\Shop\Services\SkillCompile\TargetReleaseEvent;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

it('P0: 注册五机型 Profile，A2 被废止且所有 GA 目标保持 BLOCKED', function () {
    $service = app(TargetProfileService::class);
    expect(array_keys($service->listProfiles()))->toContain(
        'unitree_g1', 'unitree_r1', 'agibot_x2', 'agibot_a3', 'engineai_t800'
    );
    expect($service->getProfile('agibot_a2'))->toBeNull();

    $matrix = app(GaSupportMatrixService::class)->build();
    expect($matrix['models'])->toHaveCount(5);
    foreach ($matrix['models'] as $row) {
        expect($row['compatibility_level'])->toBe('L2');
        expect($row['hardware_verification_status'])->toBe('HARDWARE_UNVERIFIED');
        expect($row['production_ready'])->toBe('BLOCKED');
        if ($row['model_code'] !== 'unitree_g1') {
            expect($row['blocked_reasons'])->toContain('VENDOR_LIMITS_UNRESOLVED');
        }
    }
});

it('P0: 未完成 HIL 前五机型不得宣称 E-Stop 已支持', function () {
    $service = app(\Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService::class);
    foreach ($service::GA_TARGET_MODELS as $modelCode) {
        $profile = $service->getProfile($modelCode);
        expect($profile)->not->toBeNull()
            ->and($profile['safety_envelope']['estop_supported'])->toBeNull()
            ->and($profile['safety_envelope']['estop_evidence_status'])->toBe('HARDWARE_UNVERIFIED')
            ->and($profile['safe_stop_procedure']['method'])->toBeNull()
            ->and($profile['safe_stop_procedure']['status'])->toBe('HARDWARE_UNVERIFIED');
    }
});
it('P0: 无当前可重放 SIL 时不得把历史工件标成 SIL PASS', function () {
    $service = app(\Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService::class);
    foreach ($service::GA_TARGET_MODELS as $modelCode) {
        $profile = $service->getProfile($modelCode);
        expect($profile['verification_status']['sil'])->not->toBe('PASS');
        expect($profile['verification_status']['hil'])->toBe('HARDWARE_UNVERIFIED');
    }
});
it('P0: 支持矩阵 API 返回真实五机型能力与阻塞原因', function () {
    $response = $this->getJson('/api/robot-support-matrix');
    $response->assertOk()->assertJsonPath('success', true);
    expect($response->json('data.models'))->toHaveCount(5);
    expect(collect($response->json('data.models'))->pluck('model_code')->all())
        ->toBe(['unitree_g1', 'unitree_r1', 'agibot_x2', 'agibot_a3', 'engineai_t800']);
    expect($response->json('data.default_enabled'))->toBeFalse();
});

it('P0: BuildCommand 幂等键按 seller 作用域隔离', function () {
    $command = new BuildCommand(
        skillVersionId: 10,
        sourceAssetId: 20,
        sourceDigest: 'sha256:'.str_repeat('a', 64),
        targetModelCode: 'unitree_g1',
        targetProfileDigest: 'sha256:'.str_repeat('b', 64),
        compilerVersion: 'zhaohuan.portable-compiler/v1',
        idempotencyKey: 'same-key',
        requestedBy: 'qa',
        sellerId: 7,
    );

    expect($command->scopedIdempotencyKey())->toBe('seller:7:build:same-key');
});

it('P0: AgentTaskEnvelopeV2 将机型、Profile、nonce 与 key_id 放入签名域', function () {
    $envelope = new AgentTaskEnvelopeV2(
        taskId: 'T-1',
        commandSeq: 1,
        did: 'did_1',
        operation: 7,
        modelCode: 'agibot_x2',
        packageSha256: str_repeat('c', 64),
        targetProfileDigest: 'sha256:'.str_repeat('d', 64),
        nonce: 'nonce-1',
        issuedAt: 100,
        expiresAt: 200,
        payload: ['execution_id' => 'exec_1'],
        signature: 'signed',
        keyId: 'key-1',
    );

    $signed = $envelope->signedFields();
    expect($signed['protocol'])->toBe('agent-task/v2');
    expect($signed['model_code'])->toBe('agibot_x2');
    expect($signed['nonce'])->toBe('nonce-1');
    expect($envelope->toArray()['key_id'])->toBe('key-1');
});

it('P0: ExecutionFact 拒绝把 CANCEL_REQUESTED 当作物理停止，并要求成功有停止证据', function () {
    $base = [
        'executionId' => 'exec_1',
        'actualStarted' => true,
        'actualStopped' => false,
        'startedAt' => 1,
        'stoppedAt' => null,
        'errorCode' => null,
        'evidence' => ['observer' => 'adapter'],
        'reportedAt' => 2,
    ];

    $cancelRequested = ['observedStatus' => 'CANCEL_REQUESTED'] + $base;
    $falseStop = ['observedStatus' => 'CANCEL_REQUESTED', 'actualStopped' => true] + $base;
    $falseSuccess = ['observedStatus' => 'SUCCEEDED'] + $base;

    expect(fn () => new ExecutionFact(...$cancelRequested))->not->toThrow(Exception::class);
    expect(fn () => new ExecutionFact(...$falseStop))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ExecutionFact(...$falseSuccess))->toThrow(InvalidArgumentException::class);
});

it('P0: ReconcileReport 固化设备代次、安装与执行事实', function () {
    $report = new ReconcileReport(
        did: 'did_1',
        modelCode: 'engineai_t800',
        observedGeneration: 12,
        installedSkills: [['skill_version_id' => 1, 'version' => '1.0.0', 'status' => 'enabled']],
        activeExecutions: [],
        outboxCursor: 'evt_100',
        reportedAt: 123,
    );

    expect($report->toArray()['observed_generation'])->toBe(12);
    expect($report->toArray()['outbox_cursor'])->toBe('evt_100');
});
it('P0: ACK Inbox 同键同载荷只落一条事实，同键异载荷 409 语义', function () {
    DB::table('deploy_inbox_event')->delete();
    $task = DeployTask::create([
        'task_no' => 'GA-ACK-'.uniqid(),
        'command_seq' => 1,
        'did' => 'did_inbox_1',
        'channel' => 'agent',
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'skill_id' => 1,
        'operation' => DeployTask::OP_INSTALL,
        'status' => 'dispatching',
    ]);

    $service = app(DeployInboxService::class);
    $payload = [
        'task_no' => $task->task_no,
        'did' => $task->did,
        'event_id' => 'evt_ack_1',
        'stage' => 'received_durable',
    ];

    $first = $service->acknowledge($payload);
    $second = $service->acknowledge($payload);
    $conflict = $service->acknowledge(array_replace($payload, ['stage' => 'tampered']));

    expect($first['duplicate'])->toBeFalse();
    expect($second['duplicate'])->toBeTrue();
    expect($second['event']->ack_id)->toBe($first['event']->ack_id);
    expect($conflict['conflict'])->toBeTrue();
    expect(DeployInboxEvent::where('did', $task->did)->where('event_id', 'evt_ack_1')->count())->toBe(1);
});

it('P0: Outbox 命令领取有租约，未 ACK 且未过期时其他 Worker 不能重领', function () {
    $task = DeployTask::create([
        'task_no' => 'GA-CLAIM-'.uniqid(),
        'command_seq' => 1,
        'did' => 'did_claim_1',
        'channel' => 'agent',
        'protocol_version' => DeployTask::PROTOCOL_V2,
        'skill_id' => 1,
        'operation' => DeployTask::OP_INSTALL,
        'status' => 'pending_online',
    ]);

    $service = app(DeployTaskService::class);
    $claimed = $service->claimNextCommand($task->did, DeployTask::PROTOCOL_V2);
    $blocked = $service->claimNextCommand($task->did, DeployTask::PROTOCOL_V2);

    expect($claimed)->not->toBeNull();
    expect($claimed->id)->toBe($task->id);
    expect($blocked)->toBeNull();
    expect($claimed->delivery_attempt)->toBe(1);
});
it('P0: InboxEvent、OutboxLease 与 TargetReleaseEvent 契约拒绝空身份和非法状态', function () {
    $inbox = new InboxEvent(
        did: 'did_1',
        eventId: 'evt_1',
        taskNo: 'task_1',
        eventType: 'execution_fact',
        stage: 'SUCCEEDED',
        payloadHash: str_repeat('a', 64),
        payload: ['actual_stopped' => true],
        eventSeq: 3,
        applied: true,
        resultStatus: 'success',
        lateReport: false,
        processedAt: 123,
        ackId: 'fact_1',
    );
    expect($inbox->toArray()['event_seq'])->toBe(3);

    $lease = new OutboxLease(
        taskId: 1,
        token: str_repeat('t', 64),
        attempt: 2,
        acquiredAt: 100,
        expiresAt: 130,
    );
    expect($lease->isExpired(130))->toBeTrue();

    $event = new TargetReleaseEvent(
        skillVersionId: 1,
        targetModelCode: 'agibot_a3',
        artifactAssetId: 2,
        buildKey: 'build_1',
        targetProfileDigest: 'sha256:'.str_repeat('b', 64),
        eventType: 'revoked',
        actorId: 7,
        reason: 'HIL evidence withdrawn',
        createdAt: 123,
    );
    expect($event->toArray()['event_type'])->toBe('revoked');

    expect(fn () => new OutboxLease(1, 'short', 1, 10, 20))->toThrow(InvalidArgumentException::class);
    expect(fn () => new TargetReleaseEvent(1, 'agibot_a3', 2, 'build_1', 'sha256:'.str_repeat('b', 64), 'revoked', 7, '', 123))
        ->toThrow(InvalidArgumentException::class);
});