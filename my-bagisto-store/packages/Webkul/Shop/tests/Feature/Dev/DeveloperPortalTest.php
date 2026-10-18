<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * 开发者门户 · 闭环测试（v3.4 步骤 9）。
 *
 * 覆盖计划里的完成判据最核心的一段：**入驻 → 建技能 → 传包 → 提审**。
 */

/**
 * 建一个测试用 customer（账号即开发者主体）。
 */
function devCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'User',
        'email'       => 'dev'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 造一个合法的 .skill 包（ZIP + manifest.json）。
 * 清单契约来自 SkillManifest / ManifestValidator。
 */
function makeSkillPackage(string $name = '测试技能'): string
{
    $manifest = json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => $name],
        'version'           => '1.0.0',
        'delivery_channels' => ['manual'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'         => 'low',
            'requires_open_area' => true,
            'estop_required'     => true,
            'max_duration_s'     => 30,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $path = tempnam(sys_get_temp_dir(), 'skill').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('README.txt', 'demo package');
    $zip->close();

    return $path;
}

beforeEach(function () {
    Storage::fake(SkillAsset::DISK);
});

it('入驻：建 seller + developer_profile + owner 团队行', function () {
    $customer = devCustomer();

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), [
        'dev_type'    => 'personal',
        'seller_name' => '张三',
    ])->assertRedirect(route('shop.dev.agreements'));

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    expect($profile)->not->toBeNull()
        ->and($profile->seller_id)->not->toBeNull()
        ->and($profile->dev_type)->toBe('personal')
        // 审核属平台端，本轮不做 —— 如实停在 pending，不伪造 approved
        ->and($profile->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($profile->status)->toBe(DeveloperProfile::STATUS_ACTIVE);

    $this->assertDatabaseHas('developer_team_member', [
        'developer_id' => $profile->id,
        'customer_id'  => $customer->id,
        'role'         => 'owner',
    ]);

    $this->assertDatabaseHas('sellers', [
        'id'                => $profile->seller_id,
        'owner_customer_id' => $customer->id,
    ]);
});

it('签署协议：漏签被拒；签全留痕（含正文指纹与 IP）', function () {
    $customer = devCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    // 漏签一条 → 被拒
    post(route('shop.dev.agreements.sign'), ['accept_dev_agreement' => 1])
        ->assertSessionHasErrors();

    expect($profile->fresh()->hasSignedAllAgreements())->toBeFalse();

    // 两条全签
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ])->assertRedirect(route('shop.dev.skills.index'))
        ->assertSessionHas('success', '协议已签署。请在总览查看实名进度，满足条件后即可创建技能。');

    $profile->refresh();

    expect($profile->hasSignedAllAgreements())->toBeTrue()
        ->and($profile->agreement_signed_at)->not->toBeNull()
        ->and($profile->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING);

    // Signing only records consent; the actual creation endpoint must still
    // reject this unreviewed subject without leaving a product behind.
    post(route('shop.dev.skills.store'), ['name' => '尚未实名的技能', 'price' => 1])
        ->assertSessionHasErrors('realname');
    expect(DB::table('products')->where('seller_id', $profile->seller_id)->count())->toBe(0);

    $payload = $profile->agreements()->where('doc_type', 'dev_agreement')->first()->sign_payload;

    expect($payload)
        ->toHaveKey('agreement_text_hash')
        ->toHaveKey('doc_version')
        ->toHaveKey('ip')
        ->and($payload['checked'])->toBeTrue();
});

it('建技能：商品归属到我的 seller，且生成 product_flat（不是残品）', function () {
    $customer = devCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    // S-12a：建技能前置实名（平台审核动作，测试直接置 approved —— 否则会被 fail-closed 拦住）
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), [
        'name'  => '抓取技能',
        'price' => 199,
    ])->assertRedirect();

    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    expect($productId)->not->toBeNull();
    expect((int) DB::table('products')->where('id', $productId)->value('seller_id'))
        ->toBe((int) $profile->seller_id);

    // flat 行必须生成 —— 否则商品在列表/详情里是空的（本项目踩过）
    expect(DB::table('product_flat')->where('product_id', $productId)->count())->toBeGreaterThan(0);

    // 新建即未上架：开发者不能自己上架
    expect((int) DB::table('product_flat')->where('product_id', $productId)->value('status'))->toBe(0);
});

it('传包 → 提审：走的是既有审核服务（audit_record 出现 L0）', function () {
    $customer = devCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    // S-12a：建技能前置实名（见上）
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => '抓取技能', 'price' => 199]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);

    $version = SkillVersion::where('product_id', $productId)->first();

    expect($version)->not->toBeNull()
        ->and((int) $version->seller_id)->toBe((int) $profile->seller_id)
        ->and($version->status)->toBe(SkillVersion::STATUS_DRAFT);

    // 上传合法技能包
    $package = new UploadedFile(makeSkillPackage(), 'demo.skill', 'application/zip', null, true);

    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $package,
    ])->assertRedirect();

    $asset = SkillAsset::where('skill_version_id', $version->id)->first();
    expect($asset)->not->toBeNull();
    Storage::disk(SkillAsset::DISK)->assertExists($asset->file_path);

    // 提审
    post(route('shop.dev.versions.submit', $version->id))->assertRedirect();

    // 提审后版本生命周期落到 in_review（写状态的唯一入口是 AuditService，见 submitLayer0）。
    // 注：上面 :183 那处断言在**提审之前**，彼时仍是 draft —— 两者不矛盾。
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    $this->assertDatabaseHas('audit_record', [
        'skill_id' => $productId,
        'version'  => $version->id,
        'layer'    => AuditRecord::LAYER_MACHINE,
        'result'   => AuditRecord::RESULT_PASS,
    ]);
});

it('未签协议不能提审；没传包不能提审', function () {
    $customer = devCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    // S-12a：建技能前置实名（见上）
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => '技能A', 'price' => 1]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');
    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);
    $version = SkillVersion::where('product_id', $productId)->first();

    // 未签协议 → 被闸门拦住，且不产生审核记录
    post(route('shop.dev.versions.submit', $version->id))->assertSessionHasErrors();
    expect(AuditRecord::where('version', $version->id)->count())->toBe(0);

    // 签完协议但没传包 → 仍被拦
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    post(route('shop.dev.versions.submit', $version->id))->assertSessionHasErrors();
    expect(AuditRecord::where('version', $version->id)->count())->toBe(0);
});
