<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Catalog\SkillReviewController;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * 开发者端 · 撤回提审 + 下架（任务卡 D-3 · D/E）。
 *
 * 锁三件事：
 *   ① 撤回只对「审核中」的版本有效，撤回后**自动离开审核台队列**（不必额外改队列）；
 *   ② 下架只停新增销售，**已售授权一字不动**（D3 买断制 / D10 售后）；
 *   ③ 归属过滤在查询层：动他人的资源一律 404，且不留任何痕迹。
 */

function swoCustomer(string $prefix = 'swo'): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Swo',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 走真实 HTTP 完成「入驻 → 签协议」，返回 [customer, profile]。 */
function swoOnboarded(string $prefix = 'swo'): array
{
    $customer = swoCustomer($prefix);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    return [$customer, DeveloperProfile::where('customer_id', $customer->id)->firstOrFail()];
}

/** 通过门户建一个技能，返回商品 id。 */
function swoSkill(string $name = '撤回下架技能'): int
{
    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 199])->assertRedirect();

    return (int) DB::table('products')->orderByDesc('id')->value('id');
}

function swoVersion(int $productId, string $status, ?int $sellerId): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'seller_id'    => $sellerId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => $status,
        'license_mode' => 'per_device',
    ]);
}

/** 取某商品在默认渠道/语言下的 flat 行。 */
function swoFlat(int $productId): object
{
    $channel = core()->getDefaultChannelCode();
    $locale = (string) (optional(core()->getDefaultChannel()->default_locale)->code ?: $channel);

    return DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('channel', $channel)
        ->where('locale', $locale)
        ->first();
}

/** 审核台队列里的版本 id（口径 = 已提审 in_review|rejected 且未过人审）。 */
function swoQueueIds(): array
{
    // 直接读控制器的视图数据、不渲染（本套件注册了店铺主题，渲染 Admin 布局会命中店铺 Vite 清单）
    $view = app(SkillReviewController::class)
        ->index(Request::create('/admin/catalog/skill-reviews', 'GET'));

    return collect($view->getData()['rows'])->map(fn ($row) => $row['version']->id)->all();
}

// ==================== D · 撤回提审 ====================

it('撤回提审：in_review → draft，离开审核台队列，且留痕', function () {
    [, $profile] = swoOnboarded();

    $productId = swoSkill();
    $version = swoVersion($productId, SkillVersion::STATUS_IN_REVIEW, $profile->seller_id);

    // 提审记录（队列要求该版本有 audit_record）
    AuditRecord::create([
        'skill_id'     => $productId,
        'version'      => $version->id,
        'audit_type'   => 1,
        'layer'        => AuditRecord::LAYER_MACHINE,
        'result'       => AuditRecord::RESULT_PASS,
        'submitted_at' => now(),
        'audited_at'   => now(),
    ]);

    // 撤回前在审核台队列里
    expect(swoQueueIds())->toContain($version->id);

    post(route('shop.dev.versions.withdraw', $version->id))->assertRedirect();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT)
        // 撤回后**自动离开队列**（口径是 in_review|rejected，status 一改就掉）
        ->and(swoQueueIds())->not->toContain($version->id);

    $this->assertDatabaseHas('operation_log', [
        'actor_type'  => OperationLog::ACTOR_SELLER,
        'actor_id'    => $profile->seller_id,
        'action'      => 'skill.version.withdraw',
        'target_type' => 'skill_version',
        'target_id'   => $version->id,
    ]);
});

it('撤回提审：非「审核中」的版本不能撤回（报错，状态不变，不留痕）', function () {
    [, $profile] = swoOnboarded();

    $productId = swoSkill();
    $version = swoVersion($productId, SkillVersion::STATUS_DRAFT, $profile->seller_id);

    post(route('shop.dev.versions.withdraw', $version->id))->assertSessionHasErrors('withdraw');

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT);

    $this->assertDatabaseMissing('operation_log', [
        'action'    => 'skill.version.withdraw',
        'target_id' => $version->id,
    ]);
});

