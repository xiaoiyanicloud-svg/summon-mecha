<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/**
 * 实名发布前置（任务卡 D-5 · 裁决 Q6）。
 *
 * 改前：`developer_profile.realname_status` **不拦任何事** —— 未实名主体的技能可以走完提审、
 * 被运营发布、卖出去。在真实交易上这是合规缺口。
 *
 * 裁决：**提审不拦**（鼓励供给），**发布（上架）前必须实名通过** + 协议签全；
 * **平台自营（seller_id 为 NULL）不受限**。
 *
 * 本文件锁的是**行为**：能不能上架、商品可不可售、市场**看不看得见**。
 * 核心断言一律走接口（`GET /api/skill-catalog/search`）—— 字段断言证明不了"用户看得见"。
 */

function spqHeaders(): array
{
    return ['X-Internal-Key' => 'test-internal-key', 'Accept' => 'application/json'];
}

/**
 * 建一个**真实**技能商品（走建/更商品通道），`status=0`（建品即草稿）+ 挂「技能」分类。
 * 发布会把商品一并置为可售 —— 版本指向的商品必须真实存在，否则会在"置可售"那步失败。
 */
function spqProduct(string $name): int
{
    Event::dispatch('catalog.product.create.before');

    $product = app(ProductRepository::class)->create([
        'type'                => 'simple',
        'attribute_family_id' => (int) (DB::table('attribute_families')->where('code', 'default')->value('id')
            ?: DB::table('attribute_families')->value('id') ?: 1),
        'sku'                 => 'SPQ'.strtoupper(Str::random(10)),
    ]);

    Event::dispatch('catalog.product.create.after', $product);

    Event::dispatch('catalog.product.update.before', $product->id);

    app(ProductRepository::class)->update([
        'name'                 => $name,
        'url_key'              => 'spq-'.$product->id,
        'price'                => 10,
        'status'               => 0,
        'visible_individually' => 1,
        'channel'              => core()->getDefaultChannelCode(),
        'locale'               => (string) (optional(core()->getDefaultChannel()->default_locale)->code
            ?: core()->getDefaultChannelCode()),
        'categories'           => array_values(array_filter([
            (int) DB::table('category_translations')->where('slug', 'skill')->value('category_id'),
        ])),
    ], $product->id);

    Event::dispatch('catalog.product.update.after', $product->fresh());

    return (int) $product->id;
}

/** 造一个"过了 L0+L2、待发布"的版本（现实里提审后即 in_review），带交付物。 */
function spqPublishableVersion(int $productId, ?int $sellerId): SkillVersion
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
    //   （SkillPublisher::ERR_ASSET_FILE_MISSING）。本文件测的是**主体资格**，
    //   若夹具的文件不存在，发布会先报"文件缺失"而走不到主体资格那一步，
    //   这些用例就测不到它们要测的东西（真实流程里包总是上传落盘后才发布的）。
    Storage::disk(SkillAsset::disk())->put('skills/spq.skill', 'demo-package');

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $sellerId,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/spq.skill',
        'file_name'        => 'spq.skill',
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

    return $version->fresh('assets');
}

/**
 * 走真实门户链路入驻一个开发者，返回它的 DeveloperProfile（`seller_id` 非 NULL）。
 *
 * @param  bool  $signAgreements  是否签署两份必需协议
 */
function spqDeveloper(bool $signAgreements = true): DeveloperProfile
{
    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Qual',
        'email'       => 'spq'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);

    if ($signAgreements) {
        post(route('shop.dev.agreements.sign'), [
            'accept_dev_agreement'     => 1,
            'accept_safety_commitment' => 1,
        ]);
    }

    // 入驻即 pending：实名审核动作属平台端，测试里直接改库或调平台接口来置 approved。
    return DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();
}

/** 平台把某个主体的实名审过。 */
function spqApprove(DeveloperProfile $profile): DeveloperProfile
{
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    return $profile->fresh();
}

/** 市场搜索命中该名字的商品 id 集合。 */
function spqSearchIds(string $q): array
{
    $res = getJson(route('shop.api.skill-catalog.search', ['q' => $q]))->assertOk();

    return collect($res->json('data'))->pluck('id')->map('intval')->all();
}

function spqFlatStatus(int $productId): ?int
{
    $status = DB::table('product_flat')->where('product_id', $productId)->value('status');

    return $status === null ? null : (int) $status;
}

