<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Services\Deploy\HttpPollAgentGateway;
use Webkul\Shop\Services\Deploy\MockAgentGateway;
use Webkul\Shop\Services\Ops\OpsMetricsService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 设备在线判定 —— 统一到**读时窗口**，不再读那个永不复位的粘滞列。
 *
 * 问题原状（本轮修）：`device_binding.online_status` 全仓只有四处写它，
 * **每一处都写 `true`，没有任何地方写 `false`**。也就是说一台三个月前就断线的设备，
 * 这一列至今还写着"在线"。而它有三个读点在用：
 *   `DeviceController`（我的设备）、`SkillController`（我的技能里的设备）、
 *   `OpsMetricsService`（运营总览的"在线设备数"）。
 *   同时 `HttpPollAgentGateway` 却用 `last_online_at` 的 90 秒窗口判断 ——
 *   两套判据必然不一致：**同一台设备，界面说在线，下发说离线**。
 *
 * 现在窗口常量与两种形态（单条 `isOnlineNow()` / 集合 `scopeOnlineNow()`）
 * 都只在 `DeviceBinding` 一处定义。
 *
 * ⚠ 有意**不动** `IncidentService::traceFor()`：事故快照记的是"当时库里写的是什么"，
 *   不是"现在是什么"。它冻结 sticky 值是对的，见文件末尾那条测试。
 */

// ==================== 造数据 ====================

function doCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Owner',
        'email'       => 'do'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 造一台"用户登记 + 已配对 Agent"的设备。
 *
 * @param  int|null  $lastSeenSecondsAgo  上次心跳距今秒数；null = 从未心跳过
 * @param  bool  $stickyOnline  粘滞列 online_status 的值（默认 true，模拟历史遗留）
 * @return array{0:int,1:string}  [deviceId, did]
 */
