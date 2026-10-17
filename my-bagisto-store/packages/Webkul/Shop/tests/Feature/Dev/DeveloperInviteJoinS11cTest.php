<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\Shop\Services\Developer\DeveloperInviteService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * S-11c · 被邀请者落地页（`/dev/join?code=`）。
 *
 * 本卡只做「凭链接进来、把码带住、说清下一步」：
 *   - 码只写 session，**不 claim、不落 usage**（领取属 S-11d）；
 *   - 失效原因四种分开说；
 *   - 邀请码不是准入闸门（实名 + 协议才是），页面不许出现"免审核 / 免实名 / 直接入驻"。
 */

function s11cService(): DeveloperInviteService
{
    return new DeveloperInviteService();
}

function s11cCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Invite',
        'last_name'   => 'Dev',
        'email'       => 's11c'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

it('有效码 + 未登录：200 落地页，说明仍需实名与协议，登录 / 注册两个入口都在', function () {
    $invite = s11cService()->generate(null, 's11c-a', 1, now()->addDay());

    $response = get(route('shop.dev.join', ['code' => $invite->code]));

    $response->assertOk()
        ->assertSee('仍需实名与协议')
        ->assertSee(route('shop.dev.login'), false)
        ->assertSee(route('shop.customers.register.index'), false);
});

it('有效码：写进 session；不产生 usage 行、times_used 不变（刷新不烧码）', function () {
    $invite = s11cService()->generate(null, 's11c-b', 1, now()->addDay());

    $response = get(route('shop.dev.join', ['code' => $invite->code]));

    $response->assertOk()
        ->assertSessionHas('dev_invite_code', $invite->code);

    $assertNoBurn = function () use ($invite) {
        expect((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(0)
            ->and(DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count())->toBe(0);
    };

    $assertNoBurn();

    // 刷新一次：码仍在会话里，且依旧没有被"领取"
    $second = get(route('shop.dev.join', ['code' => $invite->code]));

    $second->assertOk()->assertSessionHas('dev_invite_code', $invite->code);

    $assertNoBurn();
});

it('有效码 + 已登录开发者：重定向到入驻页，且码带进会话', function () {
    $invite = s11cService()->generate(null, 's11c-c', 1, now()->addDay());

    actingAs(s11cCustomer(), 'developer');

    get(route('shop.dev.join', ['code' => $invite->code]))
        ->assertRedirect(route('shop.dev.onboarding'))
        ->assertSessionHas('dev_invite_code', $invite->code);
});

it('四种失效：四种不同文案，各含关键语义词', function () {
    $svc = s11cService();

    // 1) 不存在
    $missing = 'NOSUCHCODE';

    // 2) 已作废
    $revoked = $svc->generate(null, 's11c-revoked');
    DB::table('developer_invite')->where('id', $revoked->id)->update([
        'status' => DeveloperInvite::STATUS_REVOKED,
    ]);

    // 3) 已过期
    $expired = $svc->generate(null, 's11c-expired', 1, now()->subDay());

    // 4) 已用完
    $exhausted = $svc->generate(null, 's11c-exhausted', 1);
    $svc->claim($exhausted, 999700, '10.0.0.1', 'UA-s11c');

    $bodies = [];

    foreach ([$missing, $revoked->code, $expired->code, $exhausted->code] as $code) {
        $response = get(route('shop.dev.join', ['code' => $code]));
        $response->assertOk();
        $bodies[] = $response->getContent();
    }

    // 四种原因 → 四个**不同**的页面（不许笼统合并成同一句"无效"）
    expect(array_unique($bodies))->toHaveCount(4);

    expect($bodies[0])->toContain('不存在')
        ->and($bodies[1])->toContain('作废')
        ->and($bodies[2])->toContain('过期')
        ->and($bodies[3])->toContain('用完');
});

it('空 code：提示缺码，不炸（200）', function () {
    get(route('shop.dev.join'))
        ->assertOk()
        ->assertSee('邀请链接里缺少邀请码')
        ->assertSee('重新索取邀请码')
        ->assertSee(route('shop.dev.login'), false)
        ->assertSee(route('shop.customers.register.index'), false);
});

it('页面不出现"免审核 / 免实名 / 直接入驻"这类话', function () {
    $invite = s11cService()->generate(null, 's11c-f', 1, now()->addDay());

    $okPage = get(route('shop.dev.join', ['code' => $invite->code]));
    $okPage->assertOk()
        ->assertDontSee('免审核')
        ->assertDontSee('免实名')
        ->assertDontSee('直接入驻')
        ->assertDontSee('无需审核')
        ->assertDontSee('无需实名');

    $badPage = get(route('shop.dev.join', ['code' => 'NOSUCHCODE']));
    $badPage->assertOk()
        ->assertDontSee('免审核')
        ->assertDontSee('免实名')
        ->assertDontSee('直接入驻');
});

it('限流：同一 IP 连打超过阈值 → 429（复用既有 login 档位）', function () {
    $invite = s11cService()->generate(null, 's11c-g', 1, now()->addDay());

    // login 档位 = 20/分，按 email|ip 计；本路由无 email，等价按 IP。
    for ($i = 1; $i <= 20; $i++) {
        get(route('shop.dev.join', ['code' => $invite->code]))->assertOk();
    }

    get(route('shop.dev.join', ['code' => $invite->code]))->assertStatus(429);
});
