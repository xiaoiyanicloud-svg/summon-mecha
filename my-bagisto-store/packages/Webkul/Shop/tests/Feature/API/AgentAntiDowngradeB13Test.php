<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\AgentSelfUpdateService;
use Webkul\Shop\Services\Deploy\AgentVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\DowngradeRejectedException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * B-13 · §4.22 F1 Agent 自更新 / F3 防降级(Uptane)。
 *
 * 缺口:全仓无 self_update / uptane / tuf 命中,也**没有任何版本比较**(version_compare
 *   零命中)—— 平台可以拿签名合法但更旧的包,让设备退回有已知漏洞的版本(rollback 攻击)。
 *
 * 本文件锁服务端能兑现的部分:
 *   1. AgentVersion 版本单调判定;
 *   2. 建安装任务时**拒绝低于本机已装版本**(回滚 OP_ROLLBACK 不受此闸,allowDowngrade 可显式放行);
 *   3. Agent 自更新工件接口:只提供**更高**版本,latest 被人为改低时回 DOWNGRADE_BLOCKED;
 *      工件带平台签名,可用内置公钥验签。
 *
 * ⚠ 诚实边界:**端侧凭签名元数据独立拒绝降级**(Uptane/TUF 的最终形态)与
 *   **自更新的下载/替换/失败回滚**都是**设备侧**行为 —— 本机无真机,未验证。
 *   本文件只测服务端。参考 agent.py 里的自更新调用同样标注"待真机"。
 */

function b13Key(): string
{
    return 'pest-b13-key';
}

function b13Headers(): array
{
    return ['X-Agent-Key' => b13Key()];
}

function b13Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Agent',
        'last_name'   => 'B13',
        'email'       => 'b13'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function b13Binding(): DeviceBinding
{
    return DeviceBinding::create([
        'did'         => 'did_b13_'.uniqid(),
        'sn'          => 'B13-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
        'bound_at'    => now(),
    ]);
}

beforeEach(function () {
    config([
        'deploy_gateways.agent_key'       => b13Key(),
        'deploy_gateways.agent_auth_mode' => 'auto',
        // 默认无自更新工件(可被单个测试覆盖)
        'deploy_gateways.agent_latest'    => ['version' => null, 'url' => null, 'hash' => null],
    ]);
});

// ───────────── 1 · 版本单调判定 ─────────────

it('B-13 · AgentVersion:降级/更新判定', function () {
    expect(AgentVersion::isDowngrade('2.0.0', '1.0.0'))->toBeTrue()
        ->and(AgentVersion::isDowngrade('2.0.0', '2.0.0'))->toBeFalse()
        ->and(AgentVersion::isDowngrade('2.0.0', '2.1.0'))->toBeFalse()
        // 已装版本未知 → 不误判为降级
        ->and(AgentVersion::isDowngrade(null, '1.0.0'))->toBeFalse()
        ->and(AgentVersion::isNewer('1.2.0', '1.1.0'))->toBeTrue()
        ->and(AgentVersion::isNewer('1.1.0', '1.1.0'))->toBeFalse()
        ->and(AgentVersion::isNewer('1.0.0', '1.1.0'))->toBeFalse()
        // 前缀 v 归一化
        ->and(AgentVersion::isDowngrade('v2.0.0', 'v1.0.0'))->toBeTrue();
});

// ───────────── 2 · 建安装任务时的降级闸 ─────────────

function b13Versions(int $productId): array
{
    return [
        'old' => SkillVersion::create([
            'product_id' => $productId, 'version' => '1.0.0', 'channel' => 'stable',
            'status' => SkillVersion::STATUS_PUBLISHED, 'license_mode' => 'per_device',
        ]),
        'new' => SkillVersion::create([
            'product_id' => $productId, 'version' => '2.0.0', 'channel' => 'stable',
            'status' => SkillVersion::STATUS_PUBLISHED, 'license_mode' => 'per_device',
        ]),
        'newest' => SkillVersion::create([
            'product_id' => $productId, 'version' => '3.0.0', 'channel' => 'stable',
            'status' => SkillVersion::STATUS_PUBLISHED, 'license_mode' => 'per_device',
        ]),
    ];
}

function b13Install(DeviceBinding $binding, SkillVersion $installed): void
{
    InstallRecord::create([
        'did'              => $binding->did,
        'skill_version_id' => $installed->id,
        'status'           => 'installed',
        'current_version'  => $installed->version,
        'installed_at'     => now(),
    ]);
}

it('B-13 · 已装 2.0.0 时装 1.0.0 → 抛 DowngradeRejectedException(不落任务)', function () {
    $productId = 950000 + random_int(1, 9999);
    $v = b13Versions($productId);
    $binding = b13Binding();
    b13Install($binding, $v['new']);

    $before = DeployTask::count();

    expect(fn () => app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: $productId,
        skillVersionId: $v['old']->id,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
    ))->toThrow(DowngradeRejectedException::class);

    expect(DeployTask::count())->toBe($before);
});

