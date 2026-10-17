<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Seller;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * S-12c 修 · 存量主体"只审一层"护栏 —— 防一次误点撤掉别人的资格。
 *
 * 背景（S-12c 主动报告的未闭合）：对**存量形态**主体（总状态 approved、两层皆 NULL ——
 * S-12a「存量不追溯」留下的历史主体）**只单层通过**，`recomputeRealnameStatus()`
 * 会因"两层不齐 → pending"使其**瞬时降为 pending** —— 该主体立刻建不了技能、也上不了架。
 *
 * 拍板的护栏（本卡）：**只禁"单层通过"，不禁"单层驳回"**：
 *   e) 存量主体单层通过 → 被拒 + **库里状态一字不改**（总状态仍 approved、两层仍 NULL）+ 不留痕；
 *   f) 存量主体单层驳回 → **仍允许**（有意的资格撤销，S-12c 语义不变）；
 *   g) 非存量主体（两层 pending 的新主体 / 已审完的主体）单层通过 → **照旧允许**；
 *   h) 存量主体「两层一起通过」→ 两层 approved 且总状态**仍 approved**（S-12c 测试 e 回归）；
 *   详情页：存量主体单层「通过」按钮被禁用 + 同义提示；「两层一起通过」仍在。
 *
 * ⚠ 本卡只加**动作层护栏**，不动 `recomputeRealnameStatus()` 的规则本身。
 */

function rlgCustomer(string $prefix = 'rlg'): Customer
{
    return Customer::create([
        'first_name'  => 'Rlg',
        'last_name'   => 'Legacy',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 一个已入驻主体（customer + seller + developer_profile），overrides 覆盖任意列。 */
function rlgProfile(array $overrides = [], string $prefix = 'rlg'): DeveloperProfile
{
    $customer = rlgCustomer($prefix);

    $seller = Seller::create([
        'code'              => 'rlg_'.uniqid(),
        'name'              => '存量护栏测试开发者',
        'status'            => 'active',
        'owner_customer_id' => $customer->id,
    ]);

    return DeveloperProfile::create(array_merge([
        'customer_id'              => $customer->id,
        'seller_id'                => $seller->id,
        'dev_type'                 => DeveloperProfile::DEV_TYPE_COMPANY,
        'realname_status'          => DeveloperProfile::REALNAME_PENDING,
        'company_realname_status'  => DeveloperProfile::REALNAME_PENDING,
        'operator_realname_status' => DeveloperProfile::REALNAME_PENDING,
        'realname_submitted_at'    => now(),
        'company_name'             => '召唤机甲（上海）科技有限公司',
        'company_credit_code'      => '91310000MA1K35XY7Q',
        'operator_name'            => '张三',
        'operator_cert_no'         => '310101199001011234',
        'status'                   => DeveloperProfile::STATUS_ACTIVE,
    ], $overrides));
}

/** 存量形态：总状态 approved、两层皆 NULL（S-12a 存量不追溯）。 */
function rlgLegacy(string $prefix = 'rlg_legacy_'): DeveloperProfile
{
    return rlgProfile([
        'realname_status'          => DeveloperProfile::REALNAME_APPROVED,
        'company_realname_status'  => null,
        'operator_realname_status' => null,
        'realname_submitted_at'    => null,
    ], $prefix);
}

// ───────────────────────── e) 存量主体：单层通过被拒 + 状态一字不改 ─────────────────────────

it('e) 存量主体单层通过 → 被拒 + 库里状态一字不改（两层仍 NULL、总状态仍 approved）+ 不留痕', function () {
    $profile = rlgLegacy('rlg_e_');
    $this->loginAsAdmin();

    foreach ([DeveloperProfile::REALNAME_LAYER_COMPANY, DeveloperProfile::REALNAME_LAYER_OPERATOR] as $layer) {
        post(route('admin.customers.developers.realname.layer.approve', [$profile->id, $layer]))
            ->assertRedirect(route('admin.customers.developers.show', $profile->id))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '两层一起通过'));

        // 每次后都验一遍：状态**一字未改**
        $fresh = $profile->fresh();
        expect($fresh->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
            ->and($fresh->company_realname_status)->toBeNull()
            ->and($fresh->operator_realname_status)->toBeNull()
            ->and($fresh->isRealnameApproved())->toBeTrue();
    }

    // 一行痕都没多
    expect(DB::table('operation_log')->where('target_id', $profile->id)->count())->toBe(0);
});

// ───────────────────────── f) 存量主体：单层驳回仍允许 ─────────────────────────

it('f) 存量主体单层驳回 → 仍被允许（有意的资格撤销，S-12c 语义不变）', function () {
    $profile = rlgLegacy('rlg_f_');
    $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.layer.reject', [$profile->id, DeveloperProfile::REALNAME_LAYER_OPERATOR]), [
        'reason' => '营业执照已过期，需重新核验',
    ])->assertRedirect(route('admin.customers.developers.show', $profile->id));

    $fresh = $profile->fresh();
    expect($fresh->realname_status)->toBe(DeveloperProfile::REALNAME_REJECTED)
        ->and($fresh->operator_realname_status)->toBe(DeveloperProfile::REALNAME_REJECTED)
        ->and($fresh->isRealnameApproved())->toBeFalse();

    // 留痕一条（含 layer / 理由）
    $log = DB::table('operation_log')
        ->where('action', 'developer.realname.layer.reject')
        ->where('target_id', $profile->id)
        ->first();

    expect($log)->not->toBeNull();
    $payload = json_decode($log->payload, true);
    expect($payload['layer'])->toBe(DeveloperProfile::REALNAME_LAYER_OPERATOR)
        ->and($payload['reason'])->toBe('营业执照已过期，需重新核验');
});

