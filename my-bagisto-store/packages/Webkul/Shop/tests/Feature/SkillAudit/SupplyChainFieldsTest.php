<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillDelivery\RiskDisclosureService;
use Webkul\User\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * 供给侧字段与闸门的正确性（供需深度优化 第 1 步 · 任务卡 S-2）。
 *
 * 两件事，同一主题：**开发者声明的东西必须真的生效；闸门必须真的拦得住不可交付的东西。**
 *
 *   ① 开发者 manifest 里声明的 `delivery_channels` / `safety.risk_level` / `pricing_mode`
 *      原先被**静默丢弃**（全仓唯一写这几列的地方是 Admin 导入路径，开发者提审不写）——
 *      于是 `delivery_channels` 恒 NULL 让 **TrialService 的试用硬闸永远不过**
 *      （开发者技能永远不能试用），`risk_level` 恒 NULL 让风险告知退化成"未分级"。
 *   ② 发布前置原先只查"有没有交付物"，不查"交付物拿不拿得到" —— 一个声明为文件、
 *      磁盘上却没有的版本照样能上架，用户付钱后下载拿到"文件缺失"。
 *
 * 断言一律**走真实链路**（门户提审 / internal.key 发布 / C 端试用接口），
 * 因为字段写对了不等于功能真的生效 —— 那正是本卡要修的病。
 */

// ==================== 夹具 ====================

/** 造一个合法 .skill 包；$overrides 覆盖 manifest 顶层字段。 */
function scfPackage(array $overrides = []): UploadedFile
{
    $manifest = array_merge([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '供给链字段技能'],
        'version'           => '1.0.0',
        // 声明了在线通道 —— 试用硬闸（TrialService）认的就是这一项
        'delivery_channels' => ['agent', 'manual'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'         => 'medium',
            'requires_open_area' => true,
            'estop_required'     => true,
            'max_duration_s'     => 30,
        ],
        // D3：买断制走 pricing_mode，**不**是 license_mode
        'pricing_mode'      => 'buyout',
    ], $overrides);

    $path = tempnam(sys_get_temp_dir(), 'skill').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
    $zip->addFromString('README.txt', 'demo package');
    $zip->close();

    return new UploadedFile($path, 'scf-demo.skill', 'application/zip', null, true);
}

/**
 * 走真实门户链路完成「入驻 → 签协议 → 实名通过」，返回 [customer, profile]。
 *
 * 实名通过（D-5）是本文件要走到**发布**的那几个用例所必需的前置 —— 补合格，
 * 而不是把前置弱化掉。实名审核动作属平台端，测试里直接改库置 approved。
 */
function scfDeveloper(string $prefix = 'scf'): array
{
    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Supply',
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

/** 门户建一个技能商品，返回真实商品 id（发布要把商品置为可售，商品必须真实存在）。 */
function scfSkill(string $name): int
{
    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 199])->assertRedirect();

    return (int) DB::table('products')->orderByDesc('id')->value('id');
}

/** 门户建一个版本，返回版本模型。 */
function scfVersion(int $productId): SkillVersion
{
    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0'])->assertRedirect();

    return SkillVersion::where('product_id', $productId)->orderByDesc('id')->firstOrFail();
}

/** 门户上传技能包（真包，落盘）+ 提审（跑 L0 机审）。 */
function scfUploadAndSubmit(SkillVersion $version, array $manifestOverrides = []): void
{
    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => scfPackage($manifestOverrides),
    ])->assertRedirect();

    post(route('shop.dev.versions.submit', $version->id))->assertRedirect();
}

/** L2 人审判通过（发布闸门要求的第二条轴）。 */
function scfApproveL2(int $productId, int $versionId): void
{
    app(AuditService::class)->recordLayer2($productId, $versionId, AuditRecord::RESULT_PASS, null);
}

/** 走 internal.key 通道发布（与运营后台共用同一条 SkillPublisher 闸门）。 */
function scfPublish(int $versionId)
{
    return post(route('shop.api.skill-admin.versions.publish', $versionId), [], [
        'X-Internal-Key' => 'test-internal-key',
        'Accept'         => 'application/json',
    ]);
}

