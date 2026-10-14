<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * 开发者门户 · 越权测试（v3.4 步骤 9）。
 *
 * 这是本步**最重要的测试**：开发者之间的隔离，以及平台自营与开发者通道的隔离。
 * 判定口径：跨租户一律 **404**（不是 403）—— 403/404 的差异可以被用来枚举他人资源 id。
 */

function makeDev(string $prefix = 'dev'): array
{
    $customer = Customer::create([
        'first_name' => 'Dev',
        'last_name'  => 'User',
        'email'      => $prefix.uniqid().'@example.com',
        'password'   => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1,
        'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    post(route('shop.dev.skills.store'), ['name' => '技能-'.$prefix, 'price' => 10]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);

    $version = SkillVersion::where('product_id', $productId)->first();

    return compact('customer', 'profile', 'productId', 'version');
}

beforeEach(function () {
    Storage::fake(SkillAsset::DISK);
});

it('开发者 B 读写开发者 A 的技能/版本 → 全部 404', function () {
    $a = makeDev('a');

    // 切到 B
    $b = makeDev('b');

    get(route('shop.dev.skills.show', $a['productId']))->assertNotFound();
    post(route('shop.dev.skills.versions.store', $a['productId']), ['version' => '2.0.0'])
        ->assertNotFound();

    $package = new UploadedFile(tempnam(sys_get_temp_dir(), 'x'), 'x.skill', 'application/zip', null, true);

    post(route('shop.dev.versions.upload', $a['version']->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $package,
    ])->assertNotFound();

    post(route('shop.dev.versions.submit', $a['version']->id))->assertNotFound();

    // 自己的仍然可访问（确认不是"全都 404"）
    get(route('shop.dev.skills.show', $b['productId']))->assertOk();
});

it('平台自营（seller_id = NULL）的技能对开发者不可见', function () {
    $dev = makeDev();

    $platformProductId = DB::table('products')->whereNull('seller_id')->value('id');

    if (! $platformProductId) {
        $platformProductId = DB::table('products')->insertGetId([
            'sku' => 'PLAT'.uniqid(), 'type' => 'simple', 'attribute_family_id' => 1,
            'seller_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    get(route('shop.dev.skills.show', $platformProductId))->assertNotFound();
});

it('/api/dev/* 无 token → 401', function () {
    getJson(route('shop.api.dev.skills.index'))->assertStatus(401);
});

it('/api/dev/* 有买家身份但没有开发者主体 → 403 DEVELOPER_PROFILE_REQUIRED', function () {
    $buyer = Customer::create([
        'first_name' => 'Buyer', 'last_name' => 'Only',
        'email' => 'buyer'.uniqid().'@example.com',
        'password' => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status' => 1, 'is_verified' => 1,
    ]);

    Sanctum::actingAs($buyer);

    getJson(route('shop.api.dev.skills.index'))
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEVELOPER_PROFILE_REQUIRED');
});

it('/api/dev/* 开发者 token 访问他人资源 → 404（不是 403）', function () {
    $a = makeDev('api-a');
    $b = makeDev('api-b');

    // 当前 guard 上是 b；用 b 的 token 访问 a 的资源
    Sanctum::actingAs($b['customer']);

    getJson(route('shop.api.dev.skills.show', $a['productId']))->assertStatus(404);

    postJson(route('shop.api.dev.skills.versions.store', $a['productId']), ['version' => '9.9.9'])
        ->assertStatus(404);

    postJson(route('shop.api.dev.versions.submit', $a['version']->id))->assertStatus(404);

    // 自己的可访问
    getJson(route('shop.api.dev.skills.show', $b['productId']))->assertOk();
});

it('/api/dev/* 未签协议提审 → 422 AGREEMENT_REQUIRED', function () {
    $customer = Customer::create([
        'first_name' => 'Unsigned', 'last_name' => 'Dev',
        'email' => 'unsigned'.uniqid().'@example.com',
        'password' => bcrypt('secret123'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status' => 1, 'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');
    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    post(route('shop.dev.skills.store'), ['name' => '未签技能', 'price' => 1]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');
    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);

    $version = SkillVersion::where('product_id', $productId)->first();

    Sanctum::actingAs($customer);

    postJson(route('shop.api.dev.versions.submit', $version->id))
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGREEMENT_REQUIRED');
});