function doPairedDevice(Customer $customer, ?int $lastSeenSecondsAgo = null, bool $stickyOnline = true): array
{
    $modelCode = 'do_model_'.uniqid();

    DeviceModel::create([
        'brand'         => 'test',
        'model_code'    => $modelCode,
        'model_name'    => '测试机型',
        'comm_channels' => ['agent'],
        'status'        => DeviceModel::STATUS_ACTIVE,
    ]);

    $sn = 'SN'.uniqid();
    $did = 'did_'.uniqid();

    $bindingId = DB::table('device_binding')->insertGetId([
        'did'               => $did,
        'sn'                => $sn,
        'model_id'          => DeviceModel::where('model_code', $modelCode)->value('id'),
        'owner_customer_id' => $customer->id,
        'source'            => DeviceBinding::SOURCE_AGENT,
        'bind_status'       => 'bound',
        // ⚠ 粘滞列：写死 true，模拟"曾经连上过、此后再没写过 false"
        'online_status'     => $stickyOnline,
        'last_online_at'    => $lastSeenSecondsAgo === null ? null : now()->subSeconds($lastSeenSecondsAgo),
        'bound_at'          => now(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    $deviceId = DB::table('devices')->insertGetId([
        'customer_id'  => $customer->id,
        'sn'           => $sn,
        'binding_id'   => $bindingId,
        'model_code'   => $modelCode,
        'display_name' => '我的人形机器人',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return [$deviceId, $did];
}

/** 从「我的设备」接口取某台设备的 agent_online。 */
function doDeviceOnlineFromApi(Customer $customer, int $deviceId): bool
{
    actingAs($customer, 'customer');

    $data = getJson(route('shop.api.devices.index'))->assertOk()->json('data');

    $row = collect($data)->firstWhere('id', $deviceId);

    expect($row)->not->toBeNull();

    return (bool) $row['agent_online'];
}

// ==================== 核心回归 ====================

it('粘滞位的陷阱：online_status=true 但三小时没有心跳 → 界面显示**离线**', function () {
    $customer = doCustomer();
    [$deviceId] = doPairedDevice($customer, lastSeenSecondsAgo: 3 * 3600, stickyOnline: true);

    // 这正是改之前会显示"在线"的情形
    expect(doDeviceOnlineFromApi($customer, $deviceId))->toBeFalse();
});

it('刚心跳过 → 在线', function () {
    $customer = doCustomer();
    [$deviceId] = doPairedDevice($customer, lastSeenSecondsAgo: 5);

    expect(doDeviceOnlineFromApi($customer, $deviceId))->toBeTrue();
});

it('从未心跳过 → 离线（哪怕粘滞列写着 true）', function () {
    $customer = doCustomer();
    [$deviceId] = doPairedDevice($customer, lastSeenSecondsAgo: null, stickyOnline: true);

    expect(doDeviceOnlineFromApi($customer, $deviceId))->toBeFalse();
});

it('窗口边界：89 秒前算在线，91 秒前算离线', function () {
    // ⚠ 这条曾经是**闪红测试**，本轮修掉：窗口是 90 秒，而用例用 89 秒造"应当在线"的那台 ——
    //   从造数据到发 HTTP 断言只要超过 1 秒，它就真的掉出窗口了，断言随机失败。
    //
    //   而"随机失败"比"稳定失败"更糟：它会让**真正的回归淹没在噪声里**
    //   （见 docs/skill-test-baseline.md 的开篇）。冻结时间之后，边界仍然取
    //   89/91 秒这个**最贴近窗口**的位置，但结论不再取决于这台机器快不快。
    $this->travelTo(now());

    $customer = doCustomer();

    [$freshId] = doPairedDevice($customer, lastSeenSecondsAgo: 89);
    [$staleId] = doPairedDevice($customer, lastSeenSecondsAgo: 91);

    expect(doDeviceOnlineFromApi($customer, $freshId))->toBeTrue()
        ->and(doDeviceOnlineFromApi($customer, $staleId))->toBeFalse();
});

// ==================== 判据必须只有一处 ====================

it('判据一致：设备列表 / 轮询网关 / 模拟网关 对同一台设备给同一个答案', function () {
    $customer = doCustomer();

    [$freshId, $freshDid] = doPairedDevice($customer, lastSeenSecondsAgo: 10);
    [$staleId, $staleDid] = doPairedDevice($customer, lastSeenSecondsAgo: 3 * 3600);

    $poll = app(HttpPollAgentGateway::class);
    $mock = app(MockAgentGateway::class);

    // 在线的那台：三处都说在线
    expect(doDeviceOnlineFromApi($customer, $freshId))->toBeTrue()
        ->and($poll->isOnline($freshDid))->toBeTrue()
        ->and($mock->isOnline($freshDid))->toBeTrue();

    // 掉线的那台：三处都说离线（改之前这里是"界面在线、下发离线"）
    expect(doDeviceOnlineFromApi($customer, $staleId))->toBeFalse()
        ->and($poll->isOnline($staleDid))->toBeFalse()
        ->and($mock->isOnline($staleDid))->toBeFalse();
});

it('运营总览的「在线设备数」用的也是同一判据 —— 不把"曾经连过"算成在线', function () {
    $customer = doCustomer();

    // ⚠ 用增量断言，不断言绝对值：开发库里本来就有别的绑定行，
    //   断言"等于 1"会在别人的库上炸，而我要证明的是"这三台里只算了一台"。
    $before = app(OpsMetricsService::class)->devices()['agent_online']['value'];

    // 一台真的在线
    doPairedDevice($customer, lastSeenSecondsAgo: 10);
    // 两台"曾经连过、早已断线、粘滞列全是 true"
    doPairedDevice($customer, lastSeenSecondsAgo: 3 * 3600);
    doPairedDevice($customer, lastSeenSecondsAgo: 30 * 86400);

    $metric = app(OpsMetricsService::class)->devices()['agent_online'];

    expect($metric['value'])->toBe($before + 1)
        // 口径必须写明是"有窗口的心跳"，否则读的人会以为这是"配对过的设备数"
        ->and($metric['basis'])->toContain('心跳')
        ->and($metric['basis'])->toContain((string) DeviceBinding::ONLINE_WINDOW_SECONDS);
});

it('绑定总数与在线数是两个不同的数 —— 别把前者读成后者', function () {
    $customer = doCustomer();
    doPairedDevice($customer, lastSeenSecondsAgo: 10);
    doPairedDevice($customer, lastSeenSecondsAgo: 3 * 3600);

    $devices = app(OpsMetricsService::class)->devices();

    // 一个是总量、一个是"此刻连着的"，口径不同就不能混着读
    expect($devices['bindings']['value'])->toBeGreaterThan($devices['agent_online']['value'])
        ->and($devices['bindings']['basis'])->not->toBe($devices['agent_online']['basis']);
});

// ==================== 第 5 个读点：兼容性校验 ====================

/** 造一个声明了 agent 通道的已发布技能，返回 productId。 */
function doAgentSkill(): int
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'do-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('skill_versions')->insert([
        'product_id'        => $productId,
        'version'           => '1.0.0',
        'status'            => 'published',
        'delivery_channels' => json_encode(['agent', 'manual']),
        'released_at'       => now(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    return $productId;
}

/** 取兼容性校验的 notes（拼成一串，方便断言）。 */
function doCompatNotes(Customer $customer, int $productId, int $deviceId): string
{
    $result = app(\Webkul\Shop\Services\SkillDelivery\CompatibilityService::class)
        ->check($customer->id, $productId, $deviceId);

    return implode(' | ', $result['notes']);
}

it('兼容性校验：设备在线时**不再**说"设备当前离线"（此前那句话是硬编码的恒定输出）', function () {
    $customer = doCustomer();
    [$deviceId] = doPairedDevice($customer, lastSeenSecondsAgo: 10);
    $productId = doAgentSkill();

    expect(doCompatNotes($customer, $productId, $deviceId))->not->toContain('设备当前离线');
});

it('兼容性校验：设备真离线时提示离线 —— 但只是提示，不是拒绝', function () {
    $customer = doCustomer();
    [$deviceId] = doPairedDevice($customer, lastSeenSecondsAgo: 3 * 3600, stickyOnline: true);
    $productId = doAgentSkill();

    $result = app(\Webkul\Shop\Services\SkillDelivery\CompatibilityService::class)
        ->check($customer->id, $productId, $deviceId);

    expect(implode(' | ', $result['notes']))->toContain('设备当前离线')
        // 离线不改变"兼容"的结论 —— 挂起下发才是正确处置，不是让用户装不了
        ->and($result['compatible'])->toBeTrue();
});

// ==================== 下发链路也读同一个判据 ====================

it('下发：设备只有粘滞位为 true、实际早已掉线 → 任务挂起为 pending_online 而不是"已下发"', function () {
    $customer = doCustomer();
    [, $did] = doPairedDevice($customer, lastSeenSecondsAgo: 3 * 3600, stickyOnline: true);

    $task = app(\Webkul\Shop\Services\Deploy\DeployTaskService::class)->create(
        did: $did,
        skillId: 1,
        skillVersionId: null,
        entitlementId: null,
        operation: 1,          // 1 = 安装（见迁移里的 operation 语义注释）
        channel: 'agent',
        createdBy: 'test',
    );

    // 设备没在轮询，指令发不出去 —— 如实挂起，等它上线自己来取。
    // 改之前：粘滞列写着 true → 判为在线 → 直接标成 dispatching（"已下发"），
    // 而那条指令永远不会有人来取。
    expect($task->status)->toBe('pending_online');
});

// ==================== 刻意不动的那一处 ====================

it('事故快照不受影响：trace 记的仍是报案时库里的原始值（冻结语义优先于"读时真相"）', function () {
    $customer = doCustomer();
    [$deviceId] = doPairedDevice($customer, lastSeenSecondsAgo: 3 * 3600, stickyOnline: true);

    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MODERATE,
        'description' => 'x',
        'device_id'   => $deviceId,
    ])->assertStatus(201);

    $trace = Incident::where('customer_id', $customer->id)->first()->trace;

    // 快照里 sticky 值原样保留 —— 调查者看到的是"当时库里写的是什么"。
    // 真信号在同一份快照的 last_online_at 里，两者并排放着，不需要谁来"修正"历史。
    // （快照存的是**原始列值**，不是模型 cast 后的布尔 —— 所以这里断言 1 而不是 true）
    expect($trace['device']['binding']['online_status'])->toBe(1)
        ->and($trace['device']['binding']['last_online_at'])->not->toBeNull();
});
