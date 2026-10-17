<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Webkul\Core\Models\CoreConfig;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperInviteService;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * S-14 · 邀请 session 跨 guard 的三段跳 —— 端到端验证。
 *
 * 背景：S-11c 在 `/dev/join` 把邀请码写进 session，S-11d 在建 developer_profile
 * 时读取。但真实用户中间要经过三段跳：
 *
 *   C 端注册 → C 端登录 → 开发者登录
 *
 * 此前只测过「落地页写 session → 入驻读 session」，没有验证这三段跳之后码还在。
 * 本测试**只走真实 HTTP 路由**，逐跳断言，哪一跳丢码就红在哪一跳。
 *
 * ⚠ 为什么显式搬运 session cookie：
 *   Laravel 的测试客户端**不会**像浏览器那样自动把上一跳响应里的 cookie
 *   带回下一跳（MakesHttpRequests 只发送显式 withCookie/withCookies 的值）。
 *   若不搬运，第二跳起就是全新会话 —— 那测到的是测试客户端的限制，不是产品行为。
 *   这里的 `$carry` 只做浏览器本来就会做的事：取响应里的 session cookie，
 *   发到下一次请求。session 内容与 regenerate 行为全部由真实中间件/guard 决定。
 *
 * 邀请码不是准入闸门：本测试验证的是「带得住的要带住」，不是「带不住就拒绝入驻」。
 */
it('邀请码跨 C 端注册 → C 端登录 → 开发者登录 三段跳后仍在，入驻正常领取', function () {
    Mail::fake();

    // 关闭邮箱验证，让注册出来的账号可以直接走 C 端登录（与 RegistrationPageTest 同做法）。
    CoreConfig::where('code', 'customer.settings.email.verification')->update(['value' => 0]);

    $invite = (new DeveloperInviteService())->generate(null, 's14-cross-guard', 1, now()->addDay());
    $email = 's14'.uniqid().'@example.com';
    $password = 'secret123';
    $sessionCookie = config('session.cookie');

    // 把上一跳响应里的 session cookie 带到下一跳（浏览器行为）。
    $carry = function ($response) use ($sessionCookie) {
        $cookie = $response->getCookie($sessionCookie);

        if (! $cookie) {
            $this->fail('响应里没有 session cookie，无法模拟同一会话继续下一跳');
        }

        $this->withCookie($sessionCookie, $cookie->getValue());

        return $response;
    };

    // 0) 未登录打开邀请落地页：码写入 session
    $landing = $carry(get(route('shop.dev.join', ['code' => $invite->code])));

    $landing->assertOk()
        ->assertSessionHas('dev_invite_code', $invite->code);

    // 1) C 端注册
    $register = $carry(post(route('shop.customers.register.store'), [
        'first_name'            => 'S14',
        'last_name'             => 'CrossGuard',
        'email'                 => $email,
        'password'              => $password,
        'password_confirmation' => $password,
    ]));

    $register->assertRedirect(route('shop.customer.session.index'));

    $this->assertSame(
        $invite->code,
        session('dev_invite_code'),
        '第 1 跳（C 端注册）后邀请码丢了'
    );

    $sessionIdAfterRegister = session()->getId();

    // 2) C 端登录
    $customerLogin = $carry(post(route('shop.customer.session.create'), [
        'email'    => $email,
        'password' => $password,
    ]));

    $customerLogin->assertSessionMissing('error')
        ->assertSessionMissing('warning')
        ->assertSessionMissing('info');

    expect(auth('customer')->check())->toBeTrue('C 端登录未成功，后续断言无意义');

    // 证据：这一跳确实换过 session id（SessionGuard::login 内部 migrate）。
    // 码若只是"同 id 下没动过"，就测不到 regenerate 前后是否还在。
    expect(session()->getId())->not->toBe(
        $sessionIdAfterRegister,
        'C 端登录没有 regenerate session id，本跳未覆盖"regenerate 后码是否还在"'
    );

    $this->assertSame(
        $invite->code,
        session('dev_invite_code'),
        '第 2 跳（C 端登录，session 已 regenerate）后邀请码丢了'
    );

    $sessionIdAfterCustomerLogin = session()->getId();

    // 3) 开发者登录（同一账号、另一套 guard）
    $devLogin = $carry(post(route('shop.dev.login.attempt'), [
        'email'    => $email,
        'password' => $password,
    ]));

    $devLogin->assertRedirect(route('shop.dev.dashboard'));

    expect(auth('developer')->check())->toBeTrue('开发者登录未成功，后续断言无意义');

    // 证据：开发者登录也显式 regenerate（PortalController::login），同样要跨过。
    expect(session()->getId())->not->toBe(
        $sessionIdAfterCustomerLogin,
        '开发者登录没有 regenerate session id，本跳未覆盖"regenerate 后码是否还在"'
    );

    $this->assertSame(
        $invite->code,
        session('dev_invite_code'),
        '第 3 跳（开发者登录，session 已 regenerate）后邀请码丢了'
    );

    // 4) 入驻：码在「建 profile 的这一刻」被领取
    $onboarding = $carry(post(route('shop.dev.onboarding.store'), [
        'dev_type'    => 'personal',
        'seller_name' => 'S14 跨 guard 开发者',
    ]));

    $onboarding->assertRedirect(route('shop.dev.agreements'))
        ->assertSessionMissing('dev_invite_code');

    $customerId = DB::table('customers')->where('email', $email)->value('id');
    $profile = DeveloperProfile::where('customer_id', $customerId)->firstOrFail();

    expect((int) $profile->invite_id)->toBe((int) $invite->id)
        ->and((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(1)
        ->and(DB::table('developer_invite_usage')
            ->where('invite_id', $invite->id)
            ->where('customer_id', $customerId)
            ->count())->toBe(1);
});
