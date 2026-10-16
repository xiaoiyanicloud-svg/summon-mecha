<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillDelivery\RiskDisclosureService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/**
 * D-8（改判版）· 权限声明**可见化** —— 不做强制，只做"让用户看得见"。
 *
 * 背景（已侦察否决的"强制"路线，别再回来补）：
 *   `manifest.permissions` 原先在 L0 只做白名单校验，**校验完即丢**（skill_versions 无对应列）。
 *   原想"下发前校验：声明权限 ⊆ 设备能力"—— 但 `device_model` 表**没有任何能力/权限字段**
 *   （只有 comm_channels / degrees_of_freedom / compute_platform / firmware_versions /
 *   min_agent_version），没有可比对的真实数据源；做下去只能是"自造一套能力词表去校验
 *   另一套自造词表"。故本卡**只做可见化**：落库 + 让用户在下单前看得见。
 *
 * 本文件锁住四件事 + 一条反向对照：
 *   ① 开发者提审 → 声明的 permissions 真落库（端到端，走门户链路）；
 *   ② 技能详情接口返回 permissions 原始值 + 人话标签（断言接口 JSON，不是断言变量）；
 *   ③ 风险告知里出现**人话**（与 PERMISSION_LABELS 一致），**不含**原始枚举串；
 *   ④ 未声明权限的版本：详情接口与风险告知**都不出现**权限段（不凭空造占位）；
 *   ⑤ 反向对照：既有 low/medium/high 分级文案未被改坏。
 */

// ==================== 夹具（门户链路，函数名带 perm 前缀避免与同目录其它文件撞名）====================

/** 造一个合法 .skill 包；$overrides 覆盖 manifest 顶层字段。 */
function permPackage(array $overrides = []): UploadedFile
{
    $manifest = array_merge([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '权限可见化技能'],
        'version'           => '1.0.0',
        'delivery_channels' => ['agent', 'manual'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'         => 'medium',
            'requires_open_area' => true,
            'estop_required'     => true,
            'max_duration_s'     => 30,
        ],
        'pricing_mode'      => 'buyout',
    ], $overrides);

    $path = tempnam(sys_get_temp_dir(), 'skill').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
    $zip->addFromString('README.txt', 'demo package');
    $zip->close();

    return new UploadedFile($path, 'perm-demo.skill', 'application/zip', null, true);
}

/** 走真实门户链路完成「入驻 → 签协议 → 实名通过」。 */
function permDeveloper(string $prefix = 'perm'): array
{
    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Permissions',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    return [$customer, $profile->fresh()];
}

/** 门户建一个技能商品，返回真实商品 id。 */
function permSkill(string $name): int
{
    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 199])->assertRedirect();

    return (int) DB::table('products')->orderByDesc('id')->value('id');
}

/** 门户建一个版本，返回版本模型。 */
function permVersion(int $productId): SkillVersion
{
    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0'])->assertRedirect();

    return SkillVersion::where('product_id', $productId)->orderByDesc('id')->firstOrFail();
}

/** 门户上传技能包（真包，落盘）+ 提审（跑 L0 机审 → 触发声明落库）。 */
function permUploadAndSubmit(SkillVersion $version, array $manifestOverrides = []): void
{
    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => permPackage($manifestOverrides),
    ])->assertRedirect();

    post(route('shop.dev.versions.submit', $version->id))->assertRedirect();
}

