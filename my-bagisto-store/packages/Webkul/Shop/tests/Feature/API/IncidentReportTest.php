<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\Incident;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 事故上报（用户侧）—— 计划 §4.19。
 *
 * 本文件的重头戏是**证据链在报案那一刻被冻结**：
 * 事后改设备型号/换包/归档版本，`trace` 都不应该跟着变 ——
 * 因为定责看的是"出事时的样子"，不是"现在的样子"。
 */

function incCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Inc',
        'last_name'   => 'User',
        'email'       => 'inc'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 造一台属于某客户的设备（含机型与绑定）。
 *
 * @return array{0:int,1:int}  [deviceId, bindingId]
 */
function incDevice(Customer $customer, ?string $modelCode = null): array
{
    $modelCode = $modelCode ?? 'inc_model_'.uniqid();

    DeviceModel::create([
        'brand'         => 'test',
        'model_code'    => $modelCode,
        'model_name'    => '测试机型',
        'comm_channels' => ['manual'],
        'status'        => DeviceModel::STATUS_ACTIVE,
    ]);

    $sn = 'SN'.uniqid();

    $bindingId = DB::table('device_binding')->insertGetId([
        'did'               => 'did_'.uniqid(),
        'sn'                => $sn,
        'model_id'          => DeviceModel::where('model_code', $modelCode)->value('id'),
        'owner_customer_id' => $customer->id,
        'source'            => 'manual',
        'bind_status'       => 'bound',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    $deviceId = DB::table('devices')->insertGetId([
        'customer_id'      => $customer->id,
        'sn'               => $sn,
        'binding_id'       => $bindingId,
        'model_code'       => $modelCode,
        'display_name'     => '我的人形机器人',
        'firmware_version' => '1.2.3',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    return [$deviceId, $bindingId];
}

it('上报成功：生成事故编号，责任默认「尚未认定」', function () {
    $customer = incCustomer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MODERATE,
        'description' => '机器人在展厅突然加速，撞坏了展台',
    ])->assertStatus(201)
        ->assertJsonPath('data.status', Incident::STATUS_REPORTED);

    $incident = Incident::where('customer_id', $customer->id)->first();

    expect($incident)->not->toBeNull()
        ->and($incident->incident_no)->toStartWith('I')
        ->and($incident->severity)->toBe(Incident::SEVERITY_MODERATE)
        // ⚠ 不预设责任方 —— 报案时通常还不知道是谁的责任
        ->and($incident->responsibility)->toBe(Incident::RESPONSIBILITY_UNKNOWN)
        ->and($incident->status)->toBe(Incident::STATUS_REPORTED);
});

it('上报：严重程度必须在白名单内', function () {
    $customer = incCustomer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => 'apocalyptic',
        'description' => 'x',
    ])->assertStatus(422);

    expect(Incident::where('customer_id', $customer->id)->count())->toBe(0);
});

it('上报：描述必填', function () {
    $customer = incCustomer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity' => Incident::SEVERITY_MINOR,
    ])->assertStatus(422);
});

it('授权边界：不能把别人的设备挂到自己的报案上（防枚举他人设备）', function () {
    $me = incCustomer();
    $other = incCustomer();

    [$otherDeviceId] = incDevice($other);

    actingAs($me, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_SEVERE,
        'description' => '试图关联别人的设备',
        'device_id'   => $otherDeviceId,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'DEVICE_NOT_OWNED');

    expect(Incident::where('customer_id', $me->id)->count())->toBe(0);
});

it('证据链：报案时把技能版本 / 交付物 / 设备 / 授权都冻结下来', function () {
    $customer = incCustomer();

    [$productId] = incSkillFixture($customer);
    [$deviceId, $bindingId] = incDevice($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_SEVERE,
        'description' => '手臂打到人',
        'skill_id'    => $productId,
        'device_id'   => $deviceId,
    ])->assertStatus(201);

    $incident = Incident::where('customer_id', $customer->id)->first();
    $trace = $incident->trace;

    expect($trace['skill_version']['version'])->toBe('1.0.0')
        ->and($trace['deliverable']['file_hash'])->toBe('hash-abc-123')
        ->and($trace['device']['model_name'])->toBe('测试机型')
        // 设备自报的固件优先于机型库的版本清单
        ->and($trace['device']['firmware'])->toBe('1.2.3')
        ->and($incident->device_binding_id)->toBe($bindingId);
});

