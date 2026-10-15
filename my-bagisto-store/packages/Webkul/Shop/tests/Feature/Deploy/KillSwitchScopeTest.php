<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * 任务卡 S-5 · 熔断口径校准 + 人工通道安装可定位。
 *
 * D9 原话是"熔断一键禁用已安装设备",但事实有三处约束(见 KillSwitchService 类注释):
 *   ① 对新下发即时生效;
 *   ② 对已装设备只在**该设备下次上线**时经 agent 生效(传输是 HTTP 拉);
 *   ③ 人工通道安装的设备**无法自动卸载**(无 agent 回程)。
 *
 * 本文件把这三类钉死成可执行的断言,并锁住 `install_record.channel` 的写入口径:
 *   1. 在线设备 → 卸载任务立即进入可拉取状态(不是 pending_online);
 *   2. 离线设备 → 任务落 pending_online,统计里离线 +1、在线不加;
 *   3. 人工通道安装 → 计为"无法自动卸载",且**不**建 agent 卸载任务;
 *   4. C 端自报安装 → install_record.channel = 该授权的 delivery_channel;
 *   5. 历史 NULL 行不被回填(把"不知道"说成"人工"是伪造事实)。
 */

// ==================== 造数据 ====================

function ksScopeProductId(): int
{
    return 920000 + random_int(1, 9999);
}

function ksScopeVersion(int $productId): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

/** 已确权设备(带 binding)。$online 决定在线判定(isOnlineNow 读 last_online_at)。 */
function ksScopeBinding(bool $online): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_s5_'.uniqid(),
        'sn'             => 'S5-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        // 在线 = 落在 DeviceBinding::ONLINE_WINDOW_SECONDS(90s)窗口内
        'last_online_at' => $online ? now() : now()->subHours(3),
    ]);
}

function ksScopeInstall(DeviceBinding $binding, int $versionId, ?string $channel): InstallRecord
{
    return InstallRecord::create([
        'did'              => $binding->did,
        'skill_version_id' => $versionId,
        'status'           => 'installed',
        'channel'          => $channel,
        'installed_at'     => now(),
    ]);
}

function ksScopeEngage(int $skillId): array
{
    return app(KillSwitchService::class)->engage(
        $skillId,
        SkillBlocklist::ACTION_FORCE_UNINSTALL,
        'pest:s5-scope',
        'high',
    );
}

// ==================== 1 · 在线设备:即时 ====================

it('S-5 · 在线设备的卸载任务立即进入可被拉取的状态(不是 pending_online)', function () {
    $productId = ksScopeProductId();
    $version = ksScopeVersion($productId);
    $binding = ksScopeBinding(online: true);
    ksScopeInstall($binding, $version->id, 'agent');

    $res = ksScopeEngage($productId);

    // 建了一条卸载任务,且**当下**就可被设备取走(dispatching),不是挂起待上线
    $task = DeployTask::query()
        ->where('did', $binding->did)
        ->where('operation', DeployTask::OP_UNINSTALL)
        ->latest('id')
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->status)->toBe('dispatching');

    // 统计口径:在线 +1,离线与"无法自动卸载"都不得虚增
    expect($res['forced_online'])->toBe(1)
        ->and($res['forced_offline'])->toBe(0)
        ->and($res['unable_auto_uninstall'])->toBe(0)
        ->and($res['forced_tasks'])->toBe(1);
});

// ==================== 2 · 离线设备:待下次上线 ====================

it('S-5 · 离线设备的卸载任务落 pending_online,统计离线 +1、在线不加', function () {
    $productId = ksScopeProductId();
    $version = ksScopeVersion($productId);
    $binding = ksScopeBinding(online: false);
    ksScopeInstall($binding, $version->id, 'agent');

    $res = ksScopeEngage($productId);

    $task = DeployTask::query()
        ->where('did', $binding->did)
        ->where('operation', DeployTask::OP_UNINSTALL)
        ->latest('id')
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->status)->toBe('pending_online');

    expect($res['forced_offline'])->toBe(1)
        ->and($res['forced_online'])->toBe(0)
        ->and($res['unable_auto_uninstall'])->toBe(0);
});

// ==================== 3 · 人工通道:无法自动卸载 ====================

it('S-5 · 人工通道安装的设备计为"无法自动卸载",且不建 agent 卸载任务', function () {
    $productId = ksScopeProductId();
    $version = ksScopeVersion($productId);
    $binding = ksScopeBinding(online: true);
    // 人工通道交付的安装：channel='app'
    ksScopeInstall($binding, $version->id, 'app');

    $res = ksScopeEngage($productId);

    // 如实计入"无法自动卸载" —— 而不是假装建了任务
    expect($res['unable_auto_uninstall'])->toBe(1)
        ->and($res['forced_tasks'])->toBe(0)
        ->and($res['forced_online'])->toBe(0)
        ->and($res['forced_offline'])->toBe(0);

    // 关键:根本没有为这台设备建任何卸载任务(建了也拉不动)
    expect(DeployTask::query()
        ->where('did', $binding->did)
        ->where('operation', DeployTask::OP_UNINSTALL)
        ->exists())->toBeFalse();
});

// ==================== 4 · 自报安装落真实通道 ====================

it('S-5 · C 端自报安装 → install_record.channel 与该授权的 delivery_channel 一致', function () {
    $customer = Customer::create([
        'first_name'  => 'S5',
        'last_name'   => 'Scope',
        'email'       => 's5'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => 'S5D-'.uniqid(),
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);

    $versionId = 920000 + random_int(1, 90000);

    // 故意用一个**非默认**通道值,证明落的是授权上的真实值,不是硬编码的 'app'
    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 920000 + random_int(1, 90000),
        'order_item_id'    => 920000 + random_int(1, 90000),
        'product_id'       => 920000 + random_int(1, 9999),
        'skill_version_id' => $versionId,
        'device_id'        => $device->id,
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'vendor:acme',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk();

    // 人工通道真相源(heal 时 binding.did = sn_{sn})
    $did = 'sn_'.$device->sn;

    $record = InstallRecord::query()
        ->where('did', $did)
        ->where('skill_version_id', $versionId)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->channel)->toBe('vendor:acme')
        ->and($record->entitlement_id)->toBe($entitlement->id);
});

// ==================== 5 · 历史 NULL 不被回填 ====================

it('S-5 · 历史 channel=null 的行:跑熔断后仍是 null(不冒充人工通道)', function () {
    $productId = ksScopeProductId();
    $version = ksScopeVersion($productId);
    $binding = ksScopeBinding(online: true);
    $record = ksScopeInstall($binding, $version->id, null);

    $res = ksScopeEngage($productId);

    // 通道未知 → 归入"无法自动卸载"(无从判断有回程),但**不**回填成 'manual'/'app' 冒充事实
    expect($res['unable_auto_uninstall'])->toBe(1)
        ->and($res['forced_tasks'])->toBe(0);

    expect(InstallRecord::find($record->id)->channel)->toBeNull();
});
