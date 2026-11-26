<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeployInboxEvent;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Services\Deploy\Contracts\AgentFactEnvelopeV3;
use Webkul\Shop\Services\Deploy\Contracts\KeyMaterial;
use Webkul\Shop\Services\Deploy\Contracts\KeyProvider;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\DeviceKeyProvider;

use function Pest\Laravel\postJson;

/**
 * WBS A7 · 密钥生命周期（key_id / epoch / rotation / revocation / 离线恢复）。
 *
 * 全部使用本地 CA 真实签发证书与真实 RSA 签名 —— 不是 mock。
 * ⚠ KMS/HSM 未接入：`capabilities()` 必须如实为 false（A7.3）。
 */

function a7Setup(bool $withCredential = true): array
{
    config([
        'deploy_gateways.agent_key' => 'test-agent-key-v2',
        'deploy_gateways.agent_auth_mode' => 'auto',
        'agent_fact_signature.require_v3' => true,
        'agent_fact_signature.v2_window_enabled' => true,
        'deploy_gateways.device_credential.rotation_window_seconds' => 86400,
        'deploy_gateways.device_credential.epoch_grace' => 1,
    ]);

    $did = 'did_a7_'.uniqid();
    $binding = DeviceBinding::create([
        'did'         => $did,
        'sn'          => 'SN_A7_'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $credential = $withCredential ? app(DeviceCredentialService::class)->issue($binding) : null;
    $binding->refresh();

    $execId = 'exec_a7_'.uniqid();

    $task = DeployTask::create([
        'task_no'          => 'T_A7_'.uniqid(),
        'command_seq'      => 1,
        'did'              => $did,
        'skill_id'         => 982,
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
        'skill_id'       => 982,
        'status'         => SkillExecution::STATUS_RUNNING,
    ]);

    return [$binding, $task, $execId, $did, $credential, ['X-Agent-Key' => 'test-agent-key-v2']];
}

function a7Payload(DeployTask $task, string $did, string $execId): array
{
    $now = now()->timestamp;

    return [
        'task_no'      => $task->task_no,
        'did'          => $did,
        'event_id'     => 'evt_a7_'.uniqid(),
        'event_seq'    => 1,
        'phase'        => 'done',
        'evidence'     => ['observer' => 'synthetic-test-only'],
        'reported_at'  => $now,
        'execution_id' => $execId,
        'observed_status' => 'SUCCEEDED',
        'actual_started'  => true,
        'actual_stopped'  => true,
        'started_at'      => $now - 2,
        'stopped_at'      => $now,
        'error_code'      => null,
        'stop_observation' => [
            'execution_id'        => $execId,
            'source_type'         => 'independent_watchdog',
            'sensor_id'           => 'synthetic:a7-test',
            'observed_at'         => $now,
            'actual_started'      => true,
            'actual_stopped'      => true,
            'velocity'            => 0.0,
            'current'             => 0.0,
            'contact'             => true,
            'brake'               => true,
            'power'               => true,
            'quality'             => 'good',
            'raw_evidence_sha256' => str_repeat('d', 64),
            'collector_version'   => 'synthetic-test-only',
        ],
    ];
}

function a7Sign(array $payload, array $fact, string $privateKeyPem, string $keyId): array
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

    openssl_sign(
        AgentFactEnvelopeV3::canonicalFor($signed),
        $signature,
        openssl_pkey_get_private($privateKeyPem),
        OPENSSL_ALGO_SHA256,
    );

    return $signed + ['signature' => base64_encode($signature), 'key_id' => $keyId];
}

function a7Fact(array $payload): array
{
    return array_intersect_key($payload, array_flip([
        'execution_id', 'observed_status', 'actual_started', 'actual_stopped',
        'started_at', 'stopped_at', 'error_code', 'stop_observation',
    ]));
}

it('A7-01 DeviceKeyProvider 实现 KeyProvider 接口，且如实声明无 KMS/HSM', function () {
    $provider = app(DeviceKeyProvider::class);

    expect($provider)->toBeInstanceOf(KeyProvider::class)
        ->and($provider->capabilities())->toMatchArray([
            'kms'    => false,
            'hsm'    => false,
            'rotation' => true,
            'revocation' => true,
            'epochs' => true,
        ]);
});

it('A7-02 轮换：新 key_id 生效、代次 +1、旧密钥转入 retirement 窗口', function () {
    [$binding, , , $did] = a7Setup(true);
    $provider = app(DeviceKeyProvider::class);
    $credentials = app(DeviceCredentialService::class);

    $oldKeyId = $credentials->factKeyId($binding);
    expect($oldKeyId)->not->toBeNull()
        ->and($provider->currentEpoch($did))->toBe(1);

    $material = $provider->rotate($did, 'scheduled');

    expect($material)->toBeInstanceOf(KeyMaterial::class)
        ->and($material->keyId)->not->toBe($oldKeyId)
        ->and($material->epoch)->toBe(2)
        ->and($material->status)->toBe(KeyMaterial::STATUS_ACTIVE)
        ->and($provider->activeKeyId($did))->toBe($material->keyId)
        ->and($provider->currentEpoch($did))->toBe(2);

    $old = $provider->resolve($did, $oldKeyId);
    expect($old)->not->toBeNull()
        ->and($old->status)->toBe(KeyMaterial::STATUS_RETIRED)
        ->and($old->isRevoked())->toBeFalse()
        ->and($old->notAfter)->not->toBeNull()
        ->and($old->notAfter)->toBeGreaterThan(now()->timestamp);
});

it('A7-03 轮换窗口内：旧密钥仍可验签，新密钥也可验签', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a7Setup(true);
    $provider = app(DeviceKeyProvider::class);
    $credentials = app(DeviceCredentialService::class);

    $oldKeyId = $credentials->factKeyId($binding);
    $new = $provider->rotate($did, 'scheduled');
    expect($new->keyId)->toBe($provider->activeKeyId($did));

    // 旧密钥签的事件：窗口内应当被接受，且平台记录的就是这把旧 key
    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $credential['private_key_pem'], $oldKeyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)->assertOk();

    $event = DeployInboxEvent::where('did', $did)->firstOrFail();
    expect($event->payload['_platform_fact_auth']['key_id'])->toBe($oldKeyId)
        ->and($event->payload['_platform_fact_auth']['level'])->toBe('v3');
});

