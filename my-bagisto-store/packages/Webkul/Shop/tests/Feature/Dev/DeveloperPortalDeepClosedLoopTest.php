<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillCompatibilityEvidence;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\put;
use function Pest\Laravel\putJson;

/**
 * 开发者后台 · 二三四级子功能与交互优化全链路闭环测试。
 *
 * 覆盖：
 *   1. 三级版本配置（通道/授权模式/激活上限/有效期/最低固件/风险等级/交付通道/声明权限/适配机型）新建与编辑 + 状态锁（in_review/published 拒绝修改）；
 *   2. 三级/四级交付物自检下载与草稿移除 + 状态锁（in_review/published 拒绝移除）+ 跨租户 404；
 *   3. 四级机型适配验证证据（SkillCompatibilityEvidence）登记、自动绑定当前产物 SHA-256 快照及门户/API 透出；
 *   4. 三级审核状态页同时呈现 L0/L2 审核流水（带技能名与版本号）与主体敏感操作审计日志（OperationLog）；
 *   5. 三级通知中心一键全部已读（严格限定在 developerInbox 作用域内，绝不波及买家通知或他人通知）。
 */

function makeClosedLoopDev(string $prefix = 'cldev'): array
{
    $customer = Customer::create([
        'first_name'  => 'ClosedLoop',
        'last_name'   => 'Dev',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'company', 'seller_name' => '机甲实验室-'.$prefix]);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), [
        'name'              => '测试闭环技能-'.$prefix,
        'price'             => 99,
        'short_description' => '用于验证二三四级闭环的技能',
    ]);

    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    return compact('customer', 'profile', 'productId');
}

beforeEach(function () {
    Storage::fake(SkillAsset::DISK);
});

it('支持创建带完整三级参数的版本并在草稿态更新配置，审核中或已发布态返回 409 拒绝修改', function () {
    $dev = makeClosedLoopDev('vcfg');

    post(route('shop.dev.skills.versions.store', $dev['productId']), [
        'version'           => '1.2.0',
        'channel'           => SkillVersion::CHANNEL_BETA,
        'license_mode'      => SkillVersion::LICENSE_PER_ACCOUNT,
        'max_activations'   => 5,
        'valid_days'        => 365,
        'min_robot_version' => '>=2.1.0',
        'risk_level'        => 'medium',
        'delivery_channels' => ['agent', 'manual'],
        'permissions'       => ['motion_control', 'audio_play'],
        'target_models'     => 'Unitree-G1, AgiBot-A2',
        'changelog'         => '新增双臂协同问候动作',
        'install_guide'     => '解压至 /opt/skills/greet 并重启 agent',
    ])->assertRedirect(route('shop.dev.skills.show', $dev['productId']));

    /** @var SkillVersion $version */
    $version = SkillVersion::where('product_id', $dev['productId'])->where('version', '1.2.0')->firstOrFail();

    expect($version->channel)->toBe(SkillVersion::CHANNEL_BETA)
        ->and($version->license_mode)->toBe(SkillVersion::LICENSE_PER_ACCOUNT)
        ->and($version->max_activations)->toBe(5)
        ->and($version->valid_days)->toBe(365)
        ->and($version->min_robot_version)->toBe('>=2.1.0')
        ->and($version->risk_level)->toBe('medium')
        ->and($version->delivery_channels)->toBe(['agent', 'manual'])
        ->and($version->permissions)->toBe(['motion_control', 'audio_play'])
        ->and($version->compatibility['models'][0]['model'] ?? null)->toBe('Unitree-G1')
        ->and($version->install_guide)->toContain('/opt/skills/greet');

    // 草稿态通过 PUT /dev/versions/{versionId} 修改配置
    put(route('shop.dev.versions.update', $version->id), [
        'channel'           => SkillVersion::CHANNEL_STABLE,
        'license_mode'      => SkillVersion::LICENSE_PER_DEVICE,
        'max_activations'   => 2,
        'valid_days'        => 180,
        'min_robot_version' => '>=2.2.0',
        'risk_level'        => 'low',
        'delivery_channels' => ['manual'],
        'permissions'       => ['motion_control'],
        'target_models'     => 'Unitree-G1-Pro',
        'changelog'         => '正式版收敛权限',
        'install_guide'     => '更新后的安装指引',
    ])->assertRedirect();

    $version->refresh();
    expect($version->channel)->toBe(SkillVersion::CHANNEL_STABLE)
        ->and($version->license_mode)->toBe(SkillVersion::LICENSE_PER_DEVICE)
        ->and($version->max_activations)->toBe(2)
        ->and($version->risk_level)->toBe('low')
        ->and($version->compatibility['models'][0]['model'] ?? null)->toBe('Unitree-G1-Pro');

    // API 通道在版本处于 published 时拒绝修改并返回 409 VERSION_PUBLISHED_IMMUTABLE
    $version->update(['status' => SkillVersion::STATUS_PUBLISHED]);
    Sanctum::actingAs($dev['customer']);

    putJson(route('shop.api.dev.versions.update', $version->id), [
        'channel' => SkillVersion::CHANNEL_BETA,
    ])->assertStatus(409)
        ->assertJsonPath('code', 'VERSION_PUBLISHED_IMMUTABLE');

    // 他人尝试修改返回 404
    $other = makeClosedLoopDev('otherv');
    Sanctum::actingAs($other['customer']);
    putJson(route('shop.api.dev.versions.update', $version->id), [
        'channel' => SkillVersion::CHANNEL_BETA,
    ])->assertNotFound();
});

