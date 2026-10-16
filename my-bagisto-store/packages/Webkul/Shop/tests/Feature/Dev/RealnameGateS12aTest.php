<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperSkillService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * S-12a · 实名拆两层 + **建技能服务端双拦**（fail-closed）。
 *
 * 拍板（2026-10-16 第三轮，改判了 Q6 的一半）：开发者**必须实名**才能建技能；
 * 原裁决"提审不拦、只在发布前拦实名"的拦截点**前移**到"建技能"。上架闸门那道实名校验
 * **保留**做双保险（`SkillPublishQualificationTest` 仍锁着）。
 *
 * 本文件锁的**行为**：
 *   ① 未实名 → 门户与 API **都拦**（门户不建商品、API 403 伞码）；
 *   ② 两层都 approved → 门户与 API **都能建**（证明这道门不是把所有人都挡住）；
 *   ③ **存量不追溯**：只写了总状态 approved、两层列为 NULL 的历史主体照旧能建；
 *   ④ 任一层 rejected → 拦，且 recompute 把总状态算成 rejected；
 *   ⑤ 归属缺失与实名未过**分成两个码**（处置路径不同，不互相误报）；
 *   ⑥ 服务层兜底：`createSkill()` 自己 fail-closed，不靠控制器。
 *
 * ⚠ 判据是**总状态** `realname_status`（见 DeveloperProfile::isRealnameApproved）——
 *   与上架闸门读同一列，避免"能建技能却永远上不了架"的自相矛盾。
 */