it('A7-04 轮换窗口外：旧密钥被拒（KEY_STALE），且不写 Inbox', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a7Setup(true);
    $provider = app(DeviceKeyProvider::class);
    $credentials = app(DeviceCredentialService::class);

    $oldKeyId = $credentials->factKeyId($binding);

    // 窗口设为负数 = 旧密钥在轮换瞬间就已过期（等价于"设备长时间没换钥"）
    config(['deploy_gateways.device_credential.rotation_window_seconds' => -60]);
    $provider->rotate($did, 'scheduled');

    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $credential['private_key_pem'], $oldKeyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_KEY_STALE');

    expect(DeployInboxEvent::where('did', $did)->count())->toBe(0)
        ->and($task->fresh()->status)->toBe('installing');
});

it('A7-05 吊销立即生效：被吊销密钥 401 KEY_REVOKED，且没有活动密钥时退回无签名也被拒', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a7Setup(true);
    $provider = app(DeviceKeyProvider::class);
    $credentials = app(DeviceCredentialService::class);

    $keyId = $credentials->factKeyId($binding);
    expect($provider->revoke($did, $keyId, 'leaked'))->toBeTrue()
        ->and($provider->activeKeyId($did))->toBeNull();

    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $credential['private_key_pem'], $keyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_KEY_REVOKED');

    // 无信封也不能借 v2 兼容窗口绕过：设备有密钥材料（虽然被吊销）就必须签
    $unsigned = a7Payload($task, $did, $execId);
    postJson(route('shop.api.agent.event'), $unsigned, $headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_FACT_SIGNATURE_REQUIRED');

    expect(DeployInboxEvent::where('did', $did)->count())->toBe(0);
});

it('A7-06 旧代次即使还在时间窗口内也被拒（epoch_grace 之外）', function () {
    [$binding, , $execId, $did, $credential, $headers] = a7Setup(true);
    $provider = app(DeviceKeyProvider::class);
    $credentials = app(DeviceCredentialService::class);

    $oldKeyId = $credentials->factKeyId($binding);
    $provider->rotate($did, 'scheduled-1');
    $provider->rotate($did, 'scheduled-2');

    expect($provider->currentEpoch($did))->toBe(3);

    // 旧密钥仍在轮换窗口内（notAfter = 轮换时刻 + 1 天），但代次 1 < 3-1
    $task = DeployTask::where('did', $did)->firstOrFail();

    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $credential['private_key_pem'], $oldKeyId);

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_KEY_STALE');
});

