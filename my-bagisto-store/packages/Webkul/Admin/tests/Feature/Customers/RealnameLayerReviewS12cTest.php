<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\Seller;
use Webkul\Shop\Services\Developer\DeveloperSkillService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * S-12c · 运营两层审批台 + 逐层留痕 + 驳回通知开发者。
 *
 * 为什么有这张卡：实名拆了两层（S-12a/b），但运营侧只有"直接置总状态"一个入口 ——
 * 哪一层是谁何时审的、为什么被驳回，全都没留痕；开发者被驳回也无从得知。
 * 本文件把这条链闭成闭环，逐条钉死交付卡 R1–R5：
 *   a) 两层 pending → 逐层通过 → 两层 approved + 总状态 approved → **开发者能建技能**（服务层 + 门户双验）；
 *   b) 只通过一层 → 总状态仍 pending → 建技能**仍被拦**；
 *   c) 驳回一层（带理由）→ 总状态 rejected + 留痕含 layer/理由/审核人 + **开发者收到通知**；
 *   d) 驳回理由缺失 → 报错、状态不变、**不留痕**；
 *   e) **存量**：总状态 approved + 两层 NULL → 补审两层通过后总状态**仍 approved**（不许算回 pending）；
 *   f) 留痕条数：两层动作 = **两条**；「两层一起通过」也是**两条**；
 *   g) 权限：未登录 admin → 被既有 `admin` 中间件挡住（redirect 到后台登录）。
 *
 * ⚠ 判据是**总状态** `realname_status`（`DeveloperProfile::isRealnameApproved()`）——
 *   与 S-12a/b 一致；本卡**不动**它的取值与语义（上架闸门 `ownerQualification` 读的就是它）。
 */

