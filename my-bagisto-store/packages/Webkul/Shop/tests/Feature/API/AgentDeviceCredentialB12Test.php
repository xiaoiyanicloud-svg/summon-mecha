<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * B-12 · §4.22 F1 per-device 证书 / 一机一证。
 *
 * 缺口:`VerifyAgentKey` 此前是**共享密钥**(类注释自认"生产应升级为 per-device 证书"),
 *   `agent/register|heartbeat|evt|commands` 四接口共用一把 `AGENT_KEY` —— 服务端**分不清
 *   真身与冒充者**:任何持 key 者能以任意 did 发请求,泄露一把即全体沦陷,无法单独吊销。
 *
 * 本文件锁服务端半边(可测部分):
 *   1. enroll 为**每台设备**签发证书 + 独立 secret,服务端只存哈希/证书(不存明文/私钥);
 *   2. per-device secret 能鉴权、错 secret 401;
 *   3. per-device **证书请求签名**(proof-of-possession)能鉴权,篡改/过期被拒;
 *   4. per-device 身份下**冒充他人 did** 被拒(共享密钥时代无法防的缺口);
 *   5. 渐进切换:mode=device 拒共享密钥,auto 仍放行(过渡态);
 *   6. 吊销后凭据立即失效(且**不回退**共享密钥)。
 *
 * ⚠ 诚实边界:**设备侧 mTLS 握手 / 真机用私钥签名** 本机无法实测(无真机/无 EMQX)。
 *   本文件测的是**服务端签发与校验**逻辑 —— 与真机无关的那一半。设备侧待真机验证。
 */

function b12Key(): string
{
    return 'pest-b12-key';
}

function b12Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Agent',
        'last_name'   => 'B12',
        'email'       => 'b12'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function b12Binding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'         => 'did_b12_'.uniqid(),
        'sn'          => 'B12-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
        'bound_at'    => now(),
    ]);
}

function b12SecretHeaders(DeviceBinding $b, string $secret): array
{
    return ['X-Agent-Did' => $b->did, 'X-Agent-Secret' => $secret];
}

function b12Sign(string $keyPem, string $canonical): string
{
    openssl_sign($canonical, $sig, $keyPem, OPENSSL_ALGO_SHA256);

    return base64_encode($sig);
}

beforeEach(function () {
    config([
        'deploy_gateways.agent_key'       => b12Key(),
        'deploy_gateways.agent_auth_mode' => 'auto',
    ]);
});

// ───────────── 1 · 签发:每设备一证,库内不留明文 ─────────────

it('B-12 · enroll 为设备签发证书 + 独立 secret,服务端只存哈希/证书(不存明文)', function () {
    $plain = 'tok-'.uniqid();
    $sn = 'B12-ENROLL-'.uniqid();

    $binding = DeviceBinding::create([
        'did'         => 'sn_'.$sn,
        'sn'          => $sn,
        'source'      => DeviceBinding::SOURCE_MANUAL,
        'bind_status' => 'bound',
        'meta'        => [
            'agent_enroll' => [
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addMinutes(30)->toDateTimeString(),
            ],
        ],
    ]);

    $res = postJson(route('shop.api.agent.enroll'), ['token' => $plain]);

    $res->assertOk()->assertJsonStructure([
        'data' => ['did', 'device_secret', 'certificate_pem', 'private_key_pem', 'ca_pem', 'agent_key'],
    ]);

    $secret = $res->json('data.device_secret');
    $binding->refresh();
    $cred = $binding->meta['agent_credential'];

    expect($cred['secret_hash'])->toBe(hash('sha256', $secret))
        ->and($cred['secret_hash'])->not->toBe($secret)
        ->and($cred['cert_pem'])->toContain('BEGIN CERTIFICATE');

    // 明文 secret 绝不落库;私钥也绝不落库
    expect(json_encode($binding->meta))->not->toContain($secret)
        ->and(json_encode($binding->meta))->not->toContain('PRIVATE KEY');

    // 服务端据此可校验
    expect(app(DeviceCredentialService::class)->verifySecret($binding, $secret))->toBeTrue();
});

// ───────────── 2 · per-device secret 鉴权 ─────────────

it('B-12 · per-device secret 能鉴权,错误 secret → 401', function () {
    $binding = b12Binding();
    $cred = app(DeviceCredentialService::class)->issue($binding);

    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did], b12SecretHeaders($binding, $cred['secret']))
        ->assertOk()
        ->assertJsonPath('data.online', true);

    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did], [
        'X-Agent-Did'    => $binding->did,
        'X-Agent-Secret' => 'wrong-secret',
    ])->assertStatus(401);
});