/** 造一台**已确权**设备（试用要真下发，未确权设备发不出去）。 */
function scfPairedDevice(Customer $customer): Device
{
    $sn = 'SCF-'.uniqid();

    $device = Device::create([
        'customer_id' => $customer->id,
        'sn'          => $sn,
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);

    $binding = DeviceBinding::create([
        'did'               => 'did_scf_'.uniqid(),
        'sn'                => $sn,
        'source'            => DeviceBinding::SOURCE_AGENT,
        'owner_customer_id' => $customer->id,
        'devices_id'        => $device->id,
        'bind_status'       => DeviceBinding::BIND_STATUS_BOUND,
        'bound_at'          => now(),
        'last_online_at'    => now(),
    ]);

    $device->binding_id = $binding->id;
    $device->save();

    return $device;
}

function scfFlatStatus(int $productId): ?int
{
    $status = DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('locale', app()->getLocale())
        ->value('status');

    return $status === null ? null : (int) $status;
}

function scfSearchIds(string $q): array
{
    $res = getJson(route('shop.api.skill-catalog.search', ['q' => $q]))->assertOk();

    return collect($res->json('data'))->pluck('id')->map('intval')->all();
}

function scfAdmin(): Admin
{
    return Admin::factory()->create(['role_id' => 1]);
}

beforeEach(function () {
    // 交付物走假盘：上传与发布的可交付性校验都以真实文件为准。
    Storage::fake(SkillAsset::disk());
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

// ==================== ① 声明字段落库 ====================

it('开发者提审 → manifest 声明的 delivery_channels / risk_level / pricing_mode 落库（原先恒 NULL）', function () {
    [, $profile] = scfDeveloper();
    $productId = scfSkill('字段落库技能');
    $version = scfVersion($productId);

    // 提审前：三列都是 NULL —— 正是本卡要修掉的缺口
    $before = $version->fresh();
    expect($before->delivery_channels)->toBeNull()
        ->and($before->risk_level)->toBeNull()
        ->and($before->pricing_mode)->toBeNull();

    scfUploadAndSubmit($version);

    $after = $version->fresh();

    expect($after->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and($after->delivery_channels)->toBe(['agent', 'manual'])
        ->and($after->risk_level)->toBe('medium')
        ->and($after->pricing_mode)->toBe('buyout')
        // schema 一并落库（本卡之前已在写，这里锁住它没被这次改动碰坏）
        ->and($after->manifest_schema)->toBe('zhaohuan.skill/v2');

    // D3 红线：定价走 pricing_mode，**绝不**写 license_mode='perpetual'
    expect($after->license_mode)->not->toBe('perpetual');

    // seller_id 仍归门户所有（本卡只写声明字段，不抢这列）
    expect((int) $after->seller_id)->toBe((int) $profile->seller_id);
});

// ==================== ② 试用因此可用（端到端）====================

it('端到端：开发者技能声明了 agent 通道 → C 端试用接口不再以「未声明通道」拒绝', function () {
    scfDeveloper();
    $productId = scfSkill('可试用技能');
    $version = scfVersion($productId);

    scfUploadAndSubmit($version);
    scfApproveL2($productId, $version->id);

    // 发布（真实闸门）—— 试用只认 published 版本
    scfPublish($version->id)->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);

    // 买家侧：自己 + 一台已确权设备
    $buyer = Customer::create([
        'first_name'  => '试用',
        'last_name'   => '买家',
        'email'       => 'scf-buyer'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
    $device = scfPairedDevice($buyer);

    actingAs($buyer, 'customer');

    // 核心断言：不再因为"版本没声明 agent 通道"被拒。
    // 该版本从未被人为写过 delivery_channels —— 这一项完全来自开发者 manifest。
    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertStatus(201)
        ->assertJsonPath('data.trial', true)
        ->assertJsonPath('data.delivery_channel', 'agent');

    $entitlement = SkillEntitlement::query()->where('customer_id', $buyer->id)->first();

    expect($entitlement)->not->toBeNull('试用没有落授权')
        ->and($entitlement->isTrial())->toBeTrue()
        ->and($entitlement->skill_version_id)->toBe($version->id);
});

it('对照：包只声明 manual 通道 → 试用仍被拒（证明这条闸门没被本次改动拆掉）', function () {
    scfDeveloper();
    $productId = scfSkill('仅人工通道技能');
    $version = scfVersion($productId);

    scfUploadAndSubmit($version, ['delivery_channels' => ['manual']]);
    scfApproveL2($productId, $version->id);
    scfPublish($version->id)->assertOk();

    $buyer = Customer::create([
        'first_name'  => '试用',
        'last_name'   => '买家',
        'email'       => 'scf-manual'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
    $device = scfPairedDevice($buyer);

    actingAs($buyer, 'customer');

    postJson(route('shop.api.skills.trial', $productId), ['device_id' => $device->id])
        ->assertJsonPath('code', 'TRIAL_CHANNEL_UNSUPPORTED');

    expect(SkillEntitlement::query()->where('customer_id', $buyer->id)->count())->toBe(0);
});

// ==================== ③ 风险告知因此可用 ====================

it('风险告知：落库的 risk_level=medium 能让 RiskDisclosureService 给出中风险分级文案', function () {
    scfDeveloper();
    $productId = scfSkill('风险分级技能');
    $version = scfVersion($productId);

    scfUploadAndSubmit($version);
    scfApproveL2($productId, $version->id);
    scfPublish($version->id)->assertOk();

    $profile = app(RiskDisclosureService::class)->profile($productId);

    expect($profile)->not->toBeNull()
        ->and($profile['risk_level'])->toBe('medium')
        ->and($profile['label'])->toBe('中风险')
        // 分档文案里带勾选项 —— 证明拿到的是 medium 那一档，而不是"未分级"兜底
        ->and(array_column($profile['items'], 'key'))->toContain('confirm_space')
        ->and($profile['disclosure_hash'])->not->toBe('');

    // 未声明的版本才会走"未分级" —— 这一版绝不该是它
    expect($profile['label'])->not->toBe('未分级');
});

// ==================== ④ 不抹除已有声明 ====================

it('提审的 manifest 没声明这些字段时 → 不把已有值写成 NULL（旧声明被保留）', function () {
    scfDeveloper();
    $productId = scfSkill('声明保留技能');
    $version = scfVersion($productId);

    // 模拟"此前已声明过"：三列都有合法值
    $version->update([
        'delivery_channels' => ['agent'],
        'risk_level'        => 'high',
        'pricing_mode'      => 'buyout',
    ]);

    // 直接调 service（真实 validator 会因缺 delivery_channels 拦住这种包 —— 这里
    // 刻意绕过 HTTP，只验证"无值时**不写**"这条规则本身，不是验证校验器）。
    $result = app(AuditService::class)->submitLayer0($productId, $version->id, [
        'schema'  => 'zhaohuan.skill/v2',
        'kind'    => 'motion',
        'name'    => ['zh_CN' => '缺声明的包'],
        'version' => '1.0.0',
        'safety'  => ['risk_level' => 'high'],
    ]);

    // 机审本身因为缺 delivery_channels 判拒（校验器行为不变）
    expect($result['result'])->toBe(AuditRecord::RESULT_REJECT);

    $after = $version->fresh();

    expect($after->delivery_channels)->toBe(['agent'])
        ->and($after->risk_level)->toBe('high')
        ->and($after->pricing_mode)->toBe('buyout')
        // 该包没声明 schema 之外的东西，也不能把 schema 写没
        ->and($after->manifest_schema)->toBe('zhaohuan.skill/v2');
});

// ==================== ⑤ 闸门拦文件缺失 ====================

it('文件类交付物在盘上不存在 → 发布被拒（ASSET_FILE_MISSING），版本仍 in_review、商品不可售、市场搜不到', function () {
    scfDeveloper();
    $name = '缺文件技能'.substr(uniqid(), -6);
    $productId = scfSkill($name);
    $version = scfVersion($productId);

    // 真包上传 + 提审 → in_review，且那一个交付物的文件是**真的**
    scfUploadAndSubmit($version);
    scfApproveL2($productId, $version->id);

    // 再挂一个声明为文件、磁盘上却没有的交付物（落盘失败 / 对象存储没切好 / 文件被清掉）
    $ghost = SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $version->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/scf-ghost-missing.skill',
        'file_name'        => 'ghost-missing.skill',
        'file_size'        => 1024,
        // 同一版本的 (carrier_type, sort_order) 有唯一键 —— 真包占了 0
        'sort_order'       => 1,
    ]);

    expect(Storage::disk(SkillAsset::disk())->exists($ghost->file_path))->toBeFalse('夹具前提：这个文件不该存在');

    scfPublish($version->id)
        ->assertStatus(422)
        ->assertJsonPath('code', 'ASSET_FILE_MISSING');

    // 版本停在原地 —— 不留 published 半状态
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    // 商品仍不可售 + **核心**：市场搜不到（字段断言证明不了"用户看不见"）
    expect(scfFlatStatus($productId))->toBe(0)
        ->and(scfSearchIds($name))->not->toContain($productId);
});

it('文件缺失的报错要说清是哪个交付物的哪个文件（运营据此重传）', function () {
    scfDeveloper();
    $productId = scfSkill('报错定位技能');
    $version = scfVersion($productId);

    scfUploadAndSubmit($version);
    scfApproveL2($productId, $version->id);

    $ghost = SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $version->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/scf-ghost-2.skill',
        'file_name'        => 'scf-ghost-2.skill',
        'file_size'        => 1024,
        'sort_order'       => 1,   // 唯一键：(skill_version_id, carrier_type, sort_order)
    ]);

    $res = scfPublish($version->id)->assertStatus(422);

    $message = (string) $res->json('message');

    expect($message)->toContain((string) $ghost->id)          // 哪一个 asset
        ->and($message)->toContain('scf-ghost-2.skill')       // 哪一个文件
        ->and($message)->toContain('skills/scf-ghost-2.skill'); // 路径
});

// ==================== ⑥ 非文件类载体不受影响 ====================

it('只有非文件类交付物（cloud_config）的版本仍可发布 —— 没有误伤不落盘的载体', function () {
    scfDeveloper();
    $name = '云配置技能'.substr(uniqid(), -6);
    $productId = scfSkill($name);
    $version = scfVersion($productId);

    // 提审（先用真包过 L0）后，把交付物换成"只有 payload 的 cloud_config"
    scfUploadAndSubmit($version);
    SkillAsset::query()->where('skill_version_id', $version->id)->delete();

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $version->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_CLOUD_CONFIG,
        'payload'          => ['vendor' => 'demo', 'endpoint' => 'https://example.com/config'],
    ]);

    scfApproveL2($productId, $version->id);

    scfPublish($version->id)->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and(scfFlatStatus($productId))->toBe(1)
        ->and(scfSearchIds($name))->toContain($productId);
});

// ==================== ⑦ 运营后台通道：同一闸门、显式映射（不落 default 兜底）====================

it('运营后台通道：文件缺失同样被拦，闪出「交付物文件」语义并留在商品编辑页（不是"审核没过"）', function () {
    scfDeveloper();
    $productId = scfSkill('后台发布技能');
    $version = scfVersion($productId);

    scfUploadAndSubmit($version);
    scfApproveL2($productId, $version->id);

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $version->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/scf-ghost-admin.skill',
        'file_name'        => 'scf-ghost-admin.skill',
        'file_size'        => 1024,
        'sort_order'       => 1,
    ]);

    actingAs(scfAdmin(), 'admin');

    post(route('admin.catalog.skills.versions.publish', $version->id))
        // 这不是审核问题 → 留在商品编辑页（去审核台找不到任何"待审的问题"）
        ->assertRedirect(route('admin.catalog.skills.edit', $productId));

    // ⚠ 断言的是**这个码自己的话术**，而不是 default 分支的通用 message ——
    //   调用方若靠 default 兜，这里会闪出另一句话（本卡明确要求显式加 case）。
    expect(session('error'))->toContain('交付物文件在存储上不存在')
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);
});
