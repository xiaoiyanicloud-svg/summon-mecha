<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Catalog\SkillReviewController;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 门户 · 申请上架（任务卡 S-3a）。
 *
 * 拍板：上架权归运营（发布 = 承担交易与合规责任的动作）。开发者侧只给「申请上架」这一
 * **交接信号** —— 它**不是发布**。本套件锁四件事：
 *   ① 闸门已过、未上架 → 门户如实说"等待平台上架"并给「申请上架」，**不再**说"可以发布"；
 *   ② 申请 → 只写一条交接信号（operation_log），运营审核台该行标出"开发者已申请上架"；
 *   ③ 幂等：同版本重复申请仍只有一条信号；
 *   ④ **零副作用**：申请前后 product.status / visible_individually / skill_versions.status /
 *      audit_record 一字不变（申请 ≠ 发布）。
 */

function dlrCustomer(string $prefix = 'dlr'): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'List',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 走真实 HTTP 完成「入驻 → 签协议」，返回 [customer, profile]。 */
function dlrOnboarded(string $prefix = 'dlr'): array
{
    $customer = dlrCustomer($prefix);

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

/** 通过门户建一个技能，返回商品 id。 */
function dlrSkill(DeveloperProfile $profile, string $name = '申请上架技能'): int
{
    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 88])->assertRedirect();

    return (int) DB::table('products')->where('seller_id', $profile->seller_id)->orderByDesc('id')->value('id');
}

function dlrVersion(int $productId, int $sellerId, string $status): SkillVersion
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

function dlrAudit(int $productId, int $versionId, int $layer, int $result): AuditRecord
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

