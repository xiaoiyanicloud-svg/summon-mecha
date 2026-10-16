<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\OperationLog;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * 开发者门户 · 技能信息可编辑（任务卡 D-1）。
 *
 * 这块的重点是**边界**，不是"表单能不能提交"：
 *   ① 改的是不是**我自己**的 —— 他人的 id 一律 404，不留枚举他人资源的口子；
 *   ② 改完 **product_flat 也必须变** —— 只动 EAV 不改 flat，列表/详情读到的还是旧值
 *      （本项目为此踩过坑，见 updateSkill 的注释）；
 *   ③ 开发者**只**能改 名称/简介/详情/价格 —— 上下架 / 归属 / SKU 就算塞进请求体也得被丢弃。
 */

function devEditCustomer(string $prefix = 'edit'): Customer
{
    return Customer::create([
        'first_name'  => 'Edit',
        'last_name'   => 'Dev',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 走真实 HTTP 完成「入驻 → 签协议」，返回 [customer, profile]。
 * 与 Dev 目录其余测试同一条路 —— 不手搓 profile，免得漏掉 seller 归属。
 */
function devEditOnboarded(string $prefix = 'edit'): array
{
    $customer = devEditCustomer($prefix);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();

    // S-12a：建技能前置实名（平台审核动作，测试直接置 approved）
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    return [$customer, $profile];
}

/** 通过门户建一个技能（走 HTTP），返回商品 id。 */
function devEditSkill(string $name, float $price): int
{
    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => $price])->assertRedirect();

    return (int) DB::table('products')->orderByDesc('id')->value('id');
}

/**
 * 取某商品在**默认渠道/语言**下的 flat 行。
 *
 * ⚠ 必须按 channel+locale 收窄：product_flat 每个 (渠道,语言) 一行，裸 `first()` 会
 *   随机命中另一行（读出来 name 是 null），那样测的就不是门户真正展示的那一行。
 */
function devEditFlat(int $productId): object
{
    $channel = core()->getDefaultChannelCode();
    $locale = (string) (optional(core()->getDefaultChannel()->default_locale)->code ?: $channel);

    return DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('channel', $channel)
        ->where('locale', $locale)
        ->first();
}

it('改名 + 改价：门户回显新值，且 product_flat 同步刷新（不是只改 EAV）', function () {
    [, $profile] = devEditOnboarded();

    $productId = devEditSkill('旧名字', 100);

    put(route('shop.dev.skills.update', $productId), [
        'name'  => '新名字',
        'price' => 288,
    ])->assertRedirect(route('shop.dev.skills.show', $productId));

    // 详情页读到的是新值
    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        ->assertSee('新名字');

    // flat 也变了 —— 这才是"事件三步"真的派发了的证据，而不是只写了 EAV
    $flat = devEditFlat($productId);

    expect($flat->name)->toBe('新名字')
        ->and((float) $flat->price)->toBe(288.0);

    // 改价必须留痕
    $this->assertDatabaseHas('operation_log', [
        'actor_type'  => OperationLog::ACTOR_SELLER,
        'actor_id'    => $profile->seller_id,
        'action'      => 'skill.update',
        'target_type' => 'product',
        'target_id'   => $productId,
    ]);
});

it('改他人的技能：编辑页与提交都 404，且对方数据一字未改', function () {
    [$custA] = devEditOnboarded('edit_a_');

    devEditOnboarded('edit_b_');        // 此刻身份是 B
    $skillB = devEditSkill('B的技能', 50);

    actingAs($custA, 'developer');      // 换回 A，去动 B 的技能

    get(route('shop.dev.skills.edit', $skillB))->assertNotFound();

    put(route('shop.dev.skills.update', $skillB), ['name' => '劫持', 'price' => 1])
        ->assertNotFound();

    // B 的技能毫发无损，也不该留下任何"我改过它"的痕迹
    $flat = devEditFlat($skillB);

    expect($flat->name)->toBe('B的技能')
        ->and((float) $flat->price)->toBe(50.0);

    $this->assertDatabaseMissing('operation_log', [
        'action'    => 'skill.update',
        'target_id' => $skillB,
    ]);
});

it('未登录访问编辑页：跳开发者登录', function () {
    get(route('shop.dev.skills.edit', 1))
        ->assertRedirect(route('shop.dev.login'));
});

it('价格为负：被校验拦住，且库里一字未改', function () {
    devEditOnboarded();

    $productId = devEditSkill('原名', 100);

    put(route('shop.dev.skills.update', $productId), ['name' => '改名', 'price' => -5])
        ->assertSessionHasErrors('price');

    $flat = devEditFlat($productId);

    expect($flat->name)->toBe('原名')
        ->and((float) $flat->price)->toBe(100.0);
});

it('请求体夹带 status=1：被丢弃，技能仍是未上架', function () {
    devEditOnboarded();

    $productId = devEditSkill('技能', 100);

    // 新建即未上架
    expect((int) devEditFlat($productId)->status)->toBe(0);

    put(route('shop.dev.skills.update', $productId), [
        'name'   => '技能改名',
        'price'  => 100,
        'status' => 1,      // 开发者不得自己上架
    ])->assertRedirect();

    // 名称改了，但上下架状态没被这套表单碰到
    expect(devEditFlat($productId)->name)->toBe('技能改名')
        ->and((int) devEditFlat($productId)->status)->toBe(0);
});