function s12aCustomer(string $prefix = 's12a'): Customer
{
    return Customer::create([
        'first_name'  => 'S12a',
        'last_name'   => 'Gate',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 走真实门户链路「入驻 → 签协议」，返回 [customer, profile]。入驻即 pending（未实名）。 */
function s12aOnboarded(string $prefix = 's12a'): array
{
    $customer = s12aCustomer($prefix);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    return [$customer, DeveloperProfile::where('customer_id', $customer->id)->firstOrFail()];
}

/**
 * 两层都置 approved，并走 `recomputeRealnameStatus()` 让总状态变 approved
 * —— 顺手覆盖 recompute 的"两层都过 → approved"分支。
 */
function s12aApproveTwoLayers(DeveloperProfile $profile): DeveloperProfile
{
    $profile->company_realname_status  = DeveloperProfile::REALNAME_APPROVED;
    $profile->operator_realname_status = DeveloperProfile::REALNAME_APPROVED;
    $profile->recomputeRealnameStatus();
    $profile->save();

    return $profile->fresh();
}

/** 该主体名下的技能（商品）条数。 */
function s12aSkillCount(DeveloperProfile $profile): int
{
    return DB::table('products')->where('seller_id', $profile->seller_id)->count();
}

// ───────────────────────── ① 未实名 → 门户拦（不建商品）─────────────────────────

it('未实名 → 门户建技能被拦：不建商品，错误 key 固定为 realname（提示"需先实名"）', function () {
    [$customer, $profile] = s12aOnboarded();

    expect($profile->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING);

    $before = s12aSkillCount($profile);

    post(route('shop.dev.skills.store'), ['name' => '未实名技能', 'price' => 10])
        ->assertSessionHasErrors('realname');

    // 商品表一字不增（没有半截商品留下来）
    expect(s12aSkillCount($profile))->toBe($before)
        ->and(s12aSkillCount($profile))->toBe(0);

    // 提示语是"要先实名"这一句（人话 message 区分场景，不复用上架那句）
    expect(session('errors')->first('realname'))->toContain('实名');
});

// ───────────────────────── ② 未实名 → API 拦（403 伞码）─────────────────────────

it('未实名 → API 建技能 403 + code=OWNER_NOT_VERIFIED，extra.realname 两层明细都在', function () {
    [$customer, $profile] = s12aOnboarded();

    // API 通道只认 Bearer token（见 ResolveDeveloper），切到 sanctum
    Sanctum::actingAs($customer);

    postJson(route('shop.api.dev.skills.store'), ['name' => '未实名API技能', 'price' => 10])
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'OWNER_NOT_VERIFIED')
        // 两层状态明细必须在（客户端据此引导到该补的那一层）
        ->assertJsonStructure(['extra' => ['realname' => ['company', 'operator']]])
        // 入驻即 pending：两层都还没填 → null（不是"已通过"）
        ->assertJsonPath('extra.realname.company', null)
        ->assertJsonPath('extra.realname.operator', null);

    expect(s12aSkillCount($profile))->toBe(0);
});

// ───────────────────────── ③ 两层都过 → 门户 + API 都能建 ─────────────────────────

it('两层都 approved（总状态经 recompute 变 approved）→ 门户与 API 都能建技能', function () {
    [$customer, $profile] = s12aOnboarded();
    $profile = s12aApproveTwoLayers($profile);

    expect($profile->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($profile->isRealnameApproved())->toBeTrue();

    // 门户
    post(route('shop.dev.skills.store'), ['name' => '实名后技能一', 'price' => 10])
        ->assertRedirect();
    expect(s12aSkillCount($profile))->toBe(1);

    // API
    Sanctum::actingAs($customer);
    postJson(route('shop.api.dev.skills.store'), ['name' => '实名后技能二', 'price' => 20])
        ->assertStatus(201);
    expect(s12aSkillCount($profile))->toBe(2);
});

// ───────────────────────── ④ 存量不追溯 ─────────────────────────

it('存量不追溯：只有总状态 approved、两层列为 NULL → 照旧能建技能', function () {
    [$customer, $profile] = s12aOnboarded();

    // 模拟历史数据：只把总状态置 approved，两层列保持 NULL（存量主体从没写过两层）
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);
    $profile = $profile->fresh();

    expect($profile->company_realname_status)->toBeNull()
        ->and($profile->operator_realname_status)->toBeNull()
        ->and($profile->isRealnameApproved())->toBeTrue();

    post(route('shop.dev.skills.store'), ['name' => '存量技能', 'price' => 10])
        ->assertRedirect();

    expect(s12aSkillCount($profile))->toBe(1);
});

// ───────────────────────── ⑤ 任一层 rejected → 拦 + recompute 算 rejected ─────────────────────────

it('任一层 rejected → 门户与 API 都拦；recomputeRealnameStatus() 把总状态算成 rejected', function () {
    [$customer, $profile] = s12aOnboarded();

    $profile->company_realname_status  = DeveloperProfile::REALNAME_REJECTED;
    $profile->operator_realname_status = DeveloperProfile::REALNAME_APPROVED;
    $profile->recomputeRealnameStatus();
    $profile->save();
    $profile = $profile->fresh();

    // 任一层 rejected → 总状态 rejected（即使另一层已过）
    expect($profile->realname_status)->toBe(DeveloperProfile::REALNAME_REJECTED)
        ->and($profile->isRealnameApproved())->toBeFalse();

    post(route('shop.dev.skills.store'), ['name' => '被驳回技能', 'price' => 10])
        ->assertSessionHasErrors('realname');

    Sanctum::actingAs($customer);
    postJson(route('shop.api.dev.skills.store'), ['name' => '被驳回API技能', 'price' => 10])
        ->assertStatus(403)
        ->assertJsonPath('code', 'OWNER_NOT_VERIFIED')
        ->assertJsonPath('extra.realname.company', DeveloperProfile::REALNAME_REJECTED)
        ->assertJsonPath('extra.realname.operator', DeveloperProfile::REALNAME_APPROVED);

    expect(s12aSkillCount($profile))->toBe(0);
});

// ───────────────────────── ⑥ 归属缺失 ≠ 实名问题 ─────────────────────────

it('归属缺失（seller_id 为 NULL）→ DEVELOPER_SELLER_MISSING，不误报成实名问题', function () {
    $customer = s12aCustomer('orphan');
    actingAs($customer, 'developer');

    // 手造"有主体、无归属"的 profile；实名**已过**，排除实名干扰
    $profile = DeveloperProfile::create([
        'customer_id'     => $customer->id,
        'seller_id'       => null,
        'dev_type'        => 'personal',
        'realname_status' => DeveloperProfile::REALNAME_APPROVED,
        'status'          => DeveloperProfile::STATUS_ACTIVE,
    ]);

    expect($profile->isRealnameApproved())->toBeTrue();

    $before = DB::table('products')->count();

    // 门户：错误 key 是 name（归属），**不是** realname
    post(route('shop.dev.skills.store'), ['name' => '无归属技能', 'price' => 10])
        ->assertSessionHasErrors('name')
        ->assertSessionDoesntHaveErrors('realname');

    // API：码是 DEVELOPER_SELLER_MISSING（与实名未过各自成码）
    Sanctum::actingAs($customer);
    postJson(route('shop.api.dev.skills.store'), ['name' => '无归属API技能', 'price' => 10])
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEVELOPER_SELLER_MISSING');

    expect(DB::table('products')->count())->toBe($before);
});

// ───────────────────────── ⑦ 服务层兜底：不靠控制器 ─────────────────────────

it('服务层兜底：createSkill() 对未实名主体直接返回 null（漏斗 fail-closed）', function () {
    [, $profile] = s12aOnboarded();

    $service = app(DeveloperSkillService::class);

    expect($service->createSkill($profile, ['name' => '兜底技能', 'price' => 10]))->toBeNull()
        ->and(s12aSkillCount($profile))->toBe(0);
});

// ───────────────────────── ⑧ 入口页如实说明（E · 非弹窗）─────────────────────────

it('未实名开发者打开创建页 → 200 且显示"还不能创建技能"的入口提示（不是白屏/报错）', function () {
    s12aOnboarded();

    get(route('shop.dev.skills.create'))
        ->assertOk()
        ->assertSeeText('还不能创建技能');
});

it('已实名开发者打开创建页 → 不显示拦截提示（这道门不是无差别吓人）', function () {
    [, $profile] = s12aOnboarded();
    s12aApproveTwoLayers($profile);

    get(route('shop.dev.skills.create'))
        ->assertOk()
        ->assertDontSeeText('还不能创建技能');
});