it('A7-07 未知 key_id 一律 401 KEY_UNKNOWN（有密钥环时不再落回 v2 窗口）', function () {
    [, $task, $execId, $did, $credential, $headers] = a7Setup(true);

    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $credential['private_key_pem'], str_repeat('f', 64));

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_KEY_UNKNOWN');
});

it('A7-08 事实缺 key_id 被拒（不能借"没写 key_id"绕过密钥选择）', function () {
    [, $task, $execId, $did, $credential, $headers] = a7Setup(true);

    $payload = a7Payload($task, $did, $execId);
    $envelope = a7Sign($payload, a7Fact($payload), $credential['private_key_pem'], 'placeholder');
    $envelope['key_id'] = '';
    $payload['fact_envelope'] = $envelope;

    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_SIGNATURE_INVALID');
});

it('A7-10 吊销凭据必须同时吊销密钥环：设备不能继续用旧私钥签事实', function () {
    [$binding, $task, $execId, $did, $credential, $headers] = a7Setup(true);
    $provider = app(DeviceKeyProvider::class);
    $credentials = app(DeviceCredentialService::class);

    $keyId = $credentials->factKeyId($binding);
    // 走既有的"吊销设备凭据"入口（HTTP 侧调用的就是它）
    expect($credentials->revoke($binding))->toBeTrue()
        ->and($provider->activeKeyId($did))->toBeNull();

    // 环里的公钥必须一起被吊销（而不是只吊销 bearer secret）
    $material = $provider->resolve($did, $keyId);
    expect($material)->not->toBeNull()
        ->and($material->isRevoked())->toBeTrue();

    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $credential['private_key_pem'], $keyId);

    // 请求会在凭据中间件就被拒（AGENT_CREDENTIAL_INVALID）；即使绕过中间件，
    // 事实验签也会以 AGENT_FACT_KEY_REVOKED 拒绝 —— 两条路都必须 401 且不落 Inbox。
    $response = postJson(route('shop.api.agent.event'), $payload, $headers)->assertStatus(401);
    expect($response->json('code'))->toBeIn(['AGENT_CREDENTIAL_INVALID', 'AGENT_FACT_KEY_REVOKED']);

    expect(DeployInboxEvent::where('did', $did)->count())->toBe(0);
});

it('A7-09 离线恢复：吊销后可重新签发新密钥恢复上报，旧密钥始终被拒', function () {
    [$binding, $task, $execId, $did, $revokedCredential, $headers] = a7Setup(true);
    $provider = app(DeviceKeyProvider::class);
    $credentials = app(DeviceCredentialService::class);

    $revokedKeyId = $credentials->factKeyId($binding);
    $provider->revoke($did, $revokedKeyId, 'lost-device');

    // ① 吊销期：旧密钥拒绝
    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $revokedCredential['private_key_pem'], $revokedKeyId);
    postJson(route('shop.api.agent.event'), $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_KEY_REVOKED');

    // ② 重新签发（离线恢复）：新活动密钥生效
    $binding->refresh();
    $newCredential = $credentials->issue($binding);
    $binding->refresh();
    $activeKeyId = $credentials->factKeyId($binding);

    expect($activeKeyId)->not->toBeNull()
        ->and($activeKeyId)->not->toBe($revokedKeyId)
        ->and($provider->activeKeyId($did))->toBe($activeKeyId);

    $payload = a7Payload($task, $did, $execId);
    $payload['fact_envelope'] = a7Sign($payload, a7Fact($payload), $newCredential['private_key_pem'], $activeKeyId);
    postJson(route('shop.api.agent.event'), $payload, $headers)->assertOk();

    // ③ 被吊销的旧密钥仍然拒绝
    $again = a7Payload($task, $did, $execId);
    $again['fact_envelope'] = a7Sign($again, a7Fact($again), $revokedCredential['private_key_pem'], $revokedKeyId);
    postJson(route('shop.api.agent.event'), $again, $headers)
        ->assertStatus(401)
        ->assertJsonPath('code', 'AGENT_FACT_KEY_REVOKED');

    expect(DB::table('deploy_inbox_event')->where('did', $did)->count())->toBe(1);
});
