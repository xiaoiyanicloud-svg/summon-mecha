<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeployInboxEvent;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Services\Deploy\Contracts\AgentFactEnvelopeV3;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;

use function Pest\Laravel\postJson;

/**
 * WBS A1：事件正文 agent-fact/v3 签名、正文替换、重放与跨语言 JCS。
 *
 * ⚠ 所有测试观察器均为 synthetic-test-only，不代表真机 HIL。
 */

function a1Fixture(): array
{
    $path = base_path('../qa/wbs-20261105/jcs-vectors.json');
    expect(is_file($path))->toBeTrue('缺少跨语言黄金向量文件');

    return json_decode((string) file_get_contents($path), true);
}

function a1Setup(bool $withCredential = true): array
{
    config([
        'deploy_gateways.agent_key' => 'test-agent-key-v2',
        'deploy_gateways.agent_auth_mode' => 'auto',
        'agent_fact_signature.require_v3' => true,
        'agent_fact_signature.v2_window_enabled' => true,
    ]);

    $did = 'did_v3_'.uniqid();
    $binding = DeviceBinding::create([
        'did'         => $did,
        'sn'          => 'SN_V3_'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $credential = null;
    if ($withCredential) {
        $credential = app(DeviceCredentialService::class)->issue($binding);
        $binding->refresh();
    }

    $execId = 'exec_v3_'.uniqid();

    $task = DeployTask::create([
        'task_no'          => 'T_V3_'.uniqid(),
        'command_seq'      => 1,
        'did'              => $did,
        'skill_id'         => 981,
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
        'skill_id'       => 981,
        'status'         => SkillExecution::STATUS_RUNNING,
    ]);

    return [$binding, $task, $execId, $did, $credential, ['X-Agent-Key' => 'test-agent-key-v2']];
}

function a1Payload(DeployTask $task, string $did, string $execId, array $overrides = []): array
{
    $now = now()->timestamp;

    $fact = [
        'execution_id'   => $execId,
        'observed_status' => 'SUCCEEDED',
        'actual_started' => true,
        'actual_stopped' => true,
        'started_at'     => $now - 2,
        'stopped_at'     => $now,
        'error_code'     => null,
        'stop_observation' => [
            'execution_id'        => $execId,
            'source_type'         => 'independent_watchdog',
            'sensor_id'           => 'synthetic:a1-test',
            'observed_at'         => $now,
            'actual_started'      => true,
            'actual_stopped'      => true,
            'velocity'            => 0.0,
            'current'             => 0.0,
            'contact'             => true,
            'brake'               => true,
            'power'               => true,
            'quality'             => 'good',
            'raw_evidence_sha256' => str_repeat('c', 64),
            'collector_version'   => 'synthetic-test-only',
        ],
    ];

    return array_merge([
        'task_no'      => $task->task_no,
        'did'          => $did,
        'event_id'     => 'evt_v3_'.uniqid(),
        'event_seq'    => 1,
        'phase'        => 'done',
        'evidence'     => ['observer' => 'synthetic-test-only'],
        'reported_at'  => $now,
    ], $fact, $overrides);
}

function a1Sign(array $payload, array $fact, string $privateKeyPem, string $keyId): array
{
    $signed = [
        'protocol'       => AgentFactEnvelopeV3::PROTOCOL,
        'event_id'       => $payload['event_id'],
        'did'            => $payload['did'],
        'task_no'        => $payload['task_no'],
        'event_seq'      => $payload['event_seq'] ?? null,
        'phase'          => $payload['phase'],
        'execution_fact' => $fact,
        'evidence'       => $payload['evidence'] ?? [],
        'reported_at'    => $payload['reported_at'],
    ];

    $private = openssl_pkey_get_private($privateKeyPem);
    openssl_sign(AgentFactEnvelopeV3::canonicalFor($signed), $signature, $private, OPENSSL_ALGO_SHA256);

    return $signed + ['signature' => base64_encode($signature), 'key_id' => $keyId];
}

function a1FactFromPayload(array $payload): array
{
    return array_intersect_key($payload, array_flip([
        'execution_id', 'observed_status', 'actual_started', 'actual_stopped',
        'started_at', 'stopped_at', 'error_code', 'stop_observation',
    ]));
}

it('A1 · 跨语言 JCS 黄金向量：PHP 与 Python 对同一正文产生同一规范字节与 key_id', function () {
    $fixture = a1Fixture();

    expect(AgentFactEnvelopeV3::canonicalFor($fixture['signed']))->toBe($fixture['expected_canonical']);

    $private = openssl_pkey_get_private($fixture['private_key_pem']);
    $details = openssl_pkey_get_details($private);
    $keyId = hash('sha256', DeviceCredentialService::normalizePem((string) $details['key']));

    expect($keyId)->toBe($fixture['expected_key_id']);
});

it('A1 · 持证设备缺正文信封 → 422，且不落 Inbox/不改状态', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(true);

    $response = postJson(route('shop.api.agent.event'), a1Payload($task, $did, $execId), $headers);

    $response->assertStatus(422)->assertJsonPath('code', 'AGENT_FACT_SIGNATURE_REQUIRED');

    expect($task->fresh()->status)->toBe('installing')
        ->and(SkillExecution::where('execution_id', $execId)->value('status'))->toBe(SkillExecution::STATUS_RUNNING)
        ->and(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(0);
});

it('A1 · 有效 agent-fact/v3 → 200，Inbox 记录 v3 认证级别', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(true);

    $payload = a1Payload($task, $did, $execId);
    $keyId = app(DeviceCredentialService::class)->factKeyId($binding);
    $fact = a1FactFromPayload($payload);
    $payload['fact_envelope'] = a1Sign($payload, $fact, $credential['private_key_pem'], $keyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'success');

    $event = DeployInboxEvent::where('did', $did)->firstOrFail();

    expect($event->payload['_platform_fact_auth']['level'])->toBe('v3')
        ->and($event->payload['_platform_fact_auth']['key_id'])->toBe($keyId)
        ->and($task->fresh()->status)->toBe('success');
});

it('A1 · 正文被替换（外层 phase 改掉、签名不变）→ 422 BODY_MISMATCH', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(true);

    $payload = a1Payload($task, $did, $execId);
    $keyId = app(DeviceCredentialService::class)->factKeyId($binding);
    $fact = a1FactFromPayload($payload);
    $payload['fact_envelope'] = a1Sign($payload, $fact, $credential['private_key_pem'], $keyId);

    // 攻击者改外层 phase（签名体仍是 done）
    $payload['phase'] = 'failed';

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_FACT_BODY_MISMATCH');

    expect($task->fresh()->status)->toBe('installing')
        ->and(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(0);
});

it('A1 · key_id 与登记证书不匹配 → 401 KEY_UNKNOWN', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(true);

    $payload = a1Payload($task, $did, $execId);
    $fact = a1FactFromPayload($payload);
    $payload['fact_envelope'] = a1Sign($payload, $fact, $credential['private_key_pem'], str_repeat('0', 64));

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_KEY_UNKNOWN');

    expect($task->fresh()->status)->toBe('installing');
});