function spqPublish(int $versionId)
{
    return post(route('shop.api.skill-admin.versions.publish', $versionId), [], spqHeaders());
}

beforeEach(function () {
    // 交付物走假盘：发布要校验文件真实存在（见 spqPublishableVersion 注释）。
    Storage::fake(SkillAsset::disk());
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

// ───────────────────────── ① 未实名 → 拦 ─────────────────────────

it('未实名（pending）→ 发布被拒（新码），版本仍 in_review、商品仍不可售、市场搜不到', function () {
    $profile = spqDeveloper();          // 入驻即 pending，协议已签
    $sku = '实名前置未过'.substr(uniqid(), -6);
    $productId = spqProduct($sku);
    $version = spqPublishableVersion($productId, $profile->seller_id);

    expect($profile->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING);

    spqPublish($version->id)
        ->assertStatus(422)
        ->assertJsonPath('code', 'OWNER_NOT_VERIFIED');

    // 版本停在原地 —— 不留 published 半状态
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    // 商品仍不可售
    expect(spqFlatStatus($productId))->toBe(0);

    // **核心**：市场搜不到（字段断言证明不了"看不见"）
    expect(spqSearchIds($sku))->not->toContain($productId);
});

it('已签协议但实名被驳回（rejected）→ 同样被拒', function () {
    $profile = spqDeveloper();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_REJECTED]);

    $sku = '实名驳回'.substr(uniqid(), -6);
    $productId = spqProduct($sku);
    $version = spqPublishableVersion($productId, $profile->seller_id);

    spqPublish($version->id)
        ->assertStatus(422)
        ->assertJsonPath('code', 'OWNER_NOT_VERIFIED');

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and(spqSearchIds($sku))->not->toContain($productId);
});

it('已实名但协议未签全 → 被拒（码是 AGREEMENT_REQUIRED，与"没实名"分开）', function () {
    $profile = spqApprove(spqDeveloper(signAgreements: false));

    $sku = '协议未签'.substr(uniqid(), -6);
    $productId = spqProduct($sku);
    $version = spqPublishableVersion($productId, $profile->seller_id);

    spqPublish($version->id)
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGREEMENT_REQUIRED');

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and(spqSearchIds($sku))->not->toContain($productId);
});

// ───────────────────────── ② 实名通过 → 放行（对照组）─────────────────────────

it('实名通过 + 协议签全 → 发布成功、市场搜得到（证明这道前置不是把所有人都挡住）', function () {
    $profile = spqApprove(spqDeveloper());
    $sku = '实名通过可上架'.substr(uniqid(), -6);
    $productId = spqProduct($sku);
    $version = spqPublishableVersion($productId, $profile->seller_id);

    expect(spqSearchIds($sku))->not->toContain($productId);

    spqPublish($version->id)->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and(spqFlatStatus($productId))->toBe(1)
        ->and(spqSearchIds($sku))->toContain($productId);
});

// ───────────────────────── ③ 平台自营不受限 ─────────────────────────

it('平台自营（seller_id 为 NULL）→ 不受主体资格限制，发布成功', function () {
    $sku = '平台自营'.substr(uniqid(), -6);
    $productId = spqProduct($sku);
    $version = spqPublishableVersion($productId, null);

    spqPublish($version->id)->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and(spqSearchIds($sku))->toContain($productId);
});

// ───────────────────────── ④ 归属异常 → fail-closed ─────────────────────────

it('seller_id 有值却查不到开发者主体 → 拒绝（fail-closed），不留 published 半状态', function () {
    // 999999 不存在任何 DeveloperProfile —— 归属异常，**不能**当成"自营"放行。
    $sku = '归属异常'.substr(uniqid(), -6);
    $productId = spqProduct($sku);
    $version = spqPublishableVersion($productId, 999999);

    // 先确认这个 seller_id 确实没有 profile（避免用错造数方式导致断言失真）
    expect(DeveloperProfile::where('seller_id', 999999)->exists())->toBeFalse();

    spqPublish($version->id)
        ->assertStatus(422)
        ->assertJsonPath('code', 'OWNER_PROFILE_MISSING');

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and(spqFlatStatus($productId))->toBe(0)
        ->and(spqSearchIds($sku))->not->toContain($productId);
});