// ───────────────────────── g) 非存量主体：单层通过照旧允许 ─────────────────────────

it('g) 非存量主体单层通过 → 照旧允许（新主体与已审完主体都不被误伤）', function () {
    $this->loginAsAdmin();

    // g-1：两层 pending 的新主体 → 单层通过允许
    $fresh = rlgProfile([], 'rlg_g1_');

    post(route('admin.customers.developers.realname.layer.approve', [$fresh->id, DeveloperProfile::REALNAME_LAYER_COMPANY]))
        ->assertRedirect(route('admin.customers.developers.show', $fresh->id))
        ->assertSessionHasNoErrors();

    $fresh = $fresh->fresh();
    expect($fresh->company_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($fresh->operator_realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($fresh->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING); // 只过一层仍 pending（有意）

    expect(DB::table('operation_log')
        ->where('action', 'developer.realname.layer.approve')
        ->where('target_id', $fresh->id)->count())->toBe(1);

    // g-2：已审完的主体（总状态 approved + 两层皆 approved）≠ 存量形态 → 单层通过照旧允许（护栏不误伤）
    $done = rlgProfile([
        'realname_status'          => DeveloperProfile::REALNAME_APPROVED,
        'company_realname_status'  => DeveloperProfile::REALNAME_APPROVED,
        'operator_realname_status' => DeveloperProfile::REALNAME_APPROVED,
    ], 'rlg_g2_');

    post(route('admin.customers.developers.realname.layer.approve', [$done->id, DeveloperProfile::REALNAME_LAYER_COMPANY]))
        ->assertRedirect(route('admin.customers.developers.show', $done->id))
        ->assertSessionHasNoErrors();

    expect($done->fresh()->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED);
});

// ───────────────────────── h) 存量主体：两层一起通过 → 仍 approved ─────────────────────────

it('h) 存量主体「两层一起通过」→ 两层 approved 且总状态仍 approved（S-12c 测试 e 回归）', function () {
    $profile = rlgLegacy('rlg_h_');
    $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.layer.approve_all', $profile->id))
        ->assertRedirect(route('admin.customers.developers.show', $profile->id))
        ->assertSessionHasNoErrors();

    $fresh = $profile->fresh();
    expect($fresh->company_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($fresh->operator_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($fresh->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($fresh->isRealnameApproved())->toBeTrue();

    // 两层各一条痕
    expect(DB::table('operation_log')
        ->where('action', 'developer.realname.layer.approve')
        ->where('target_id', $fresh->id)->count())->toBe(2);
});

// ───────────────────────── 详情页：存量主体单层「通过」按钮禁用 + 同义提示 ─────────────────────────

it('详情页护栏：存量主体单层「通过」按钮被禁用 + 同义提示；「两层一起通过」仍在', function () {
    $this->loginAsAdmin();

    // 存量主体 → 单层通过被禁用（稳定锚点 class）+ 提示 + 便捷入口仍在
    $legacy = rlgLegacy('rlg_ui_legacy_');

    get(route('admin.customers.developers.show', $legacy->id))
        ->assertOk()
        ->assertSee('legacy-approve-blocked', false)
        ->assertSeeText('单独通过一层会使其降为待审')
        ->assertSeeText('两层一起通过')
        ->assertSee(route('admin.customers.developers.realname.layer.approve_all', $legacy->id), false);

    // 非存量主体 → 单层通过照旧可点（没有禁用锚点、也没有该提示）
    $fresh = rlgProfile([], 'rlg_ui_new_');

    get(route('admin.customers.developers.show', $fresh->id))
        ->assertOk()
        ->assertDontSee('legacy-approve-blocked', false)
        ->assertDontSeeText('单独通过一层会使其降为待审')
        ->assertSeeText('两层一起通过');
});