it('撤回提审：动他人的版本 → 404，且对方版本一字未改', function () {
    [$custA] = swoOnboarded('swo_a_');

    [, $profileB] = swoOnboarded('swo_b_');   // 此刻身份是 B
    $productB = swoSkill('B的技能');
    $versionB = swoVersion($productB, SkillVersion::STATUS_IN_REVIEW, $profileB->seller_id);

    actingAs($custA, 'developer');            // 换回 A，去撤回 B 的版本

    post(route('shop.dev.versions.withdraw', $versionB->id))->assertNotFound();

    expect($versionB->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    $this->assertDatabaseMissing('operation_log', [
        'action'    => 'skill.version.withdraw',
        'target_id' => $versionB->id,
    ]);
});

// ==================== E · 下架 ====================

it('下架：商品置不可售(status=0)，但已售授权一字不动，留痕含理由', function () {
    [, $profile] = swoOnboarded();

    $productId = swoSkill('下架技能');

    // 模拟"已上架"（发布的结果）—— 直接把 flat 置 status=1
    DB::table('product_flat')->where('product_id', $productId)->update(['status' => 1]);
    expect((int) swoFlat($productId)->status)->toBe(1);

    // 一条已售授权（买家已买断）
    $buyer = swoCustomer('swo_buyer_');
    $entitlement = SkillEntitlement::create([
        'customer_id' => $buyer->id,
        'product_id'  => $productId,
        'status'      => SkillEntitlement::STATUS_ACTIVE,
        'license_key' => 'LK-'.uniqid(),
    ]);

    post(route('shop.dev.skills.offline', $productId), [
        'reason' => '技能存在缺陷，暂时下架整改',
    ])->assertRedirect();

    // 商品变不可售
    expect((int) swoFlat($productId)->status)->toBe(0);

    // ⚠ 不回收已售授权：买家的授权仍在、仍是 active（下架只停新增销售）
    $after = SkillEntitlement::find($entitlement->id);

    expect($after)->not->toBeNull()
        ->and($after->status)->toBe(SkillEntitlement::STATUS_ACTIVE);

    // 留痕：理由必存（这是影响买家可见性的动作）
    $this->assertDatabaseHas('operation_log', [
        'actor_type'  => OperationLog::ACTOR_SELLER,
        'actor_id'    => $profile->seller_id,
        'action'      => 'skill.offline',
        'target_type' => 'product',
        'target_id'   => $productId,
    ]);

    $log = DB::table('operation_log')
        ->where('action', 'skill.offline')->where('target_id', $productId)->first();

    expect(json_decode($log->payload, true)['reason'])->toBe('技能存在缺陷，暂时下架整改');
});

it('下架：理由必填（缺失 → 报错，未下架、不留痕）', function () {
    swoOnboarded();

    $productId = swoSkill();
    DB::table('product_flat')->where('product_id', $productId)->update(['status' => 1]);

    post(route('shop.dev.skills.offline', $productId), [])->assertSessionHasErrors('reason');

    // 没下架，也没留痕
    expect((int) swoFlat($productId)->status)->toBe(1);

    $this->assertDatabaseMissing('operation_log', [
        'action'    => 'skill.offline',
        'target_id' => $productId,
    ]);
});

it('下架：动他人的技能 → 404，且对方商品一字未改', function () {
    [$custA] = swoOnboarded('swo_a2_');

    [, $profileB] = swoOnboarded('swo_b2_');
    $productB = swoSkill('B的技能2');
    DB::table('product_flat')->where('product_id', $productB)->update(['status' => 1]);

    actingAs($custA, 'developer');

    post(route('shop.dev.skills.offline', $productB), ['reason' => '劫持下架'])->assertNotFound();

    expect((int) swoFlat($productB)->status)->toBe(1);

    $this->assertDatabaseMissing('operation_log', [
        'action'    => 'skill.offline',
        'target_id' => $productB,
    ]);
});
