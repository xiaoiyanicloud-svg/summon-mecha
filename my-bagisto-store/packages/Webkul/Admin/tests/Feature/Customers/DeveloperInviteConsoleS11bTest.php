<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * S-11b · 开发者邀请码运营端（Admin）。
 *
 * 口径（负责人拍板）：邀请码**不是准入闸门** —— 它只提供「落地路由 + 归属 + 来源留痕」，
 * 准入闸门是「实名 + 协议」。所以本文件钉死的是"发码 / 作废 / 看使用情况"，
 * 并反向断言页面**没有**出现"持码免审核 / 用码直接入驻"这类话。
 *
 * 逐条对应交付卡：
 *   a) 生成 → DB 只有一条、max_uses=1、status=active、created_by=当前 admin；列表能看到**明文码**；
 *   b) 生成 / 作废各写一条 operation_log，payload **不含明文 code**（只有掩码）；
 *   c) 作废 → revoked；再次作废 → 幂等提示、不 500、不重复留痕；
 *   d) 未登录 / 无该 ACL 的 admin → 被挡；
 *   e) 页面口径断言：必须写明"邀请码不是准入闸门 / 仍需实名与协议"。
 */

// ───────────────────────── a) 生成一次性邀请码 + 列表明文可见 ─────────────────────────

it('a) 运营生成邀请码：DB 只有一条、一次性、active、created_by=当前 admin', function () {
    $admin = $this->loginAsAdmin();

    $before = DB::table('developer_invite')->count();

    post(route('admin.customers.developer-invites.store'), [
        'note'            => '发给某某机器人公司-张工',
        'expires_in_days' => 7,
    ])
        ->assertRedirect(route('admin.customers.developer-invites.index'))
        ->assertSessionHas('success');

    expect(DB::table('developer_invite')->count())->toBe($before + 1);

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    expect($invite)->not->toBeNull()
        ->and((int) $invite->max_uses)->toBe(1)
        ->and((int) $invite->times_used)->toBe(0)
        ->and($invite->status)->toBe(DeveloperInvite::STATUS_ACTIVE)
        ->and((int) $invite->created_by)->toBe($admin->id)
        ->and($invite->note)->toBe('发给某某机器人公司-张工')
        ->and($invite->expires_at)->not->toBeNull();
});

it('a) 运营生成邀请码支持指定自定义码与可用次数', function () {
    $admin = $this->loginAsAdmin();
    $custom = 'CUSTOM' . strtoupper(substr(uniqid(), -4));

    post(route('admin.customers.developer-invites.store'), [
        'custom_code'     => $custom,
        'max_uses'        => 5,
        'note'            => '供应商多账号测试',
        'expires_in_days' => 30,
    ])
        ->assertRedirect(route('admin.customers.developer-invites.index'))
        ->assertSessionHas('success');

    $invite = DB::table('developer_invite')->where('code', $custom)->first();
    expect($invite)->not->toBeNull()
        ->and((int) $invite->max_uses)->toBe(5)
        ->and((int) $invite->times_used)->toBe(0)
        ->and($invite->status)->toBe(DeveloperInvite::STATUS_ACTIVE)
        ->and((int) $invite->created_by)->toBe($admin->id)
        ->and($invite->note)->toBe('供应商多账号测试');
});

