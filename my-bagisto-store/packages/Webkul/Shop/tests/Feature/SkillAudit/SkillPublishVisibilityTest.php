<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/**
 * 发布 = 真上架（任务卡 D-3 · A）。
 *
 * 改前：`SkillPublisher::publish()` 只把**版本**置 published，商品仍是 `status=0`
 * （开发者建品即草稿，`DeveloperSkillService::createSkill`）。于是技能走完
 * "提审 → L2 通过 → 发布"，版本是 published，**市场却搜不到** —— "发布"是个空动作。
 *
 * 本文件用**接口**证明修复：发布后 `GET /api/skill-catalog/search` 能搜到该技能，
 * 发布前搜不到。**字段断言证明不了"用户能看见"** —— 所以核心断言是搜索接口本身。
 */

function spvHeaders(): array
{
    return ['X-Internal-Key' => 'test-internal-key', 'Accept' => 'application/json'];
}

/** 走真实开发者链路建一个 `status=0` 的技能商品，返回 [profile, productId, name]。 */
function spvSkill(): array
{
    $name = '可见性技能'.substr(uniqid(), -6);

    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Vis',
        'email'       => 'spv'.uniqid().'@example.com',
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

    // D-5 实名发布前置：本文件测的是"发布=真上架"，主体必须是**合格**的（实名通过 + 协议已签），
    // 否则发布会先被主体资格挡下（fixture 的开发者默认 pending）。这里把该主体的实名审过关，
    // 让用例聚焦它真正要锁的东西 —— 而不是把前置弱化掉。
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 199])->assertRedirect();

    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    return [$profile, $productId, $name];
}

/** 造一个"过了 L0+L2、待发布"的版本（现实里提审后即 in_review），带交付物。 */
function spvPublishableVersion(int $productId, ?int $sellerId): SkillVersion
{
    $version = SkillVersion::create([
        'product_id'   => $productId,
        'seller_id'    => $sellerId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_IN_REVIEW,
        'license_mode' => 'per_device',
    ]);

    // ⚠ 2026-10-16：文件类交付物必须真的落盘 —— 发布现在先过"可交付性"前置
    //   （SkillPublisher::ERR_ASSET_FILE_MISSING）。本文件测的是"发布 = 真上架"，
    //   夹具若指向一个不存在的文件，发布会先被文件缺失挡住，测不到可售与可见性。
    Storage::disk(SkillAsset::disk())->put('skills/spv.skill', 'demo-package');

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $sellerId,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/spv.skill',
        'file_name'        => 'spv.skill',
        'file_size'        => 1024,
    ]);

    foreach ([AuditRecord::LAYER_MACHINE, AuditRecord::LAYER_HUMAN] as $layer) {
        AuditRecord::create([
            'skill_id'     => $productId,
            'version'      => $version->id,
            'audit_type'   => $layer === AuditRecord::LAYER_HUMAN ? 2 : 1,
            'layer'        => $layer,
            'result'       => AuditRecord::RESULT_PASS,
            'submitted_at' => now(),
            'audited_at'   => now(),
        ]);
    }

    return $version;
}

/** 市场搜索命中该名字的商品 id 集合。 */
function spvSearchIds(string $q): array
{
    $res = getJson(route('shop.api.skill-catalog.search', ['q' => $q]))->assertOk();

    return collect($res->json('data'))->pluck('id')->map('intval')->all();
}

beforeEach(function () {
    // 交付物走假盘：发布要校验文件真实存在（见 spvPublishableVersion 注释）。
    Storage::fake(SkillAsset::disk());
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

it('发布让技能真正可售：发布前搜不到，发布后能搜到（接口断言）', function () {
    [$profile, $productId, $name] = spvSkill();

    $version = spvPublishableVersion($productId, $profile->seller_id);

    // 发布前：市场搜不到（商品还是 status=0）
    expect(spvSearchIds($name))->not->toContain($productId);

    // 发布（internal.key 通道 —— 与运营后台共用同一条 SkillPublisher 闸门 + 置可售）
    post(route('shop.api.skill-admin.versions.publish', $version->id), [], spvHeaders())->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);

    // 发布后：市场**能搜到** —— 这是本卡的验收核心（字段对了不代表用户看得见）
    expect(spvSearchIds($name))->toContain($productId);

    // 佐证：flat 行确实被置为可售（经事件三步刷出来的，不是直写派生表）
    $flat = DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('locale', app()->getLocale())
        ->first();

    expect((int) $flat->status)->toBe(1)
        ->and((int) $flat->visible_individually)->toBe(1);
});

it('发布置可售不会把商品从「技能」分类里摘掉（搜索按分类过滤，摘掉就搜不到）', function () {
    [$profile, $productId, $name] = spvSkill();

    $version = spvPublishableVersion($productId, $profile->seller_id);

    $skillCategoryId = (int) DB::table('category_translations')->where('slug', 'skill')->value('category_id');

    // 建品即挂在该分类下
    expect(DB::table('product_categories')
        ->where('product_id', $productId)->where('category_id', $skillCategoryId)->exists())->toBeTrue();

    post(route('shop.api.skill-admin.versions.publish', $version->id), [], spvHeaders())->assertOk();

    // 发布后仍在分类里（AbstractType::update 不传 categories 会 sync 成空 —— 这里是回归锁）
    expect(DB::table('product_categories')
        ->where('product_id', $productId)->where('category_id', $skillCategoryId)->exists())->toBeTrue();
});

it('版本指向的商品不存在 → 发布失败，且不留下 published 半状态（整体回滚）', function () {
    // 商品不存在：现实中不该发生（版本总挂在商品下）。发布必须**报错**，不能谎报已发布。
    $version = spvPublishableVersion(970000 + random_int(1, 999), null);

    post(route('shop.api.skill-admin.versions.publish', $version->id), [], spvHeaders())
        ->assertStatus(500);

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);
});
