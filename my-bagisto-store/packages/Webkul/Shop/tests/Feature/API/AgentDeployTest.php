<?php

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;

use function Pest\Laravel\postJson;

/**
 * v3.4 步骤 8 · Agent 下发链路的边界测试。
 *
 * 覆盖三处最容易出错的契约:
 *   1. enroll 的一次性 token(无效 / 过期 / 合法且按 sn 归并)
 *   2. internal.key 中间件(未配 → 503,错 key → 401)
 *   3. install_record 的 operation 语义(installed / updated / rolled_back)
 *
 * 说明:项目此前没有 internal.key 路由的测试,这里是第一处。
 */

it('enroll 用无效 token 返回 401', function () {
    postJson(route('shop.api.agent.enroll'), ['token' => 'not-a-real-token'])
        ->assertStatus(401);
});

it('enroll 用已过期 token 返回 401', function () {
    $plain = 'expired-token-'.uniqid();

    DeviceBinding::create([
        'did'         => 'sn_expired_'.uniqid(),
        'sn'          => 'EXPIRED-'.uniqid(),
        'source'      => DeviceBinding::SOURCE_MANUAL,
        'bind_status' => 'bound',
        'meta'        => [
            'agent_enroll' => [
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->subMinute()->toDateTimeString(),
            ],
        ],
    ]);

    postJson(route('shop.api.agent.enroll'), ['token' => $plain])
        ->assertStatus(401);
});

it('enroll 用合法 token 换取身份并清掉 token', function () {
    $plain = 'good-token-'.uniqid();
    $sn = 'ENROLL-'.uniqid();

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

    $res->assertOk()
        ->assertJsonPath('data.did', $binding->did)
        ->assertJsonStructure(['data' => ['did', 'agent_key', 'server_public_key']]);

    $binding->refresh();

    // 一次性:用掉即清,且身份确实升为 agent
    expect($binding->meta['agent_enroll'] ?? null)->toBeNull();
    expect($binding->source)->toBe(DeviceBinding::SOURCE_AGENT);
});

it('灰度接口未配 internal key 时返回 503', function () {
    config(['skill_delivery.upload_key' => null]);

    postJson(route('shop.api.gray-releases.store'), ['skill_version_id' => 1])
        ->assertStatus(503);
});

it('灰度接口用错 internal key 时返回 401', function () {
    config(['skill_delivery.upload_key' => 'real-key']);

    postJson(route('shop.api.gray-releases.store'), ['skill_version_id' => 1], [
        'X-Internal-Key' => 'wrong-key',
    ])->assertStatus(401);
});

it('install_record:首次安装落 installed,同 skill 换版本落 updated', function () {
    $productId = 900000 + random_int(1, 9999);
    $did = 'sn_pest_'.uniqid();

    $v1 = SkillVersion::create([
        'product_id' => $productId, 'version' => '1.0.0', 'channel' => 'stable',
        'status' => SkillVersion::STATUS_PUBLISHED, 'license_mode' => 'per_device',
    ]);
    $v2 = SkillVersion::create([
        'product_id' => $productId, 'version' => '2.0.0', 'channel' => 'stable',
        'status' => SkillVersion::STATUS_PUBLISHED, 'license_mode' => 'per_device',
    ]);

    $service = app(DeployTaskService::class);

    // 首次安装
    $t1 = DeployTask::create([
        'task_no' => 'DTEST'.uniqid(), 'did' => $did, 'channel' => 'agent',
        'skill_id' => $productId, 'skill_version_id' => $v1->id,
        'operation' => DeployTask::OP_INSTALL, 'status' => 'dispatching', 'created_by' => 'test',
    ]);
    $service->onEvent($t1->task_no, 'done');

    expect(InstallRecord::where('did', $did)->where('skill_version_id', $v1->id)->value('status'))
        ->toBe('installed');

    // 升级到同 skill 的新版本 → updated(这是灰度回滚的靶子)
    $t2 = DeployTask::create([
        'task_no' => 'DTEST'.uniqid(), 'did' => $did, 'channel' => 'agent',
        'skill_id' => $productId, 'skill_version_id' => $v2->id,
        'operation' => DeployTask::OP_INSTALL, 'status' => 'dispatching', 'created_by' => 'test',
    ]);
    $service->onEvent($t2->task_no, 'done');

    expect(InstallRecord::where('did', $did)->where('skill_version_id', $v2->id)->value('status'))
        ->toBe('updated');
    expect(InstallRecord::where('did', $did)->where('skill_version_id', $v1->id)->value('status'))
        ->toBe('installed');

    // 回滚该版本 → rolled_back
    $t3 = DeployTask::create([
        'task_no' => 'DTEST'.uniqid(), 'did' => $did, 'channel' => 'agent',
        'skill_id' => $productId, 'skill_version_id' => $v2->id,
        'operation' => DeployTask::OP_ROLLBACK, 'status' => 'dispatching', 'created_by' => 'test',
    ]);
    $service->onEvent($t3->task_no, 'done');

    expect(InstallRecord::where('did', $did)->where('skill_version_id', $v2->id)->value('status'))
        ->toBe('rolled_back');
});