it('a) 列表（datagrid）能看到明文码、一次性上限与 active 状态', function () {
    $this->loginAsAdmin();

    post(route('admin.customers.developer-invites.store'), [
        'note'            => '列表可见性测试',
        'expires_in_days' => 7,
    ]);

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    $grid = get(route('admin.customers.developer-invites.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk();

    $row = collect($grid->json('records'))
        ->first(fn ($record) => str_contains((string) ($record['code'] ?? ''), $invite->code));

    expect($row)->not->toBeNull()
        // 明文码：不是掩码，就是那一串码本身
        ->and($row['code'])->toContain($invite->code)
        ->and($row['code'])->not->toContain('******')
        ->and($row['status'])->toContain('有效')
        ->and($row['times_used'])->toContain('0 / 1')
        ->and($row['times_used'])->toContain('未使用');
});

it('a) 有效期可选「不过期」：落库为 null', function () {
    $this->loginAsAdmin();

    post(route('admin.customers.developer-invites.store'), [
        'expires_in_days' => 0,
    ])->assertRedirect();

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    expect($invite->expires_at)->toBeNull();
});

// ───────────────────────── b) 审计留痕：不记明文码 ─────────────────────────

it('b) 生成写一条 operation_log，payload 只有掩码、没有明文 code', function () {
    $admin = $this->loginAsAdmin();

    post(route('admin.customers.developer-invites.store'), [
        'expires_in_days' => 7,
    ]);

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    $log = DB::table('operation_log')
        ->where('action', 'developer.invite.create')
        ->where('target_type', 'developer_invite')
        ->where('target_id', $invite->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe('admin')
        ->and((int) $log->actor_id)->toBe($admin->id);

    $payload = json_decode($log->payload, true);

    expect($payload['code_masked'])->toContain('*')
        ->and($payload['code_masked'])->not->toBe($invite->code)
        // 掩码保留首 2 位、末 2 位
        ->and($payload['code_masked'])->toStartWith(substr($invite->code, 0, 2))
        ->and($payload['code_masked'])->toEndWith(substr($invite->code, -2))
        // 整条 payload 序列化后都不许出现明文码
        ->and(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain($invite->code);
});

it('b) 作废写一条 operation_log，payload 同样不含明文 code', function () {
    $this->loginAsAdmin();

    post(route('admin.customers.developer-invites.store'), ['expires_in_days' => 7]);

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    post(route('admin.customers.developer-invites.revoke', $invite->id))->assertRedirect();

    $log = DB::table('operation_log')
        ->where('action', 'developer.invite.revoke')
        ->where('target_type', 'developer_invite')
        ->where('target_id', $invite->id)
        ->first();

    expect($log)->not->toBeNull();

    $payload = json_decode($log->payload, true);

    expect(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain($invite->code)
        ->and($payload['code_masked'])->toContain('*')
        ->and($payload['status'])->toBe(DeveloperInvite::STATUS_REVOKED);
});

// ───────────────────────── c) 作废 + 幂等 ─────────────────────────

it('c) 作废后状态变 revoked；再次作废幂等提示、不 500、不重复留痕', function () {
    $this->loginAsAdmin();

    post(route('admin.customers.developer-invites.store'), ['expires_in_days' => 7]);

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    $logScope = fn () => DB::table('operation_log')
        ->where('action', 'developer.invite.revoke')
        ->where('target_id', $invite->id);

    post(route('admin.customers.developer-invites.revoke', $invite->id))
        ->assertRedirect(route('admin.customers.developer-invites.index'))
        ->assertSessionHas('success');

    expect(DB::table('developer_invite')->where('id', $invite->id)->value('status'))
        ->toBe(DeveloperInvite::STATUS_REVOKED)
        ->and($logScope()->count())->toBe(1);

    // 再点一次：幂等 —— 302 而非 500，状态不变，留痕不重复
    $second = post(route('admin.customers.developer-invites.revoke', $invite->id));

    $second->assertRedirect(route('admin.customers.developer-invites.index'))
        ->assertSessionHas('success');

    expect(session('success'))->toContain('此前已作废')
        ->and(DB::table('developer_invite')->where('id', $invite->id)->value('status'))
        ->toBe(DeveloperInvite::STATUS_REVOKED)
        ->and($logScope()->count())->toBe(1);
});

it('c) 作废后的码在列表里显示「已作废」，已用/上限仍如实显示', function () {
    $this->loginAsAdmin();

    post(route('admin.customers.developer-invites.store'), ['expires_in_days' => 7]);

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    post(route('admin.customers.developer-invites.revoke', $invite->id));

    $grid = get(route('admin.customers.developer-invites.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk();

    $row = collect($grid->json('records'))
        ->first(fn ($record) => str_contains((string) ($record['code'] ?? ''), $invite->code));

    expect($row)->not->toBeNull()
        ->and($row['status'])->toContain('已作废')
        ->and($row['times_used'])->toContain('0 / 1');
});

it('c) 列表里的「作废」按钮（DataGrid POST / ajax）返回 JSON message', function () {
    $this->loginAsAdmin();

    post(route('admin.customers.developer-invites.store'), ['expires_in_days' => 7]);

    $invite = DB::table('developer_invite')->orderByDesc('id')->first();

    $response = post(
        route('admin.customers.developer-invites.revoke', $invite->id),
        [],
        ['X-Requested-With' => 'XMLHttpRequest']
    );

    $response->assertOk()->assertJsonStructure(['message']);

    expect($response->json('message'))->toContain('已作废')
        ->and(DB::table('developer_invite')->where('id', $invite->id)->value('status'))
        ->toBe(DeveloperInvite::STATUS_REVOKED);
});

// ───────────────────────── d) 权限 / 未登录 ─────────────────────────

it('d) 未登录 admin：列表与发码都被重定向到后台登录', function () {
    $before = DB::table('developer_invite')->count();

    get(route('admin.customers.developer-invites.index'))
        ->assertRedirect(route('admin.session.create'));

    post(route('admin.customers.developer-invites.store'), ['expires_in_days' => 7])
        ->assertRedirect(route('admin.session.create'));

    expect(DB::table('developer_invite')->count())->toBe($before);
});

it('d) 无该 ACL 的后台账号：列表被中间件挡（401），发码/作废被控制器挡（403）', function () {
    // custom 角色 + 非空权限（避免被 Bouncer 中间件的"权限为空即登出"先挡掉），
    // 但这组权限里**没有** customers.developer-invites.manage。
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dashboard'],
    ]);

    $admin = Admin::factory()->create(['role_id' => $role->id]);

    actingAs($admin, 'admin');

    $before = DB::table('developer_invite')->count();

    // index 路由登记在 acl.php 里 → Bouncer 中间件按路由名映射权限，直接 401
    get(route('admin.customers.developer-invites.index'))->assertStatus(401);

    // store / revoke 不登记路由映射，由控制器的 authorizeManage() 兜底 → 403
    post(route('admin.customers.developer-invites.store'), ['expires_in_days' => 7])
        ->assertStatus(403);

    post(route('admin.customers.developer-invites.revoke', 1))->assertStatus(403);

    expect(DB::table('developer_invite')->count())->toBe($before);
});

// ───────────────────────── e) 页面口径 ─────────────────────────

it('e) 列表页写明「邀请码不是准入闸门 / 仍需实名与协议」，且不出现免审核话术', function () {
    $this->loginAsAdmin();

    $html = get(route('admin.customers.developer-invites.index'))
        ->assertOk()
        ->assertSeeText('开发者邀请码')
        ->assertSeeText('邀请码不是准入闸门')
        ->assertSeeText('仍需实名与协议')
        ->getContent();

    expect($html)->not->toContain('持码免审核')
        ->and($html)->not->toContain('用码直接入驻')
        ->and($html)->not->toContain('免审核');
});