it('B-13 · 已装 2.0.0 时装 3.0.0 → 放行(版本更高)', function () {
    $productId = 951000 + random_int(1, 9999);
    $v = b13Versions($productId);
    $binding = b13Binding();
    b13Install($binding, $v['new']);

    $task = app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: $productId,
        skillVersionId: $v['newest']->id,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
    );

    expect($task->skill_version_id)->toBe($v['newest']->id);
});

it('B-13 · 回滚(OP_ROLLBACK)不受降级闸限制', function () {
    $productId = 952000 + random_int(1, 9999);
    $v = b13Versions($productId);
    $binding = b13Binding();
    b13Install($binding, $v['new']);

    $task = app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: $productId,
        skillVersionId: $v['old']->id,
        entitlementId: null,
        operation: DeployTask::OP_ROLLBACK,
    );

    expect($task->operation)->toBe(DeployTask::OP_ROLLBACK);
});

it('B-13 · allowDowngrade=true 时显式放行(平台受控场景)', function () {
    $productId = 953000 + random_int(1, 9999);
    $v = b13Versions($productId);
    $binding = b13Binding();
    b13Install($binding, $v['new']);

    $task = app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: $productId,
        skillVersionId: $v['old']->id,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
        allowDowngrade: true,
    );

    expect($task->skill_version_id)->toBe($v['old']->id);
});

it('B-13 · HTTP 入口:降级下发 → 409 + AGENT_DOWNGRADE_REJECTED', function () {
    $customer = b13Customer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.devices.store'), ['sn' => 'B13-'.uniqid()])->assertStatus(201);
    $deviceId = $res->json('data.id');
    $binding = DeviceBinding::where('devices_id', $deviceId)->firstOrFail();

    $productId = 954000 + random_int(1, 9999);
    $v = b13Versions($productId);
    b13Install($binding, $v['new']);

    postJson(route('shop.api.deploy.store'), [
        'device_id'        => $deviceId,
        'skill_id'         => $productId,
        'skill_version_id' => $v['old']->id,
        'operation'        => DeployTask::OP_INSTALL,
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'AGENT_DOWNGRADE_REJECTED')
        ->assertJsonPath('data.target_version', '1.0.0');
});

// ───────────── 3 · Agent 自更新工件 ─────────────

it('B-13 · 自更新:未配置 → NOT_CONFIGURED', function () {
    $binding = b13Binding();

    getJson(route('shop.api.agent.self-update').'?did='.$binding->did.'&agent_version=0.1.0', b13Headers())
        ->assertOk()
        ->assertJsonPath('data.update_available', false)
        ->assertJsonPath('data.reason', 'NOT_CONFIGURED');
});

it('B-13 · 自更新:有更高版本 → 下发工件且签名可验', function () {
    config(['deploy_gateways.agent_latest' => [
        'version' => '0.2.0', 'url' => 'https://example.test/agent.py', 'hash' => 'deadbeef',
    ]]);

    $binding = b13Binding();

    $res = getJson(route('shop.api.agent.self-update').'?did='.$binding->did.'&agent_version=0.1.0', b13Headers())
        ->assertOk()
        ->assertJsonPath('data.update_available', true)
        ->assertJsonPath('data.version', '0.2.0');

    $d = $res->json('data');

    expect(app(AgentSelfUpdateService::class)->verify($d['version'], $d['url'], $d['hash'], $d['ts'], $d['signature']))
        ->toBeTrue();
});

it('B-13 · 自更新:平台 latest 被人为改低 → DOWNGRADE_BLOCKED(绝不推低版本)', function () {
    config(['deploy_gateways.agent_latest' => [
        'version' => '0.0.9', 'url' => 'https://example.test/agent.py', 'hash' => 'deadbeef',
    ]]);

    $binding = b13Binding();

    getJson(route('shop.api.agent.self-update').'?did='.$binding->did.'&agent_version=1.0.0', b13Headers())
        ->assertOk()
        ->assertJsonPath('data.update_available', false)
        ->assertJsonPath('data.reason', 'DOWNGRADE_BLOCKED');
});

it('B-13 · 自更新:版本相同 → UP_TO_DATE', function () {
    config(['deploy_gateways.agent_latest' => [
        'version' => '1.0.0', 'url' => 'https://example.test/agent.py', 'hash' => 'deadbeef',
    ]]);

    $binding = b13Binding();

    getJson(route('shop.api.agent.self-update').'?did='.$binding->did.'&agent_version=1.0.0', b13Headers())
        ->assertOk()
        ->assertJsonPath('data.update_available', false)
        ->assertJsonPath('data.reason', 'UP_TO_DATE');
});

// ───────────── 4 · 版本上报落库 ─────────────

it('B-13 · 心跳携带 agent_version → 落到 binding.meta,且去重', function () {
    $binding = b13Binding();

    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did, 'agent_version' => '0.1.0'], b13Headers())
        ->assertOk();

    expect($binding->fresh()->meta['agent_version'])->toBe('0.1.0');

    // 版本没变 → 不改 seen_at(避免心跳高频写库)
    $seenAt = $binding->fresh()->meta['agent_version_seen_at'];
    postJson(route('shop.api.agent.heartbeat'), ['did' => $binding->did, 'agent_version' => '0.1.0'], b13Headers())
        ->assertOk();
    expect($binding->fresh()->meta['agent_version_seen_at'])->toBe($seenAt);
});
