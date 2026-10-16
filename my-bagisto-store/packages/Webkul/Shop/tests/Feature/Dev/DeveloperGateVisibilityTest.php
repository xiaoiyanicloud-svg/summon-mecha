<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 开发者门户 · 发布闸门可见性（2026-10-16）。
 *
 * 在这之前，开发者在技能详情页只看到一句笼统的"未上架"，看不出还差什么 ——
 * 只能去群里问人。现在门户显示**逐层**结论，且与平台真正执行的那份判定同源
 * （`ReviewGate::evaluate()`，门户不另算一份）。
 *
 * 锁三点：
 *   ① 每一层都出现，且带上"是不是还没人审"；
 *   ② 未过闸门时明说"还不能发布"，而不是让人以为只是没点发布按钮；
 *   ③ 未接入的层显示"未接入"，**不伪装成通过**（没审 ≠ 通过）。
 */

function dgDeveloper(): array
{
    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Gate',
        'email'       => 'dg'.uniqid().'@example.com',
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

    // S-12a：建技能前置实名（平台审核动作，测试直接置 approved）
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => '闸门可见性技能', 'price' => 99]);
    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    return [$profile, $productId];
}

function dgAudit(int $productId, int $versionId, int $layer, int $result): AuditRecord
{
    return AuditRecord::create([
        'skill_id'     => $productId,
        'version'      => $versionId,
        'audit_type'   => $layer === AuditRecord::LAYER_HUMAN ? 2 : 1,
        'layer'        => $layer,
        'result'       => $result,
        'submitted_at' => now(),
        'audited_at'   => now(),
    ]);
}

it('技能详情页显示逐层闸门：L0 已过、L2 尚未审核 → 明说「还不能发布」', function () {
    [$profile, $productId] = dgDeveloper();

    $version = SkillVersion::create([
        'product_id' => $productId,
        'seller_id'  => $profile->seller_id,
        'version'    => '1.0.0',
        'channel'    => 'stable',
        'status'     => SkillVersion::STATUS_DRAFT,
    ]);

    dgAudit($productId, $version->id, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);

    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        ->assertSeeText('发布闸门')
        ->assertSeeText('L0 机审')
        ->assertSeeText('L2 人审')
        ->assertSeeText('尚未审核')
        ->assertSeeText('还不能发布')
        ->assertDontSeeText('可以发布');
});

it('L1 仿真层未接入 → 显示「未接入」，不伪装成「通过」', function () {
    [$profile, $productId] = dgDeveloper();

    $version = SkillVersion::create([
        'product_id' => $productId,
        'seller_id'  => $profile->seller_id,
        'version'    => '1.0.0',
        'channel'    => 'stable',
        'status'     => SkillVersion::STATUS_DRAFT,
    ]);

    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        ->assertSeeText('L1 仿真预跑')
        ->assertSeeText('未接入');
});

it('两层都过 → 显示「审核已通过，等待平台上架」并给出「申请上架」，不再出现「可以发布」', function () {
    [$profile, $productId] = dgDeveloper();

    $version = SkillVersion::create([
        'product_id' => $productId,
        'seller_id'  => $profile->seller_id,
        'version'    => '1.0.0',
        'channel'    => 'stable',
        'status'     => SkillVersion::STATUS_DRAFT,
    ]);

    dgAudit($productId, $version->id, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    dgAudit($productId, $version->id, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    // 审核全过 ≠ 已上架：上架权归运营 —— 门户如实说"等待平台上架"并给「申请上架」，
    // 不再说"可以发布"（那句话会被读成"马上能上架"）。见任务卡 S-3a。
    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        ->assertSeeText('等待平台上架')
        ->assertSeeText('申请上架')
        ->assertDontSeeText('可以发布');
});