/** 让某版本"闸门已过"（L0 + L2 都通过；required_layers 默认 [1,3]）。 */
function dlrApprove(int $productId, int $versionId): void
{
    dlrAudit($productId, $versionId, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    dlrAudit($productId, $versionId, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);
}

/** 商品在默认渠道/语言下的 flat 行。 */
function dlrFlat(int $productId): object
{
    $channel = core()->getDefaultChannelCode();
    $locale = (string) (optional(core()->getDefaultChannel()->default_locale)->code ?: $channel);

    return DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('channel', $channel)
        ->where('locale', $locale)
        ->first();
}

/**
 * 运营审核台某版本的视图行（**不渲染**，规避 Shop 套件注册店铺主题导致 Admin 布局解析失败）。
 * 口径与运营实际看到的一致（走的是真控制器）。
 */
function dlrQueueRow(int $versionId): ?array
{
    $view = app(SkillReviewController::class)
        ->index(Request::create('/admin/catalog/skill-reviews?status=approved', 'GET'));

    return collect($view->getData()['rows'])->first(fn ($r) => $r['version']->id === $versionId);
}

// ==================== ① 门户如实说 + 给动作 ====================

it('已过闸门、未上架：门户说「等待平台上架」并给「申请上架」，不再说「可以发布」', function () {
    [, $profile] = dlrOnboarded();

    $productId = dlrSkill($profile);
    $version = dlrVersion($productId, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productId, $version->id);

    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        ->assertSeeText('等待平台上架')
        ->assertSeeText('申请上架')
        // 审核全过 ≠ 已上架：不再说"可以发布"（那句话会被读成"马上能上架"）
        ->assertDontSeeText('可以发布');
});

// ==================== ② 信号 + 留痕 + 运营可见 ====================

it('申请上架：写一条交接信号（operation_log），运营审核台该行标出「开发者已申请上架」', function () {
    [, $profile] = dlrOnboarded();

    $productId = dlrSkill($profile);
    $version = dlrVersion($productId, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productId, $version->id);

    // 申请前：运营侧无标注
    expect(dlrQueueRow($version->id)['listing_requested_at'])->toBeNull();

    post(route('shop.dev.skills.request-listing', $productId), ['version_id' => $version->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('operation_log', [
        'actor_type'  => OperationLog::ACTOR_SELLER,
        'actor_id'    => $profile->seller_id,
        'action'      => DeveloperSkillService::ACTION_LISTING_REQUESTED,
        'target_type' => DeveloperSkillService::TARGET_SKILL_VERSION,
        'target_id'   => $version->id,
    ]);

    // 运营审核台该行：出现"开发者已申请上架（时间）"
    $row = dlrQueueRow($version->id);

    expect($row)->not->toBeNull()
        ->and($row['listing_requested_at'])->toBe(now()->format('Y-m-d H:i'));
});

it('幂等：同版本重复申请不产生第二条信号（如实回"已申请过"）', function () {
    [, $profile] = dlrOnboarded();

    $productId = dlrSkill($profile);
    $version = dlrVersion($productId, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productId, $version->id);

    post(route('shop.dev.skills.request-listing', $productId), ['version_id' => $version->id])->assertRedirect();

    // 第二次申请：不报错、不重复写
    post(route('shop.dev.skills.request-listing', $productId), ['version_id' => $version->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('operation_log')
        ->where('action', DeveloperSkillService::ACTION_LISTING_REQUESTED)
        ->where('target_id', $version->id)
        ->count())->toBe(1);
});

it('已申请后：门户把「申请上架」按钮换成"已于 … 申请，等待处理"', function () {
    [, $profile] = dlrOnboarded();

    $productId = dlrSkill($profile);
    $version = dlrVersion($productId, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productId, $version->id);

    post(route('shop.dev.skills.request-listing', $productId), ['version_id' => $version->id])->assertRedirect();

    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        ->assertSeeText('已于')
        // 按钮（表单 action）消失 —— 不再给"重复申请"的入口
        ->assertDontSee(route('shop.dev.skills.request-listing', $productId));
});

// ==================== ③ 闸门未过：不给按钮，打路由也被拒 ====================

it('闸门未过：门户不给「申请上架」按钮；直接打路由被明确拒绝且不留信号', function () {
    [, $profile] = dlrOnboarded();

    // 技能名刻意不含"申请上架"字样 —— 否则 assertDontSeeText 会被技能名本身命中
    $productId = dlrSkill($profile, '未过闸门技能');
    $version = dlrVersion($productId, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    // 只过 L0，L2 未审 → 闸门未过
    dlrAudit($productId, $version->id, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);

    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        ->assertDontSeeText('申请上架')
        ->assertSeeText('还不能发布');

    post(route('shop.dev.skills.request-listing', $productId), ['version_id' => $version->id])
        ->assertSessionHasErrors('request_listing');

    // 错误码清楚（人话 + 机器可判）：GATE_NOT_PASSED
    $result = app(DeveloperSkillService::class)->requestListing($profile, $productId, $version->id);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('GATE_NOT_PASSED');

    $this->assertDatabaseMissing('operation_log', [
        'action'    => DeveloperSkillService::ACTION_LISTING_REQUESTED,
        'target_id' => $version->id,
    ]);
});

it('已可售（已上架）的版本：不能申请上架（拒 ALREADY_SELLABLE），不留信号', function () {
    [, $profile] = dlrOnboarded();

    $productId = dlrSkill($profile);
    $version = dlrVersion($productId, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productId, $version->id);

    // 模拟"已上架"：商品置可售
    DB::table('product_flat')->where('product_id', $productId)->update(['status' => 1]);

    post(route('shop.dev.skills.request-listing', $productId), ['version_id' => $version->id])
        ->assertSessionHasErrors('request_listing');

    // 错误码清楚：ALREADY_SELLABLE（已上架，没有可交接的东西）
    $result = app(DeveloperSkillService::class)->requestListing($profile, $productId, $version->id);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('ALREADY_SELLABLE');

    $this->assertDatabaseMissing('operation_log', [
        'action'    => DeveloperSkillService::ACTION_LISTING_REQUESTED,
        'target_id' => $version->id,
    ]);
});

// ==================== ④ 零副作用（最重要） ====================

it('零副作用：申请前后商品可售性 / 版本状态 / 审核记录一字不变（申请 ≠ 发布）', function () {
    [, $profile] = dlrOnboarded();

    $productId = dlrSkill($profile);
    $version = dlrVersion($productId, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productId, $version->id);

    $auditQuery = fn () => AuditRecord::query()
        ->forVersion($productId, $version->id)
        ->orderBy('id')
        ->get(['id', 'layer', 'result', 'reason_category', 'reason_detail'])
        ->toArray();

    $before = [
        'flat_status'    => (int) dlrFlat($productId)->status,
        'flat_visible'   => (int) dlrFlat($productId)->visible_individually,
        'version_status' => $version->fresh()->status,
        'audit_count'    => count($auditQuery()),
        'audit_digest'   => $auditQuery(),
    ];

    post(route('shop.dev.skills.request-listing', $productId), ['version_id' => $version->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // product.status / visible_individually 不变
    expect((int) dlrFlat($productId)->status)->toBe($before['flat_status'])
        ->and((int) dlrFlat($productId)->visible_individually)->toBe($before['flat_visible'])
        // skill_versions.status 不变（申请**不**迁移状态）
        ->and($version->fresh()->status)->toBe($before['version_status'])
        // audit_record 条数与内容一字不变（申请**不**写审核记录）
        ->and(count($auditQuery()))->toBe($before['audit_count'])
        ->and($auditQuery())->toBe($before['audit_digest']);

    // 对照：版本仍是 in_review、商品仍不可售 —— 没有被顺手"发布"
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and((int) dlrFlat($productId)->status)->toBe(0);
});

// ==================== 归属（越权） ====================

it('申请他人的技能/版本 → 404，且不留痕', function () {
    [$custA] = dlrOnboarded('dlr_a_');

    [, $profileB] = dlrOnboarded('dlr_b_');
    $productB = dlrSkill($profileB, 'B的技能');
    $versionB = dlrVersion($productB, $profileB->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productB, $versionB->id);

    actingAs($custA, 'developer');

    post(route('shop.dev.skills.request-listing', $productB), ['version_id' => $versionB->id])
        ->assertNotFound();

    $this->assertDatabaseMissing('operation_log', [
        'action'    => DeveloperSkillService::ACTION_LISTING_REQUESTED,
        'target_id' => $versionB->id,
    ]);
});

it('技能 id 与版本 id 不匹配（都是我自己的）→ 404，不留痕', function () {
    [, $profile] = dlrOnboarded();

    $productX = dlrSkill($profile, '技能X');
    $productY = dlrSkill($profile, '技能Y');

    $versionY = dlrVersion($productY, $profile->seller_id, SkillVersion::STATUS_IN_REVIEW);
    dlrApprove($productY, $versionY->id);

    // 用技能 X 的 id 配技能 Y 的版本 id —— 归属链对不上，必须 404
    post(route('shop.dev.skills.request-listing', $productX), ['version_id' => $versionY->id])
        ->assertNotFound();

    $this->assertDatabaseMissing('operation_log', [
        'action'    => DeveloperSkillService::ACTION_LISTING_REQUESTED,
        'target_id' => $versionY->id,
    ]);
});