it('A1 · 用错私钥签名（key_id 正确）→ 401 SIGNATURE_INVALID', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(true);

    // 黄金向量里那把固定测试私钥与设备证书不是一对。
    $otherPem = a1Fixture()['private_key_pem'];

    $payload = a1Payload($task, $did, $execId);
    $keyId = app(DeviceCredentialService::class)->factKeyId($binding);
    $fact = a1FactFromPayload($payload);
    $payload['fact_envelope'] = a1Sign($payload, $fact, $otherPem, $keyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_SIGNATURE_INVALID');

    expect($task->fresh()->status)->toBe('installing');
});

it('A1 · 同 event_id 同载荷重投 → 幂等 duplicate', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(true);

    $payload = a1Payload($task, $did, $execId);
    $keyId = app(DeviceCredentialService::class)->factKeyId($binding);
    $fact = a1FactFromPayload($payload);
    $payload['fact_envelope'] = a1Sign($payload, $fact, $credential['private_key_pem'], $keyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)->assertOk();
    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    expect(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(1);
});

it('A1 · 同 event_id 异载荷（重新签名）→ 409 冲突，不静默覆盖', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(true);

    $payload = a1Payload($task, $did, $execId);
    $keyId = app(DeviceCredentialService::class)->factKeyId($binding);
    $fact = a1FactFromPayload($payload);
    $payload['fact_envelope'] = a1Sign($payload, $fact, $credential['private_key_pem'], $keyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)->assertOk();

    $tampered = $payload;
    $tampered['error_code'] = 'TAMPERED';
    $tampered['fact_envelope'] = a1Sign(
        $tampered,
        a1FactFromPayload($tampered),
        $credential['private_key_pem'],
        $keyId,
    );

    postJson(route('shop.api.agent.event'), $tampered, $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONFLICT');
});

it('A1 · 无凭据历史设备 + 共享密钥 → 保留 v2_request_only 兼容窗口', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(false);

    postJson(route('shop.api.agent.event'), a1Payload($task, $did, $execId), $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'success');

    $event = DeployInboxEvent::where('did', $did)->firstOrFail();

    expect($event->payload['_platform_fact_auth']['level'])->toBe('v2_request_only');
});

it('A1 · v2 兼容窗口关闭后，无凭据设备也不接受未签名正文', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a1Setup(false);

    config(['agent_fact_signature.v2_window_enabled' => false]);

    postJson(route('shop.api.agent.event'), a1Payload($task, $did, $execId), $headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_FACT_SIGNATURE_REQUIRED');

    expect($task->fresh()->status)->toBe('installing');
});
