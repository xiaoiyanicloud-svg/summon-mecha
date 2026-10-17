<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperInviteService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * S-11e · 入驻进度可见性（dashboard 进度卡 + onboarding 邀请横幅）。
 *
 * 拍板：入驻 = 双通道（自助申请 + 邀请），邀请码**不是准入闸门**。
 * 本卡只**复用既有事实**（invite_id / 登录态 / 实名两层 / 协议 / 该 seller 下商品），
 * 不新增真相源；页面必须让开发者自己看出"我还差哪一步"。
 *
 * 口径红线（测试锁着）：
 *   · 实名一律写「平台人工审核（人工确认，非身份核验）」；
 *   · 页面**不得出现**"实名认证通过 / 已核验身份"；
 *   · 邀请码**不明文回显**。
 */

function s11eCustomer(string $prefix = 's11e'): Customer
{
    return Customer::create([
        'first_name'  => 'S11e',
        'last_name'   => 'Dev',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 走真实 HTTP 完成入驻（不签协议、不实名），返回 [customer, profile]。 */
function s11eOnboarded(string $prefix = 's11e'): array
{
    $customer = s11eCustomer($prefix);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal'])
        ->assertRedirect(route('shop.dev.agreements'));

    return [$customer, DeveloperProfile::where('customer_id', $customer->id)->firstOrFail()];
}

/** 直接给某个 seller 造一个技能商品（技能=商品，归属锚点是 seller_id）。 */
function s11eAddSkill(int $sellerId): void
{
    DB::table('products')->insert([
        'sku'        => 's11e-'.uniqid(),
        'type'       => 'simple',
        'seller_id'  => $sellerId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** 从渲染后的 HTML 里取出某一步的 <li> 片段（断言"直达链接属于进行中那一步"）。 */
function s11eStepHtml(string $html, string $key): string
{
    preg_match('/<li[^>]*data-step="'.preg_quote($key, '/').'"[^>]*>(.*?)<\/li>/s', $html, $m);

    return $m[1] ?? '';
}

it('a) 新入驻：五步渲染，①未使用邀请链接，③④⑤未完成，进行中指向实名', function () {
    s11eOnboarded();

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSeeText('入驻进度')
        ->assertSeeText('未使用邀请链接（不影响入驻）')
        // ① 邀请 / ② 注册都算完成（邀请码不是准入闸门）
        ->assertSee('data-step="invite" data-state="done"', false)
        ->assertSee('data-step="register" data-state="done"', false)
        // ③ 实名是第一个未完成 → 进行中
        ->assertSee('data-step="realname" data-state="current"', false)
        ->assertSee('data-step="agreement" data-state="pending"', false)
        ->assertSee('data-step="skill" data-state="pending"', false)
        ->assertSeeText('进行中');
});

it('b) 有 invite_id：①显示已记录来源；页面不出现码明文', function () {
    [, $profile] = s11eOnboarded();

    $invite = (new DeveloperInviteService())->generate(null, 's11e-b');
    $profile->update(['invite_id' => $invite->id]);

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSeeText('已记录来源')
        ->assertDontSeeText('未使用邀请链接')
        ->assertDontSee($invite->code);
});

it('c) 两层都 approved（重算总状态）：③实名显示完成', function () {
    [, $profile] = s11eOnboarded();

    $profile->update([
        'company_realname_status'  => DeveloperProfile::REALNAME_APPROVED,
        'operator_realname_status' => DeveloperProfile::REALNAME_APPROVED,
    ]);
    $profile->recomputeRealnameStatus();
    $profile->save();

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSee('data-step="realname" data-state="done"', false)
        ->assertSeeText('公司认证：已通过；操作人认证：已通过')
        // 实名完成后，进行中应推进到协议
        ->assertSee('data-step="agreement" data-state="current"', false);
});

it('d) 已签协议 → ④完成；该 seller 有商品 → ⑤完成', function () {
    [, $profile] = s11eOnboarded();

    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ])->assertRedirect();

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSee('data-step="agreement" data-state="done"', false)
        ->assertSee('data-step="skill" data-state="pending"', false);

    s11eAddSkill($profile->seller_id);

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSee('data-step="skill" data-state="done"', false)
        ->assertSeeText('已创建技能商品');
});

it('e) session 有邀请码 → onboarding 顶部出现横幅且不回显码；无码时不出现', function () {
    actingAs(s11eCustomer('s11e_e_with_'), 'developer');

    $this->withSession(['dev_invite_code' => 'S11E-BANNER-CODE'])
        ->get(route('shop.dev.onboarding'))
        ->assertOk()
        ->assertSee('data-invite-banner="1"', false)
        ->assertSeeText('你正在通过邀请链接入驻')
        ->assertSeeText('仍需完成实名与协议')
        ->assertDontSee('S11E-BANNER-CODE');

    actingAs(s11eCustomer('s11e_e_without_'), 'developer');

    // 上一条请求把码留在了测试会话里；明确清掉，才能验证"无码时不许出现空横幅"。
    $this->flushSession();

    get(route('shop.dev.onboarding'))
        ->assertOk()
        ->assertDontSee('data-invite-banner', false)
        ->assertDontSeeText('通过邀请链接入驻');
});

it('f) 页面口径：出现「平台人工审核」，不出现「实名认证通过 / 已核验身份」', function () {
    s11eOnboarded();

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSeeText('平台人工审核（人工确认，非身份核验）')
        ->assertDontSeeText('实名认证通过')
        ->assertDontSeeText('已核验身份');
});

it('g) 直达链接：进行中那一步的 href 指向正确的具名路由', function () {
    [, $profile] = s11eOnboarded();

    // 进行中 = 实名
    $html = get(route('shop.dev.dashboard'))->assertOk()->getContent();

    expect(s11eStepHtml($html, 'realname'))->toContain('href="'.route('shop.dev.realname').'"');

    // 实名完成后进行中 = 协议
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    $html = get(route('shop.dev.dashboard'))->assertOk()->getContent();

    expect(s11eStepHtml($html, 'agreement'))->toContain('href="'.route('shop.dev.agreements').'"');

    // 协议签完后进行中 = 创建技能
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ])->assertRedirect();

    $html = get(route('shop.dev.dashboard'))->assertOk()->getContent();

    expect(s11eStepHtml($html, 'skill'))->toContain('href="'.route('shop.dev.skills.create').'"');
});