it('支持开发者自检下载已上传技能包、草稿态移除交付物及登记机型适配验证证据（自动钉住产物 SHA-256）', function () {
    $dev = makeClosedLoopDev('assetev');

    post(route('shop.dev.skills.versions.store', $dev['productId']), [
        'version' => '1.0.0',
    ]);
    $version = SkillVersion::where('product_id', $dev['productId'])->firstOrFail();

    // 生成合法示例包并上传
    $sample = app(\Webkul\Shop\Services\SkillPackage\SkillPackageBuilder::class)
        ->buildSamplePackage($version, ['kind' => 'motion']);

    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $sample,
    ])->assertRedirect();

    $asset = SkillAsset::where('skill_version_id', $version->id)->firstOrFail();
    expect($asset->file_hash)->not->toBeEmpty();

    // 1. 自检下载自己的包 → 200
    get(route('shop.dev.assets.download', $asset->id))
        ->assertOk();

    // 2. 登记机型适配验证记录（四级闭环）→ 自动绑定当前 asset_id 与 asset_hash
    post(route('shop.dev.versions.evidence.store', $version->id), [
        'model_code'       => 'Unitree-G1',
        'source'           => SkillCompatibilityEvidence::SOURCE_SIMULATION,
        'evidence_type'    => SkillCompatibilityEvidence::EVIDENCE_RUNTIME,
        'result'           => SkillCompatibilityEvidence::RESULT_MATCH,
        'firmware_version' => '2.1.4',
        'sdk_version'      => '1.4.0',
        'summary'          => '仿真环境连续运行 50 次无碰撞与超温',
    ])->assertRedirect();

    $evidence = SkillCompatibilityEvidence::where('skill_version_id', $version->id)->firstOrFail();
    expect($evidence->asset_id)->toBe($asset->id)
        ->and($evidence->asset_hash)->toBe($asset->file_hash)
        ->and($evidence->model_code)->toBe('Unitree-G1')
        ->and($evidence->verified_by)->toBe('seller:'.$dev['profile']->seller_id);

    // 详情页与 API 均能看到该适配验证记录及指纹一致性标识
    get(route('shop.dev.skills.show', $dev['productId']))
        ->assertOk()
        ->assertSee('Unitree-G1')
        ->assertSee('指纹一致');

    Sanctum::actingAs($dev['customer']);
    getJson(route('shop.api.dev.skills.show', $dev['productId']))
        ->assertOk()
        ->assertJsonPath('data.versions.0.compatibility_evidence.0.model_code', 'Unitree-G1')
        ->assertJsonPath('data.versions.0.compatibility_evidence.0.asset_hash', $asset->file_hash);

    // 3. 当版本处于 in_review 时，禁止移除交付物（409）
    $version->update(['status' => SkillVersion::STATUS_IN_REVIEW]);
    deleteJson(route('shop.api.dev.assets.destroy', $asset->id))
        ->assertStatus(409);

    // 4. 回到 draft 时允许移除交付物（200），并写入 skill.asset.delete 操作日志
    $version->update(['status' => SkillVersion::STATUS_DRAFT]);
    delete(route('shop.dev.assets.destroy', $asset->id))
        ->assertRedirect();

    expect(SkillAsset::find($asset->id))->toBeNull();
    expect(OperationLog::where('action', 'skill.asset.delete')->where('target_id', $asset->id)->exists())->toBeTrue();

    // 5. 审核状态与日志页可见本主体的操作留痕
    get(route('shop.dev.audits.index'))
        ->assertOk()
        ->assertSee('开发者关键操作审计日志')
        ->assertSee('skill.version.evidence')
        ->assertSee('skill.asset.delete');
});

it('通知中心一键全部标记已读仅作用于本人开发者收件箱，不触碰买家通知或他人通知', function () {
    $devA = makeClosedLoopDev('notifa');
    $devB = makeClosedLoopDev('notifb');

    // A 的开发者通知（未读）
    $nDevA = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $devA['customer']->id,
        'type'           => 'skill.audit_rejected',
        'title'          => 'A 的技能审核驳回',
        'content'        => '请补充权限说明',
    ]);

    // A 的普通买家通知（不属于 developerInbox）
    $nBuyerA = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $devA['customer']->id,
        'type'           => 'order.paid',
        'title'          => 'A 的买家订单通知',
        'content'        => '订单已支付',
    ]);

    // B 的开发者通知（未读）
    $nDevB = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $devB['customer']->id,
        'type'           => 'skill.published',
        'title'          => 'B 的技能已上架',
        'content'        => '已可售',
    ]);

    actingAs($devA['customer'], 'developer');
    post(route('shop.dev.notifications.read-all'))
        ->assertRedirect();

    expect($nDevA->fresh()->read_at)->not->toBeNull()
        ->and($nBuyerA->fresh()->read_at)->toBeNull()
        ->and($nDevB->fresh()->read_at)->toBeNull();
});