it('证据链冻结：事后改设备型号，trace 里的快照不变', function () {
    $customer = incCustomer();

    [$productId] = incSkillFixture($customer);
    [$deviceId] = incDevice($customer, 'frozen_model_'.uniqid());

    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MODERATE,
        'description' => 'x',
        'skill_id'    => $productId,
        'device_id'   => $deviceId,
    ])->assertStatus(201);

    $incident = Incident::where('customer_id', $customer->id)->first();

    // 事后把机型改名（模拟运营维护机型库）
    DB::table('device_model')
        ->where('model_code', $incident->trace['device']['model_code'])
        ->update(['model_name' => '改过的名字']);

    $fresh = Incident::find($incident->id);

    expect($fresh->trace['device']['model_name'])->toBe('测试机型')
        ->and($fresh->trace['device']['model_name'])->not->toBe('改过的名字');
});

it('证据链：查不到的环节显式记进 missing，而不是悄悄省略', function () {
    $customer = incCustomer();
    actingAs($customer, 'customer');

    // 不指明技能、不指明设备
    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MINOR,
        'description' => 'x',
    ])->assertStatus(201);

    $incident = Incident::where('customer_id', $customer->id)->first();
    $missing = $incident->trace['missing'];

    expect($missing)->not->toBeEmpty();

    $joined = implode(' | ', $missing);

    expect($joined)->toContain('未指明技能')
        ->and($joined)->toContain('未指明设备');
});

it('A4 · 授权边界：skill_id 不在自己的授权里 → 报案被拒(不再挂到无关技能版本)', function () {
    $customer = incCustomer();

    // 造一个技能商品，但**不给**这个客户授权
    [$productId] = incSkillFixture(incCustomer());

    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MODERATE,
        'description' => 'x',
        'skill_id'    => $productId,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'SKILL_NOT_OWNED');

    expect(Incident::where('customer_id', $customer->id)->count())->toBe(0);
});

it('证据链：无授权依据时 traceFor 仍把版本标成"推断值"(服务层契约不变)', function () {
    $customer = incCustomer();

    // 造一个技能商品，但**不给**这个客户授权
    [$productId] = incSkillFixture(incCustomer());

    // A4 之后这条路径已不再经由 API 触发(API 层先拒),但 traceFor 的兜底契约不变:
    // 关联没有依据时,不静默断链、也不假装确凿,而是把版本标成"推断值"并写进 missing。
    $trace = app(\Webkul\Shop\Services\Incident\IncidentService::class)
        ->traceFor($customer->id, $productId, null);

    $joined = implode(' | ', $trace['missing']);

    expect($joined)->toContain('没有此技能的授权记录')
        ->and($joined)->toContain('不一定');
});

it('详情：不返回 trace 与内部处理记录', function () {
    $customer = incCustomer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MINOR,
        'description' => 'x',
    ]);

    $incident = Incident::where('customer_id', $customer->id)->first();

    // 模拟平台内部写了处理记录
    $incident->update(['handling_log' => [['note' => '内部讨论：疑似用户误操作']]]);

    $response = getJson(route('shop.api.incidents.show', $incident->id));

    $response->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('trace')
        ->and($data)->not->toHaveKey('handling_log')
        ->and(json_encode($data, JSON_UNESCAPED_UNICODE))->not->toContain('疑似用户误操作');
});

it('详情：只看得到自己的事故', function () {
    $me = incCustomer();
    $other = incCustomer();

    actingAs($other, 'customer');
    postJson(route('shop.api.incidents.store'), ['severity' => 'minor', 'description' => 'x']);

    $theirs = Incident::where('customer_id', $other->id)->first();

    actingAs($me, 'customer');

    getJson(route('shop.api.incidents.show', $theirs->id))->assertStatus(404);
});

// ==================== 严重事故的即时告警 ====================
//
// 仓库自己承认过这个缺口：`bootstrap/app.php` 的调度注释写着
// "真的需要分钟级响应的是严重事故 —— 那靠的是事故一上报就有即时通知"，
// 但"后续待办"四个字底下当时什么都没接。于是严重事故实际最快的通知路径是
// `ops:alert-open-incidents` 的小时级轮询再叠加 4 小时 SLA —— 对一个
// "这个技能刚把人撞了、可能还有第二个人正在用"的场景，沉默太久了。