/** 直插一个商品（读取路径的用例只需要它存在）。 */
function permSeedProduct(): int
{
    return (int) DB::table('products')->insertGetId([
        'sku'        => 'perm-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** 直插一个已发布版本（读取路径的用例只需版本在库）。 */
function permSeedVersion(int $productId, array $extra = []): int
{
    return (int) DB::table('skill_versions')->insertGetId(array_merge([
        'product_id'  => $productId,
        'version'     => '1.0.0',
        'channel'     => 'stable',
        'status'      => 'published',
        'released_at' => now(),
        'created_at'  => now(),
        'updated_at'  => now(),
    ], $extra));
}

beforeEach(function () {
    Storage::fake(SkillAsset::disk());
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

// ==================== ① 端到端：声明落库 ====================

it('端到端：开发者提审声明 permissions 的包 → skill_versions.permissions 不再是 NULL 且与 manifest 一致', function () {
    permDeveloper();
    $productId = permSkill('权限落库技能');
    $version = permVersion($productId);

    // 提审前恒 NULL —— 正是本卡要修掉的缺口
    expect($version->fresh()->permissions)->toBeNull();

    permUploadAndSubmit($version, ['permissions' => ['motion_control', 'camera']]);

    $after = $version->fresh();

    expect($after->permissions)->toBe(['motion_control', 'camera']);
});

// ==================== ② 技能详情接口：原始值 + 人话标签 ====================

it('技能详情接口返回 permissions 原始值 + 人话标签（只加键）', function () {
    $productId = permSeedProduct();
    permSeedVersion($productId, ['permissions' => json_encode(['motion_control', 'camera'])]);

    $row = collect(getJson(route('shop.api.skill-catalog.versions', $productId))->assertOk()->json('data'))->first();

    expect($row['permissions'])->toBe(['motion_control', 'camera'])
        ->and($row['permission_labels'])->toBe(['运动控制', '摄像头'])
        // 既有键未被这次改动碰坏（术语纪律：发布通道 stable/beta）
        ->and($row['channel'])->toBe('stable')
        ->and($row['channel_label'])->toBe('正式版');
});

// ==================== ③ 风险告知：人话，不含原始枚举 ====================

it('风险告知里出现人话权限词，不含原始枚举串', function () {
    $productId = permSeedProduct();
    permSeedVersion($productId, [
        'risk_level'  => 'medium',
        'permissions' => json_encode(['motion_control', 'camera']),
    ]);

    $profile = app(RiskDisclosureService::class)->profile($productId);

    expect($profile)->not->toBeNull()
        // 人话（与 PERMISSION_LABELS 一致）
        ->and($profile['summary'])->toContain('运动控制')
        ->and($profile['summary'])->toContain('摄像头')
        // 不含原始枚举 / 英文码 —— 用户不该看到 camera, motion_control
        ->and($profile['summary'])->not->toContain('motion_control')
        ->and($profile['summary'])->not->toContain('camera')
        // 分档语义没被权限并入搞坏：仍是 medium 那一档
        ->and($profile['risk_level'])->toBe('medium')
        ->and($profile['label'])->toBe('中风险')
        ->and($profile['require_checklist'])->toBeFalse();
});

// ==================== ④ 未声明权限：不出现权限段 ====================

it('未声明权限的版本 → 详情接口与风险告知都不出现权限段', function () {
    $productId = permSeedProduct();
    permSeedVersion($productId, ['risk_level' => 'low']);

    $row = collect(getJson(route('shop.api.skill-catalog.versions', $productId))->assertOk()->json('data'))->first();

    expect($row['permissions'])->toBe([])
        ->and($row['permission_labels'])->toBe([]);

    $profile = app(RiskDisclosureService::class)->profile($productId);

    // 不凭空造一句"未声明权限"占位
    expect($profile['summary'])->not->toContain('该技能运行时会涉及')
        ->and($profile['summary'])->toBe('该技能为常规动作，但仍需在设备可安全活动的区域内运行。');
});

// ==================== ⑤ 反向对照：既有分级文案未被改坏 ====================

it('反向对照：low/medium/high 既有分级文案与勾选项未被权限改动碰坏', function () {
    $cases = [
        'low'    => ['label' => '低风险', 'checklist' => false, 'items' => 0],
        'medium' => ['label' => '中风险', 'checklist' => false, 'items' => 2],
        'high'   => ['label' => '高风险', 'checklist' => true,  'items' => 3],
    ];

    foreach ($cases as $level => $expect) {
        $productId = permSeedProduct();
        permSeedVersion($productId, ['risk_level' => $level]);

        $profile = app(RiskDisclosureService::class)->profile($productId);

        expect($profile['risk_level'])->toBe($level)
            ->and($profile['label'])->toBe($expect['label'])
            ->and($profile['require_checklist'])->toBe($expect['checklist'])
            ->and($profile['items'])->toHaveCount($expect['items'])
            // 无权限声明 → 不追加权限段（基线文案未被本卡改动污染）
            ->and($profile['summary'])->not->toContain('该技能运行时会涉及');
    }

    // 未分级兜底也仍在（不臆造成 low）
    $unknownProduct = permSeedProduct();
    permSeedVersion($unknownProduct, ['risk_level' => null]);

    $unknown = app(RiskDisclosureService::class)->profile($unknownProduct);

    expect($unknown['risk_level'])->toBeNull()
        ->and($unknown['label'])->toBe('未分级');
});
