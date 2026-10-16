<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Seller;
use Webkul\Shop\Models\SkillAsset;
use Webkul\User\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * S-12b + S-12d · 门户实名提交页 + 弹窗引导 + 运营只读展示（合并卡）。
 *
 * 锁的**行为**（逐条对应交付卡 R1–R5）：
 *   a) 未实名：dashboard / skills 列表页渲染出**弹窗锚点**（`:target` modal + 「去实名」指向实名页）；
 *      创建页仍可打开、如实提示"还不能创建技能"（口径不打架）；
 *   b) 提交两层资料 + 营业执照 → 两层 pending、`realname_submitted_at` 落库、
 *      文件落**私有盘**且路径/文件名入库；**public 盘没有**这个文件；
 *   c) 提交后 `recomputeRealnameStatus()` 使总状态 pending，且**建技能仍被拦**（闭环一致）；
 *   d) 已 approved → 再次提交被拒（错误码语义 + 文案），且库里状态**一字不改**；
 *   e) 缺字段 / 格式错 → **字段级**错误，**不落库、不落文件**；
 *   f) **绕开前端直接 POST 建技能** → 仍被服务端拒（证明弹窗只是引导）；
 *   g) 运营详情页：能看到两层资料与附件入口（渲染断言）。
 *
 * ⚠ 判据是**总状态** `realname_status`（`DeveloperProfile::isRealnameApproved()`）——
 *   与 S-12a 一致；本卡只把"两层 → 总状态"的调用点接上（S-12a 已实现规则）。
 */

