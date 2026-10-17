<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperInviteService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * S-11d · 邀请码与入驻衔接 + 补 S-11a 的重复领取遗留。
 *
 * 拍板：邀请码**不是准入闸门**；码失效/被抢 → **降级为自助入驻 + 中性提示**，不硬失败。
 * API 侧本轮**不接受 invite_code**（body 里传了也不生效）。
 *
 * claim 的用例全部回查数据库（不读内存模型），原子性由"条件 UPDATE 的 affected 行数"保证。
 */

function s11dInvites(): DeveloperInviteService
{
    return new DeveloperInviteService();
}

function s11dCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'S11d',
        'last_name'   => 'Dev',
        'email'       => 's11d'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

it('a) 有效码 + 门户入驻：invite_id 落库、times_used=1、usage 一行、session 码清掉', function () {
    $invite = s11dInvites()->generate(null, 's11d-a', 1, now()->addDay());
    $customer = s11dCustomer();

    actingAs($customer, 'developer');

    $response = $this->withSession(['dev_invite_code' => $invite->code])
        ->post(route('shop.dev.onboarding.store'), [
            'dev_type'    => 'personal',
            'seller_name' => '邀请开发者',
        ]);

    $response->assertRedirect(route('shop.dev.agreements'))
        ->assertSessionMissing('dev_invite_code');

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();

    expect((int) $profile->invite_id)->toBe((int) $invite->id)
        ->and((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(1)
        ->and(DB::table('developer_invite_usage')
            ->where('invite_id', $invite->id)
            ->where('customer_id', $customer->id)
            ->count())->toBe(1)
        // 成功领取只说"已记录邀请来源"，不吹成"资格已通过"
        ->and((string) session('success'))->toContain('已记录邀请来源');
});

it('b) 失效码（不存在/作废/过期/用完）：仍入驻成功、invite_id=null、times_used 不变、页面中性提示', function () {
    $svc = s11dInvites();

    $cases = [];

    $cases['not_found'] = ['code' => 'NOSUCHCODE', 'invite_id' => null, 'before' => null];

    $revoked = $svc->generate(null, 's11d-b-revoked');
    DB::table('developer_invite')->where('id', $revoked->id)->update([
        'status' => DeveloperInvite::STATUS_REVOKED,
    ]);
    $cases['revoked'] = ['code' => $revoked->code, 'invite_id' => $revoked->id, 'before' => 0];

    $expired = $svc->generate(null, 's11d-b-expired', 1, now()->subDay());
    $cases['expired'] = ['code' => $expired->code, 'invite_id' => $expired->id, 'before' => 0];

    $exhausted = $svc->generate(null, 's11d-b-exhausted', 1);
    $svc->claim($exhausted, 999810, '10.0.0.1', 'UA-s11d');
    $cases['exhausted'] = ['code' => $exhausted->code, 'invite_id' => $exhausted->id, 'before' => 1];

    foreach ($cases as $state => $case) {
        $customer = s11dCustomer();
        actingAs($customer, 'developer');

        $response = $this->withSession(['dev_invite_code' => $case['code']])
            ->post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);

        $response->assertRedirect(route('shop.dev.agreements'));

        $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();

        expect($profile->invite_id)->toBeNull("case={$state}：失效码不得写入 invite_id");

        if ($case['invite_id'] !== null) {
            expect((int) DB::table('developer_invite')->where('id', $case['invite_id'])->value('times_used'))
                ->toBe($case['before'], "case={$state}：失效码不得烧次数");
        }

        // 跳转后的协议页必须出现中性提示（不报成失败）
        $page = $this->get(route('shop.dev.agreements'));

        $page->assertOk()
            ->assertSee('邀请码已失效')
            ->assertSee('自助通道')
            ->assertDontSee('入驻失败');
    }
});

it('c) 同一人重复提交入驻：幂等 —— 不重复领、usage 仍一行、times_used 仍 1', function () {
    $invite = s11dInvites()->generate(null, 's11d-c', 3, now()->addDay());
    $customer = s11dCustomer();

    actingAs($customer, 'developer');

    $this->withSession(['dev_invite_code' => $invite->code])
        ->post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal'])
        ->assertRedirect(route('shop.dev.agreements'));

    // 第二次再提交（模拟重复点击 / 回退再提交）；成功领取后 session 已清，这里重新塞回
    $this->withSession(['dev_invite_code' => $invite->code])
        ->post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal'])
        ->assertRedirect(route('shop.dev.agreements'));

    expect(DeveloperProfile::where('customer_id', $customer->id)->count())->toBe(1)
        ->and((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(1)
        ->and(DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count())->toBe(1);
});

it('d) 服务层：max_uses=2 的码，同一 customer 连续 claim 两次 → true / false（不抛异常），次数只 +1', function () {
    $svc = s11dInvites();
    $invite = $svc->generate(null, 's11d-d', 2);

    $first = $svc->claim($invite, 999841, '10.0.0.2', 'UA-s11d');
    $second = $svc->claim($invite, 999841, '10.0.0.2', 'UA-s11d');

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($svc->alreadyClaimedBy($invite, 999841))->toBeTrue()
        ->and((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(1)
        ->and(DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count())->toBe(1);
});

it('e) 并发语义：max_uses=1，两位不同 customer 依次 claim → 一真一假', function () {
    // 验证方式：claim() 是一条带全部条件的 UPDATE，成败只看 affected 行数。
    // 这里用两次**顺序**调用模拟竞争（同一进程内无法制造真正的并行事务；
    // 真正并发时，两个 UPDATE 在数据库层串行化，只有第一个的 WHERE 命中）。
    $svc = s11dInvites();
    $invite = $svc->generate(null, 's11d-e', 1);

    $first = $svc->claim($invite, 999851, '10.0.0.3', 'UA-A');
    $second = $svc->claim($invite, 999852, '10.0.0.4', 'UA-B');

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(1)
        ->and(DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count())->toBe(1);
});

it('f) 无码入驻：行为与改动前一致（invite_id null、无 usage、原提示语）', function () {
    $customer = s11dCustomer();

    actingAs($customer, 'developer');

    $this->post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal'])
        ->assertRedirect(route('shop.dev.agreements'));

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();

    expect($profile->invite_id)->toBeNull()
        ->and(DB::table('developer_invite_usage')->where('customer_id', $customer->id)->count())->toBe(0)
        ->and((string) session('success'))->toContain('入驻信息已提交');
});

it('g) API 不接受 invite_code：body 里传了也不生效，且不打红正常调用', function () {
    $invite = s11dInvites()->generate(null, 's11d-g', 1, now()->addDay());
    $customer = s11dCustomer();

    $token = postJson(route('shop.api.dev.auth.token'), [
        'email'    => $customer->email,
        'password' => 'secret123',
    ])->assertOk()->json('data.token');

    $headers = ['Authorization' => 'Bearer '.$token];

    $response = postJson(route('shop.api.dev.profile.store'), [
        'dev_type'    => 'personal',
        'invite_code' => $invite->code,
    ], $headers);

    // 额外字段被 validate 静默忽略（不是 ValidationException / 未知字段错误）
    $response->assertStatus(201)
        ->assertJsonMissingValidationErrors(['invite_code']);

    expect($response->json())->not->toHaveKey('errors');

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();

    expect($profile->invite_id)->toBeNull()
        ->and((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(0)
        ->and(DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count())->toBe(0);
});