function s12cCustomer(string $prefix = 's12c'): Customer
{
    return Customer::create([
        'first_name'  => 'S12c',
        'last_name'   => 'Review',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 一份"已入驻"的主体：customer + seller（归属锚点）+ developer_profile。 */
function s12cProfile(array $overrides = [], string $prefix = 's12c'): DeveloperProfile
{
    $customer = s12cCustomer($prefix);

    $seller = Seller::create([
        'code'              => 's12c_'.uniqid(),
        'name'              => '两层审批测试开发者',
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

/** 建技能前置的**唯一**判定（门户 + API 共用）—— 不是只看字段。 */
function s12cCanCreate(DeveloperProfile $profile): array
{
    return app(DeveloperSkillService::class)->canCreateSkill($profile->fresh());
}

// ───────────────────────── a) 逐层通过 → 总状态 approved → 能建技能 ─────────────────────────

it('a) 两层 pending → 运营逐层通过 → 两层 approved + 总状态 approved → 开发者能建技能', function () {
    $profile = s12cProfile();
    $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.layer.approve', [$profile->id, DeveloperProfile::REALNAME_LAYER_COMPANY]))
        ->assertRedirect(route('admin.customers.developers.show', $profile->id));

    // 只过一层 → 总状态仍 pending（下面 b 专门钉）
    expect($profile->fresh()->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING);

    post(route('admin.customers.developers.realname.layer.approve', [$profile->id, DeveloperProfile::REALNAME_LAYER_OPERATOR]))
        ->assertRedirect(route('admin.customers.developers.show', $profile->id));

    $profile = $profile->fresh();
    expect($profile->company_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($profile->operator_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($profile->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED);

    // 服务层：建技能前置通过（唯一判定，门户与 API 共用）
    expect(s12cCanCreate($profile)['ok'])->toBeTrue();

    // 端到端：开发者以门户身份真的把技能建出来了
    actingAs($profile->customer, 'developer');

    $before = DB::table('products')->where('seller_id', $profile->seller_id)->count();

    post(route('shop.dev.skills.store'), ['name' => '两层都过了才建的技能', 'price' => 199])
        ->assertSessionHasNoErrors();

    expect(DB::table('products')->where('seller_id', $profile->seller_id)->count())->toBe($before + 1);
});

// ───────────────────────── b) 只过一层 → 仍 pending → 仍被拦 ─────────────────────────

it('b) 只通过一层 → 总状态仍 pending → 建技能仍被拦（服务层 + 门户双验）', function () {
    $profile = s12cProfile();
    $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.layer.approve', [$profile->id, DeveloperProfile::REALNAME_LAYER_COMPANY]))
        ->assertRedirect();

    $profile = $profile->fresh();
    expect($profile->company_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($profile->operator_realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($profile->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($profile->isRealnameApproved())->toBeFalse();

    $gate = s12cCanCreate($profile);
    expect($gate['ok'])->toBeFalse()
        ->and($gate['code'])->toBe('OWNER_NOT_VERIFIED');

    // 绕开前端直接 POST 建技能 → 服务端仍拒
    actingAs($profile->customer, 'developer');

    $before = DB::table('products')->where('seller_id', $profile->seller_id)->count();

    post(route('shop.dev.skills.store'), ['name' => '只过一层还想建的技能', 'price' => 10])
        ->assertSessionHasErrors('realname');

    expect(DB::table('products')->where('seller_id', $profile->seller_id)->count())->toBe($before);
});

// ───────────────────────── c) 驳回一层（带理由）→ rejected + 留痕 + 通知开发者 ─────────────────────────

it('c) 驳回一层（带理由）→ 总状态 rejected + 留痕含 layer/理由/审核人 + 开发者收到通知', function () {
    $profile = s12cProfile();
    $admin   = $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.layer.approve', [$profile->id, DeveloperProfile::REALNAME_LAYER_COMPANY]))
        ->assertRedirect();

    post(route('admin.customers.developers.realname.layer.reject', [$profile->id, DeveloperProfile::REALNAME_LAYER_OPERATOR]), [
        'reason' => '操作人证件号与公司授权书不一致',
    ])->assertRedirect(route('admin.customers.developers.show', $profile->id));

    // 总状态 rejected（任一层 rejected → rejected）→ 失去资格
    $profile = $profile->fresh();
    expect($profile->realname_status)->toBe(DeveloperProfile::REALNAME_REJECTED)
        ->and($profile->isRealnameApproved())->toBeFalse();

    // 留痕：含 layer、驳回理由、审核人
    $log = DB::table('operation_log')
        ->where('action', 'developer.realname.layer.reject')
        ->where('target_id', $profile->id)
        ->where('actor_id', $admin->id)
        ->first();

    expect($log)->not->toBeNull();

    $payload = json_decode($log->payload, true);
    expect($payload['layer'])->toBe(DeveloperProfile::REALNAME_LAYER_OPERATOR)
        ->and($payload['reason'])->toBe('操作人证件号与公司授权书不一致')
        ->and($payload['realname_status'])->toBe(DeveloperProfile::REALNAME_REJECTED);

    // 开发者收到通知（复用 LifecycleNotifier → NotificationService 那条线）
    $note = Notification::query()
        ->where('recipient_type', 'customer')
        ->where('recipient_id', $profile->customer_id)
        ->where('type', 'realname.rejected')
        ->first();

    expect($note)->not->toBeNull()
        ->and($note->payload['layer'])->toBe(DeveloperProfile::REALNAME_LAYER_OPERATOR)
        ->and($note->payload['reason'])->toBe('操作人证件号与公司授权书不一致')
        ->and($note->content)->toContain('操作人层')
        ->and($note->content)->toContain('操作人证件号与公司授权书不一致');
});

// ───────────────────────── d) 驳回理由缺失 → 报错、状态不变、不留痕 ─────────────────────────

it('d) 驳回理由缺失 → 校验报错、状态不变、不留痕', function () {
    $profile = s12cProfile();
    $this->loginAsAdmin();

    $before = DB::table('operation_log')->where('target_id', $profile->id)->count();

    post(route('admin.customers.developers.realname.layer.reject', [$profile->id, DeveloperProfile::REALNAME_LAYER_COMPANY]))
        ->assertSessionHasErrors('reason');

    // 状态一字未改
    $profile = $profile->fresh();
    expect($profile->company_realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($profile->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING);

    // 一行痕都没多
    expect(DB::table('operation_log')->where('target_id', $profile->id)->count())->toBe($before);

    // 也没发通知
    expect(Notification::where('recipient_id', $profile->customer_id)->where('type', 'realname.rejected')->count())->toBe(0);
});

// ───────────────────────── e) 存量：两层 NULL 的老主体补审后仍 approved ─────────────────────────

it('e) 存量（总状态 approved + 两层 NULL）→ 补审两层通过 → 总状态**仍 approved**（不许算回 pending）', function () {
    // 历史主体：只写了 realname_status=approved，两层列为 NULL（S-12a 的"存量不追溯"）
    $profile = s12cProfile([
        'realname_status'          => DeveloperProfile::REALNAME_APPROVED,
        'company_realname_status'  => null,
        'operator_realname_status' => null,
        'realname_submitted_at'    => null,
    ], 's12c_legacy_');

    expect($profile->company_realname_status)->toBeNull()
        ->and($profile->operator_realname_status)->toBeNull()
        ->and($profile->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED);

    $this->loginAsAdmin();

    // 运营补审：两层一起通过（便捷按钮）
    post(route('admin.customers.developers.realname.layer.approve_all', $profile->id))
        ->assertRedirect(route('admin.customers.developers.show', $profile->id));

    $profile = $profile->fresh();

    // 核心：**仍然 approved** —— 不许因为"两层刚补上"就被算回 pending
    expect($profile->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($profile->company_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($profile->operator_realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED)
        ->and($profile->isRealnameApproved())->toBeTrue();

    // 补审后建技能前置照旧通过
    expect(s12cCanCreate($profile)['ok'])->toBeTrue();
});

// ───────────────────────── f) 留痕条数：两层 = 两条 ─────────────────────────

it('f) 逐层两次动作 = 两条痕（各带 layer）；「两层一起通过」也是两条', function () {
    // 逐层分别点
    $p1 = s12cProfile([], 's12c_f1_');
    $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.layer.approve', [$p1->id, DeveloperProfile::REALNAME_LAYER_COMPANY]));
    post(route('admin.customers.developers.realname.layer.approve', [$p1->id, DeveloperProfile::REALNAME_LAYER_OPERATOR]));

    $rows1 = DB::table('operation_log')
        ->where('action', 'developer.realname.layer.approve')
        ->where('target_id', $p1->id)
        ->pluck('payload')
        ->map(fn ($p) => json_decode($p, true)['layer'])
        ->all();

    expect($rows1)->toHaveCount(2)
        ->and($rows1)->toContain(DeveloperProfile::REALNAME_LAYER_COMPANY)
        ->and($rows1)->toContain(DeveloperProfile::REALNAME_LAYER_OPERATOR);

    // 便捷按钮（两层一起）—— 仍是两条，不是一条"总通过"
    $p2 = s12cProfile([], 's12c_f2_');

    post(route('admin.customers.developers.realname.layer.approve_all', $p2->id));

    $rows2 = DB::table('operation_log')
        ->where('action', 'developer.realname.layer.approve')
        ->where('target_id', $p2->id)
        ->pluck('payload')
        ->map(fn ($p) => json_decode($p, true)['layer'])
        ->all();

    expect($rows2)->toHaveCount(2)
        ->and($rows2)->toContain(DeveloperProfile::REALNAME_LAYER_COMPANY)
        ->and($rows2)->toContain(DeveloperProfile::REALNAME_LAYER_OPERATOR);

    // 绝不能出现一条"总通过"式留痕
    expect(DB::table('operation_log')
        ->where('target_id', $p2->id)
        ->whereIn('action', ['developer.realname.approve', 'developer.realname.layer.approve_all'])
        ->count())->toBe(0);
});

// ───────────────────────── g) 权限：非 admin → 被拒 ─────────────────────────

it('g) 未登录 admin 身份访问逐层动作 → 被既有 admin 中间件挡下（跳后台登录）', function () {
    $profile = s12cProfile();

    // 不带任何后台会话
    post(route('admin.customers.developers.realname.layer.approve', [$profile->id, DeveloperProfile::REALNAME_LAYER_COMPANY]))
        ->assertRedirect(route('admin.session.create'));

    post(route('admin.customers.developers.realname.layer.reject', [$profile->id, DeveloperProfile::REALNAME_LAYER_OPERATOR]), ['reason' => '越权试试'])
        ->assertRedirect(route('admin.session.create'));

    post(route('admin.customers.developers.realname.layer.approve_all', $profile->id))
        ->assertRedirect(route('admin.session.create'));

    // 一次动作都没落：状态原样 + 零留痕
    expect($profile->fresh()->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and(DB::table('operation_log')->where('target_id', $profile->id)->count())->toBe(0);
});

// ───────────────────────── h) 非法 layer → 明确报错 ─────────────────────────

it('h) 非法 layer → 422，状态不变、不留痕', function () {
    $profile = s12cProfile();
    $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.layer.approve', [$profile->id, 'not_a_layer']))
        ->assertStatus(422);

    post(route('admin.customers.developers.realname.layer.reject', [$profile->id, 'hacker']), ['reason' => 'x'])
        ->assertStatus(422);

    expect($profile->fresh()->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and(DB::table('operation_log')->where('target_id', $profile->id)->count())->toBe(0);
});

// ───────────────────────── i) 详情页渲染出逐层动作入口 ─────────────────────────

it('i) 运营详情页渲染出两层各自「通过/驳回」与「两层一起通过」入口 + 后果文案', function () {
    $profile = s12cProfile();
    $this->loginAsAdmin();

    get(route('admin.customers.developers.show', $profile->id))
        ->assertOk()
        ->assertSeeText('逐层审批')
        // 两层动作路由都出现在页面上（各两个 approve/reject）
        ->assertSee(route('admin.customers.developers.realname.layer.approve', [$profile->id, DeveloperProfile::REALNAME_LAYER_COMPANY]), false)
        ->assertSee(route('admin.customers.developers.realname.layer.approve', [$profile->id, DeveloperProfile::REALNAME_LAYER_OPERATOR]), false)
        ->assertSee(route('admin.customers.developers.realname.layer.reject', [$profile->id, DeveloperProfile::REALNAME_LAYER_COMPANY]), false)
        ->assertSee(route('admin.customers.developers.realname.layer.reject', [$profile->id, DeveloperProfile::REALNAME_LAYER_OPERATOR]), false)
        ->assertSee(route('admin.customers.developers.realname.layer.approve_all', $profile->id), false)
        ->assertSeeText('两层一起通过')
        // R5 后果文案：驳回任一层 → 既不能创建技能、也不能上架
        ->assertSeeText('既不能创建技能，也不能上架');
});