function s12bdCustomer(string $prefix = 's12bd'): Customer
{
    return Customer::create([
        'first_name'  => 'S12bd',
        'last_name'   => 'Submit',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 走真实门户链路「入驻 → 签协议」，返回 [customer, profile]。入驻即 pending（未实名）。 */
function s12bdOnboarded(string $prefix = 's12bd'): array
{
    $customer = s12bdCustomer($prefix);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'company']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    return [$customer, DeveloperProfile::where('customer_id', $customer->id)->firstOrFail()];
}

/** 一份合法的提交载荷（不含文件）。 */
function s12bdPayload(array $overrides = []): array
{
    return array_merge([
        'company_name'        => '召唤机甲（上海）科技有限公司',
        'company_credit_code' => '91310000MA1K35XY7Q',
        'operator_name'       => '张三',
        'operator_cert_no'    => '310101199001011234',
    ], $overrides);
}

/** 营业执照附件（pdf）。 */
function s12bdLicense(string $name = 'license.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 50, 'application/pdf');
}

beforeEach(function () {
    // 大包/附件都落私有盘；两个盘都 fake，避免污染真实磁盘
    Storage::fake(SkillAsset::disk());
    Storage::fake('public');
});

// ───────────────────────── a) 未实名：弹窗锚点 + 创建页口径 ─────────────────────────

it('a) 未实名：skills 列表页渲染弹窗锚点，「创建技能」不跳创建页而是指向 :target 弹窗', function () {
    s12bdOnboarded();

    get(route('shop.dev.skills.index'))
        ->assertOk()
        // 弹窗容器 id 存在（纯 CSS :target 的锚点）
        ->assertSee('id="realname-modal"', false)
        // 「去实名」链到实名页（否则是死链）
        ->assertSee(route('shop.dev.realname'), false)
        // 未实名时「创建技能」按钮指向弹窗锚点，而不是创建页
        ->assertSee('href="#realname-modal"', false);
});

it('a) 未实名：dashboard 渲染弹窗锚点 + 非阻断提示条（已有技能不受影响）', function () {
    s12bdOnboarded();

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSee('id="realname-modal"', false)
        ->assertSee(route('shop.dev.realname'), false)
        // R3 存量非阻断提示
        ->assertSeeText('暂时不能')
        ->assertSeeText('已有技能不受影响');
});

it('a) 未实名：创建页仍可打开（200），且如实提示"还不能创建技能"（口径不打架）', function () {
    s12bdOnboarded();

    get(route('shop.dev.skills.create'))
        ->assertOk()
        ->assertSeeText('还不能创建技能');
});

it('a) 已实名：列表页 / dashboard **不**渲染弹窗锚点，「创建技能」正常跳创建页', function () {
    [, $profile] = s12bdOnboarded();

    $profile->company_realname_status  = DeveloperProfile::REALNAME_APPROVED;
    $profile->operator_realname_status = DeveloperProfile::REALNAME_APPROVED;
    $profile->recomputeRealnameStatus();
    $profile->save();

    get(route('shop.dev.skills.index'))
        ->assertOk()
        ->assertDontSee('id="realname-modal"', false)
        ->assertSee(route('shop.dev.skills.create'), false);

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertDontSee('id="realname-modal"', false);
});

// ───────────────────────── b) 提交：两层 pending + 私有盘落盘 ─────────────────────────

it('b) 提交两层资料 + 营业执照 → 两层 pending、submitted_at 落库、文件落私有盘、public 盘没有', function () {
    [, $profile] = s12bdOnboarded();

    post(route('shop.dev.realname.store'), array_merge(s12bdPayload(), [
        'license' => s12bdLicense('营业执照.pdf'),
    ]))->assertRedirect(route('shop.dev.realname'))
        ->assertSessionHasNoErrors();

    $profile = $profile->fresh();

    // 两层状态 → pending
    expect($profile->company_realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($profile->operator_realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($profile->realname_submitted_at)->not->toBeNull();

    // 文本字段落库
    expect($profile->company_name)->toBe('召唤机甲（上海）科技有限公司')
        ->and($profile->company_credit_code)->toBe('91310000MA1K35XY7Q')
        ->and($profile->operator_name)->toBe('张三')
        ->and($profile->operator_cert_no)->toBe('310101199001011234');

    // 路径/文件名入库，且真正落在**私有盘**
    expect($profile->company_cert_path)->not->toBeNull()
        ->and($profile->company_cert_name)->toBe('营业执照.pdf');

    Storage::disk(SkillAsset::disk())->assertExists($profile->company_cert_path);
    // 红线：同一个文件绝不能同时存在于 public 盘
    Storage::disk('public')->assertMissing($profile->company_cert_path);
});

it('b) 幂等：同一份重复提交不堆文件（同名覆盖，盘上仍只有一份）', function () {
    [, $profile] = s12bdOnboarded();

    post(route('shop.dev.realname.store'), array_merge(s12bdPayload(), ['license' => s12bdLicense('license.pdf')]))
        ->assertSessionHasNoErrors();

    post(route('shop.dev.realname.store'), array_merge(s12bdPayload(), ['license' => s12bdLicense('license.pdf')]))
        ->assertSessionHasNoErrors();

    // 私有盘上这个目录下只有 1 个文件（同名覆盖）
    $dir = dirname($profile->fresh()->company_cert_path);
    expect(Storage::disk(SkillAsset::disk())->files($dir))->toHaveCount(1);
});

// ───────────────────────── c) 提交后总状态 pending + 仍被拦 ─────────────────────────

it('c) 提交后总状态经 recompute 变 pending，且建技能**仍被拦**（不许"交了就能建"）', function () {
    [, $profile] = s12bdOnboarded();

    post(route('shop.dev.realname.store'), array_merge(s12bdPayload(), ['license' => s12bdLicense()]))
        ->assertSessionHasNoErrors();

    $profile = $profile->fresh();
    expect($profile->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING)
        ->and($profile->isRealnameApproved())->toBeFalse();

    // 绕开前端（直接 POST 建技能）→ 服务端仍拒（弹窗只是引导，见 f）
    $before = DB::table('products')->where('seller_id', $profile->seller_id)->count();

    post(route('shop.dev.skills.store'), ['name' => '交了实名就想建的技能', 'price' => 10])
        ->assertSessionHasErrors('realname');

    expect(DB::table('products')->where('seller_id', $profile->seller_id)->count())->toBe($before);
});

// ───────────────────────── d) 已 approved → 拒绝再次提交 ─────────────────────────

it('d) 任一层已 approved → 再次提交被拒（文案说"已通过"），且库里状态一字不改', function () {
    [, $profile] = s12bdOnboarded();

    // 先正常提交一次（拿到一份资料 + 一条路径）
    post(route('shop.dev.realname.store'), array_merge(s12bdPayload(), ['license' => s12bdLicense('first.pdf')]))
        ->assertSessionHasNoErrors();

    // 平台审核通过（两层都 approved，走真实 recomputeRealnameStatus()）
    $profile = $profile->fresh();
    $profile->company_realname_status  = DeveloperProfile::REALNAME_APPROVED;
    $profile->operator_realname_status = DeveloperProfile::REALNAME_APPROVED;
    $profile->recomputeRealnameStatus();
    $profile->save();

    $before = $profile->fresh()->only([
        'realname_status', 'company_realname_status', 'operator_realname_status',
        'company_name', 'company_credit_code', 'operator_name', 'operator_cert_no',
        'company_cert_path', 'company_cert_name',
    ]);

    // 想改主体（换公司/换操作人）→ 被拒
    post(route('shop.dev.realname.store'), array_merge(s12bdPayload([
        'company_name'        => '另一家公司',
        'company_credit_code' => '91310000MA1K35XY7Q',
    ]), ['license' => s12bdLicense('second.pdf')]))
        ->assertSessionHasErrors('realname');

    expect(session('errors')->first('realname'))->toContain('已通过');

    // 库里的状态与资料**一字不改**
    expect($profile->fresh()->only(array_keys($before)))->toBe($before);

    // 已 approved 的营业执照路径仍在，且**没有被新上传覆盖**（没落第二个文件）
    expect(Storage::disk(SkillAsset::disk())->exists($before['company_cert_path']))->toBeTrue();
});

// ───────────────────────── e) 缺字段 / 格式错 → 字段级错误，不落库不落文件 ─────────────────────────

it('e) 缺字段 → 字段级错误（各字段分开报），不落库、不落文件', function () {
    [, $profile] = s12bdOnboarded();

    post(route('shop.dev.realname.store'), [])
        ->assertSessionHasErrors(['company_name', 'company_credit_code', 'operator_name', 'operator_cert_no', 'license']);

    $profile = $profile->fresh();

    expect($profile->company_realname_status)->toBeNull()      // 一字未写
        ->and($profile->company_name)->toBeNull()
        ->and($profile->company_cert_path)->toBeNull()
        ->and($profile->realname_submitted_at)->toBeNull();

    // 私有盘上一个文件都没落
    expect(Storage::disk(SkillAsset::disk())->allFiles())->toBeEmpty();
});

it('e) 信用代码格式错（长度/字符集）→ 错在 company_credit_code 这个字段，不落库', function () {
    [, $profile] = s12bdOnboarded();

    post(route('shop.dev.realname.store'), array_merge(
        s12bdPayload(['company_credit_code' => 'ABC123']),   // 太短
        ['license' => s12bdLicense()]
    ))->assertSessionHasErrors('company_credit_code');

    expect($profile->fresh()->company_credit_code)->toBeNull()
        ->and(Storage::disk(SkillAsset::disk())->allFiles())->toBeEmpty();
});

// ───────────────────────── f) 绕开前端直接建技能 → 仍被拒 ─────────────────────────

it('f) 绕开前端直接 POST 建技能 → 服务端仍拒（弹窗只是引导，不是闸门）', function () {
    [, $profile] = s12bdOnboarded();

    $before = DB::table('products')->where('seller_id', $profile->seller_id)->count();

    post(route('shop.dev.skills.store'), ['name' => '绕过弹窗的技能', 'price' => 10])
        ->assertSessionHasErrors('realname');

    expect(DB::table('products')->where('seller_id', $profile->seller_id)->count())->toBe($before);
});

// ───────────────────────── g) 运营详情页：只读展示两层资料 + 附件入口 ─────────────────────────

it('g) 运营详情页能看到两层资料与营业执照下载入口（渲染断言）', function () {
    // ⚠ 这里**刻意**在模型层直接造一份"已提交"的两层资料，而**不**先打门户路由：
    //   同一个测试进程里，先渲染 shop 视图会把 `themes()` 单例钉在 shop 主题上，
    //   之后渲染 Admin 布局时 `bagisto_asset()` 会去 shop 的 Vite manifest 找 Admin 的资源而报错
    //   （测试基建的既有现象，与本卡无关）。端到端的"门户提交 → 落库"由 (b) 覆盖。
    $customer = s12bdCustomer('s12bdadmin');

    $seller = Seller::create([
        'code'              => 's12bd_'.uniqid(),
        'name'              => '测试开发者',
        'status'            => 'active',
        'owner_customer_id' => $customer->id,
    ]);

    $profile = DeveloperProfile::create([
        'customer_id'               => $customer->id,
        'seller_id'                 => $seller->id,
        'dev_type'                  => DeveloperProfile::DEV_TYPE_COMPANY,
        'realname_status'           => DeveloperProfile::REALNAME_PENDING,
        'company_realname_status'   => DeveloperProfile::REALNAME_PENDING,
        'operator_realname_status'  => DeveloperProfile::REALNAME_PENDING,
        'realname_submitted_at'     => now(),
        'company_name'              => '召唤机甲（上海）科技有限公司',
        'company_credit_code'       => '91310000MA1K35XY7Q',
        'operator_name'             => '张三',
        'operator_cert_no'          => '310101199001011234',
        'company_cert_path'         => 'realname/'.$seller->id.'/license.pdf',
        'company_cert_name'         => '营业执照.pdf',
        'status'                    => DeveloperProfile::STATUS_ACTIVE,
    ]);

    // 运营身份（Admin::factory role_id=1 全权限，Admin 测试同款）
    actingAs(Admin::factory()->create(), 'admin');

    get(route('admin.customers.developers.show', $profile->id))
        ->assertOk()
        ->assertSeeText('实名两层资料')
        ->assertSeeText('91310000MA1K35XY7Q')
        ->assertSeeText('张三')
        ->assertSeeText('310101199001011234')
        // 营业执照文件名 + 私有盘下载入口
        ->assertSeeText('营业执照.pdf')
        ->assertSee(route('admin.customers.developers.cert.download', $profile->id), false);
});