// ───────────── 3 · 证书请求签名(proof-of-possession)─────────────

it('B-12 · 证书请求签名可鉴权,篡改签名 / 过期 ts 被拒', function () {
    $binding = b12Binding();
    $cred = app(DeviceCredentialService::class)->issue($binding);

    $ts = time();
    $canonical = DeviceCredentialService::requestCanonical($binding->did, $ts, 'GET', 'api/agent/commands');

    getJson(route('shop.api.agent.commands').'?did='.$binding->did, [
        'X-Agent-Did'       => $binding->did,
        'X-Agent-Signature' => b12Sign($cred['private_key_pem'], $canonical),
        'X-Agent-Ts'        => (string) $ts,
    ])->assertOk();

    // 篡改签名(非本私钥所签)
    getJson(route('shop.api.agent.commands').'?did='.$binding->did, [
        'X-Agent-Did'       => $binding->did,
        'X-Agent-Signature' => base64_encode('garbage-signature'),
        'X-Agent-Ts'        => (string) $ts,
    ])->assertStatus(401);

    // 过期 ts(超出 ±5min 窗口)—— 即使签名本身合法也拒
    $old = time() - (DeviceCredentialService::TS_WINDOW_SECONDS + 100);
    $oldCanonical = DeviceCredentialService::requestCanonical($binding->did, $old, 'GET', 'api/agent/commands');

    getJson(route('shop.api.agent.commands').'?did='.$binding->did, [
        'X-Agent-Did'       => $binding->did,
        'X-Agent-Signature' => b12Sign($cred['private_key_pem'], $oldCanonical),
        'X-Agent-Ts'        => (string) $old,
    ])->assertStatus(401);
});

// ───────────── 4 · 冒充他人 did 被拒 ─────────────

it('B-12 · per-device 身份下用 A 的凭据拉 B 的指令 → 403(冒充被拒)', function () {
    $a = b12Binding();
    $b = b12Binding();
    $cred = app(DeviceCredentialService::class)->issue($a);

    // 用 A 的 secret,却请求 B 的 did
    getJson(route('shop.api.agent.commands').'?did='.$b->did, b12SecretHeaders($a, $cred['secret']))
        ->assertStatus(403)
        ->assertJsonPath('code', 'AGENT_IDENTITY_MISMATCH');
});

// ───────────── 5 · 渐进切换 ─────────────

it('B-12 · auth_mode=device 时共享密钥被拒(401)', function () {
    config(['deploy_gateways.agent_auth_mode' => 'device']);
    $binding = b12Binding();

    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did], ['X-Agent-Key' => b12Key()])
        ->assertStatus(401);
});

it('B-12 · auth_mode=auto 时共享密钥仍放行(过渡期)', function () {
    $binding = b12Binding();

    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did], ['X-Agent-Key' => b12Key()])
        ->assertOk();
});

// ───────────── 6 · 吊销 ─────────────

it('B-12 · 吊销后 secret 立即失效(且不回退共享密钥)', function () {
    $binding = b12Binding();
    $cred = app(DeviceCredentialService::class)->issue($binding);

    app(DeviceCredentialService::class)->revoke($binding);

    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did], b12SecretHeaders($binding, $cred['secret']))
        ->assertStatus(401);
});

it('B-12 · 用户可单独吊销本人设备凭据(HTTP 入口)', function () {
    $customer = b12Customer();
    actingAs($customer, 'customer');

    // 经登记接口建设备 + binding
    $res = postJson(route('shop.api.devices.store'), ['sn' => 'B12-REVOKE-'.uniqid()])
        ->assertStatus(201);

    $deviceId = $res->json('data.id');
    $binding = DeviceBinding::where('devices_id', $deviceId)->firstOrFail();

    app(DeviceCredentialService::class)->issue($binding);

    deleteJson(route('shop.api.devices.agent-credential.revoke', $deviceId))->assertOk();

    $binding->refresh();
    expect($binding->meta['agent_credential']['revoked_at'])->not->toBeNull();
});

it('B-12 · 无凭据设备吊销 → 404', function () {
    $customer = b12Customer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.devices.store'), ['sn' => 'B12-NOCRED-'.uniqid()])
        ->assertStatus(201);

    deleteJson(route('shop.api.devices.agent-credential.revoke', $res->json('data.id')))
        ->assertStatus(404);
});