it('严重事故：报案当场就通知在岗管理员（不等小时级轮询）', function () {
    $admin = \Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);

    $customer = incCustomer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_SEVERE,
        'description' => '机器人在产线上夹伤操作员右手',
    ])->assertStatus(201);

    $incident = Incident::where('customer_id', $customer->id)->first();

    $rows = DB::table('notification')
        ->where('recipient_type', 'admin')
        ->where('recipient_id', $admin->id)
        ->where('type', 'incident_new')
        ->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    // 未读 —— 它是待办，不是通知存档
    expect($row->read_at)->toBeNull()
        ->and($row->title)->toContain($incident->incident_no)
        ->and($row->title)->toContain('严重')
        // 正文要能直接判断要不要马上处理
        ->and($row->content)->toContain('机器人在产线上夹伤操作员右手');

    $payload = json_decode($row->payload, true);

    expect($payload['incident_id'])->toBe($incident->id)
        ->and($payload['level'])->toBe('warn');
});

it('重大事故：告警级别升到 urgent', function () {
    $admin = \Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);

    $customer = incCustomer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_CRITICAL,
        'description' => '重伤',
    ])->assertStatus(201);

    $payload = json_decode(DB::table('notification')
        ->where('recipient_type', 'admin')
        ->where('recipient_id', $admin->id)
        ->where('type', 'incident_new')
        ->value('payload'), true);

    expect($payload['level'])->toBe('urgent');
});

it('一般/轻微事故不发即时告警 —— 告警滥发等于没有告警', function () {
    $admin = \Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);

    $customer = incCustomer();
    actingAs($customer, 'customer');

    foreach ([Incident::SEVERITY_MINOR, Incident::SEVERITY_MODERATE] as $severity) {
        postJson(route('shop.api.incidents.store'), [
            'severity'    => $severity,
            'description' => 'x',
        ])->assertStatus(201);
    }

    expect(DB::table('notification')
        ->where('recipient_type', 'admin')
        ->where('recipient_id', $admin->id)
        ->where('type', 'incident_new')
        ->count())->toBe(0);
});

it('每起事故一条告警：去重键按事故走，两起事故不会被并成一条', function () {
    $admin = \Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);

    $customer = incCustomer();
    actingAs($customer, 'customer');

    foreach ([1, 2] as $i) {
        postJson(route('shop.api.incidents.store'), [
            'severity'    => Incident::SEVERITY_SEVERE,
            'description' => '第 '.$i.' 起',
        ])->assertStatus(201);
    }

    $keys = DB::table('notification')
        ->where('recipient_type', 'admin')
        ->where('recipient_id', $admin->id)
        ->where('type', 'incident_new')
        ->get()
        ->map(fn ($n) => json_decode($n->payload, true)['dedup_key'])
        ->all();

    expect($keys)->toHaveCount(2)
        ->and(array_unique($keys))->toHaveCount(2)
        // 与轮询命令的 `incident:open:{id}` 刻意不同：
        // "刚发生"和"过了时限还没人处理"是两件事，不该互相压制
        ->and($keys[0])->toStartWith('incident:')
        ->and($keys[0])->not->toStartWith('incident:open:');
});

// ==================== 造技能授权的辅助 ====================

/**
 * 造一个技能商品 + 版本 + 交付物 + 该客户的授权，返回 [productId, versionId]。
 */
function incSkillFixture(Customer $customer): array
{
    $productId = DB::table('products')->insertGetId([
        'sku'     => 'inc-sku-'.uniqid(),
        'type'    => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $versionId = DB::table('skill_versions')->insertGetId([
        'product_id' => $productId,
        'version'    => '1.0.0',
        'status'     => 'published',
        'released_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('skill_assets')->insert([
        'skill_version_id' => $versionId,
        'carrier_type'     => 'file',
        'file_path'        => 'skills/'.$productId.'/1.0.0/demo.skill',
        'file_name'        => 'demo.skill',
        'file_size'        => 1024,
        'file_hash'        => 'hash-abc-123',
        'sort_order'       => 0,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    DB::table('skill_entitlements')->insert([
        'customer_id'      => $customer->id,
        'order_id'         => 0,
        'order_item_id'    => 0,
        'product_id'       => $productId,
        'skill_version_id' => $versionId,
        'status'           => 'active',
        'license_key'      => substr(md5(uniqid()), 0, 32),
        'delivery_channel' => 'manual',
        'granted_at'       => now(),
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    return [$productId, $versionId];
}
