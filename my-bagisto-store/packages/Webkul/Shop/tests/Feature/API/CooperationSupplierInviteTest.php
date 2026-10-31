<?php

use Carbon\Carbon;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Services\Developer\DeveloperInviteService;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

function supplierTestCustomer(): Customer
{
    return Customer::create([
        'first_name'  => '供',
        'last_name'   => '应链',
        'email'       => 'supplier_' . uniqid() . '@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function supplierTestAdmin(): Admin
{
    $role = Role::where('permission_type', 'all')->first() ?: Role::create([
        'name'            => 'Administrator',
        'permission_type' => 'all',
    ]);

    return Admin::create([
        'name'     => 'Supplier Admin',
        'email'    => 'admin_supp_' . uniqid() . '@example.com',
        'password' => bcrypt('admin123'),
        'role_id'  => $role->id,
        'status'   => 1,
    ]);
}

it('供应链邀请码核验接口 · POST /api/cooperations/verify-invite 准确识别有效、不存在、过期、停用及用尽状态', function () {
    $inviteService = app(DeveloperInviteService::class);

    // 1. 生成一个有效的邀请码
    $validInvite = $inviteService->generate(null, '官方供应链特邀测试', 2, now()->addDays(7));

    $resValid = postJson(route('shop.api.cooperations.verify_invite'), [
        'code' => strtolower($validInvite->code), // 验证小写自动大写归一化
    ]);

    $resValid->assertOk()
        ->assertJsonPath('data.redeemable', true)
        ->assertJsonPath('data.code', $validInvite->code)
        ->assertJsonPath('data.remaining_uses', 2)
        ->assertJsonPath('data.note', '官方供应链特邀测试');
    expect($resValid->json('data.dev_join_url'))->toContain('/dev/join?code=' . $validInvite->code);

    // 2. 不存在的邀请码
    $resNotFound = postJson(route('shop.api.cooperations.verify_invite'), [
        'code' => 'NOTEXIST00',
    ]);
    $resNotFound->assertOk()
        ->assertJsonPath('data.redeemable', false)
        ->assertJsonPath('data.reason', '邀请码不存在，请向邀请人重新索取邀请链接。');

    // 3. 已撤销停用的邀请码
    $revokedInvite = $inviteService->generate(null, '已撤销测试', 1);
    $revokedInvite->update(['status' => 'revoked']);
    $resRevoked = postJson(route('shop.api.cooperations.verify_invite'), [
        'code' => $revokedInvite->code,
    ]);
    $resRevoked->assertOk()
        ->assertJsonPath('data.redeemable', false)
        ->assertJsonPath('data.reason', '该邀请码已被作废，请联系邀请你的人。');

    // 4. 已过期的邀请码
    $expiredInvite = $inviteService->generate(null, '已过期测试', 1, now()->subDay());
    $resExpired = postJson(route('shop.api.cooperations.verify_invite'), [
        'code' => $expiredInvite->code,
    ]);
    $resExpired->assertOk()
        ->assertJsonPath('data.redeemable', false);
    expect($resExpired->json('data.reason'))->toContain('过期');

    // 5. 次数已用尽的邀请码
    $exhaustedInvite = $inviteService->generate(null, '用尽测试', 1);
    $exhaustedInvite->update(['times_used' => 1]);
    $resExhausted = postJson(route('shop.api.cooperations.verify_invite'), [
        'code' => $exhaustedInvite->code,
    ]);
    $resExhausted->assertOk()
        ->assertJsonPath('data.redeemable', false)
        ->assertJsonPath('data.reason', '该邀请码的使用次数已用完。');
});

it('供应链受邀入驻 · 持码模式强校验，有效码通过，无效码阻断并返回诊断原因', function () {
    $customer = supplierTestCustomer();
    actingAs($customer, 'customer');

    $inviteService = app(DeveloperInviteService::class);
    $validInvite = $inviteService->generate(null, '供应商专属核验通过', 5);

    // 无效邀请码提交 -> 422 拒绝
    $resFail = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_SUPPLIER_INVITE,
        'org_name'       => '深圳极速动力电机制造有限公司_' . uniqid(),
        'contact_name'   => '张总',
        'contact_phone'  => '13812345678',
        'coop_direction' => '配件皮肤与核心部件',
        'meta'           => [
            'entry_mode'          => 'with_code',
            'invite_code'         => 'INVALID999',
            'supply_items'        => '双足高爆发无刷伺服关节电机',
            'capacity_scale'      => '50-200台/月 (规模产能)',
            'warranty_commitment' => '7天换新 + 1年原厂质保',
        ],
    ]);
    $resFail->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('message', '邀请码不存在，请向邀请人重新索取邀请链接。');

    // 有效邀请码提交 -> 201 成功
    $resSuccess = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_SUPPLIER_INVITE,
        'org_name'       => '深圳极速动力电机制造有限公司_' . uniqid(),
        'contact_name'   => '张总',
        'contact_phone'  => '13812345678',
        'coop_direction' => '配件皮肤与核心部件',
        'region'         => '深圳宝安',
        'summary'        => '自研高扭矩伺服舵机，专供宇树G1与主流轮足机器人。',
        'meta'           => [
            'entry_mode'          => 'with_code',
            'invite_code'         => $validInvite->code,
            'supply_items'        => '双足高爆发无刷伺服关节电机',
            'capacity_scale'      => '50-200台/月 (规模产能)',
            'warranty_commitment' => '7天换新 + 1年原厂质保',
            'credit_code'         => '91440300MA5XXXXXX1',
        ],
    ]);

    $resSuccess->assertCreated()
        ->assertJsonPath('data.coop_type', 'supplier_invite')
        ->assertJsonPath('data.type_label', '供应链受邀入驻')
        ->assertJsonPath('data.invite_code', $validInvite->code)
        ->assertJsonPath('data.invite_verified', true)
        ->assertJsonPath('data.invite_note', '供应商专属核验通过');
});

it('供应链受邀入驻 · 无码申请模式与 Admin 审核通过后在线签发邀请码全链路', function () {
    $customer = supplierTestCustomer();
    actingAs($customer, 'customer');

    // 1. 供应商无码申请入驻建档
    $resApply = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_SUPPLIER_INVITE,
        'org_name'       => '苏州机甲智能运维工程技术有限公司_' . uniqid(),
        'contact_name'   => '孙经理',
        'contact_phone'  => '13987654321',
        'coop_direction' => '城市交付与驻场运维',
        'region'         => '华东（苏州/上海/杭州）',
        'summary'        => '覆盖华东20+城市，提供7x24小时驻场工程师机甲检修巡检。',
        'meta'           => [
            'entry_mode'          => 'apply_code',
            'supply_items'        => '驻场工程师支持与机器人日常维保巡检',
            'capacity_scale'      => '200台+/月 或 全国多城覆盖',
            'warranty_commitment' => '2小时现场响应 + 24小时备件换修',
        ],
    ]);

    $resApply->assertCreated()
        ->assertJsonPath('data.invite_verified', false)
        ->assertJsonPath('data.issued_invite_code', null);

    $appId = (int) $resApply->json('data.id');

    // 2. Admin 登录并在运营台审核通过，同时勾选「同步签发专属供应链/生态邀请码」
    $admin = supplierTestAdmin();
    $this->withoutVite();
    actingAs($admin, 'admin');

    get(route('admin.customers.cooperations.show', $appId))->assertOk();

    $reviewRes = post(route('admin.customers.cooperations.status', $appId), [
        'status'              => CooperationApplication::STATUS_APPROVED,
        'admin_note'          => '供应链资质核验通过，已为您定向签发首期 30 天专属供应商邀请码。',
        'issue_invite'        => '1',
        'invite_expires_days' => '30',
    ]);

    $reviewRes->assertRedirect(route('admin.customers.cooperations.show', $appId));

    $appUpdated = CooperationApplication::findOrFail($appId);
    expect($appUpdated->status)->toBe(CooperationApplication::STATUS_APPROVED)
        ->and($appUpdated->meta)->toHaveKey('issued_invite_code')
        ->and($appUpdated->meta['issued_invite_code'])->toBeString();

    $issuedCode = $appUpdated->meta['issued_invite_code'];

    // 3. 验证签发的邀请码真实存在且处于有效可兑换状态
    $inviteRecord = DeveloperInvite::where('code', $issuedCode)->first();
    expect($inviteRecord)->not->toBeNull()
        ->and($inviteRecord->status)->toBe('active')
        ->and($inviteRecord->max_uses)->toBe(1)
        ->and($inviteRecord->times_used)->toBe(0)
        ->and($inviteRecord->note)->toContain($appUpdated->application_no);

    // 4. 验证客户站内信与 C 端列表包含已签发邀请码
    $notif = Notification::query()
        ->where('recipient_type', 'customer')
        ->where('recipient_id', $customer->id)
        ->where('type', 'cooperation.status_updated')
        ->latest('id')
        ->first();

    expect($notif)->not->toBeNull()
        ->and($notif->content)->toContain($issuedCode);

    actingAs($customer, 'customer');
    $cListRes = getJson(route('shop.api.cooperations.index'));
    $cListRes->assertOk();
    $firstRecord = collect($cListRes->json('data'))->firstWhere('id', $appId);

    expect($firstRecord)->not->toBeNull()
        ->and($firstRecord['issued_invite_code'])->toBe($issuedCode)
        ->and($firstRecord['issued_invite_expires_at'])->not->toBeNull();
});

it('合作申请运营台 · 支持快捷创建邀请码、一键签发专属码及填写在跟进人员与联系方式', function () {
    $customer = supplierTestCustomer();
    actingAs($customer, 'customer');

    $resApply = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_SUPPLIER_INVITE,
        'org_name'       => '杭州具身智能精密减速器有限公司_' . uniqid(),
        'contact_name'   => '周工',
        'contact_phone'  => '13711112222',
        'coop_direction' => '配件皮肤与核心部件',
        'meta'           => [
            'entry_mode'          => 'apply_code',
            'supply_items'        => '高精度谐波减速器与行星滚柱丝杠',
            'capacity_scale'      => '50-200台/月 (规模产能)',
            'warranty_commitment' => '7天换新 + 1年原厂质保',
        ],
    ]);
    $resApply->assertCreated();
    $appId = (int) $resApply->json('data.id');

    $admin = supplierTestAdmin();
    $this->withoutVite();
    actingAs($admin, 'admin');

    // 1. 在合作申请列表页直接快捷创建邀请码（支持自定义码与次数）
    $customCode = 'SUPPLY' . strtoupper(substr(uniqid(), -4));
    $createInviteRes = post(route('admin.customers.cooperations.invites.store'), [
        'custom_code'     => $customCode,
        'note'            => '定向邀约-杭州具身智能-周工',
        'expires_in_days' => '30',
        'max_uses'        => '5',
    ]);
    $createInviteRes->assertRedirect(route('admin.customers.cooperations.index'));

    $createdInvite = DeveloperInvite::where('code', $customCode)->first();
    expect($createdInvite)->not->toBeNull()
        ->and($createdInvite->max_uses)->toBe(5)
        ->and($createdInvite->status)->toBe('active');

    // 2. 在详情页填写「在跟进人员」与「跟进人联系方式」并更新为商务跟进中
    $followRes = post(route('admin.customers.cooperations.status', $appId), [
        'status'           => CooperationApplication::STATUS_CONTACTING,
        'followup_person'  => '张经理（供应链商务组）',
        'follower_contact' => '13800009999 / wx_zhang',
        'admin_note'       => '已安排张经理对接减速器样品测试。',
    ]);
    $followRes->assertRedirect(route('admin.customers.cooperations.show', $appId));

    $appAfterFollow = CooperationApplication::findOrFail($appId);
    expect($appAfterFollow->status)->toBe(CooperationApplication::STATUS_CONTACTING)
        ->and($appAfterFollow->followup_person)->toBe('张经理（供应链商务组）')
        ->and($appAfterFollow->meta['follower_name'] ?? null)->toBe('张经理（供应链商务组）')
        ->and($appAfterFollow->meta['follower_contact'] ?? null)->toBe('13800009999 / wx_zhang');

    // 验证列表页按跟进人员筛选生效
    get(route('admin.customers.cooperations.index', ['followup_person' => '张经理']))
        ->assertOk()
        ->assertSee('张经理（供应链商务组）');

    // 3. 单独点击「一键生成并下发专属邀请码」（支持自定义码与可用次数）
    $exclusiveCustom = 'EXCL' . strtoupper(substr(uniqid(), -4));
    $issueRes = post(route('admin.customers.cooperations.issue_invite', $appId), [
        'custom_code'         => $exclusiveCustom,
        'max_uses'            => 2,
        'invite_expires_days' => '30',
    ]);
    $issueRes->assertRedirect(route('admin.customers.cooperations.show', $appId));

    $appAfterIssue = CooperationApplication::findOrFail($appId);
    expect($appAfterIssue->meta['issued_invite_code'] ?? null)->toBe($exclusiveCustom);

    // 4. C 端查询接口同步返回 follower_name、follower_contact 与 issued_invite_code
    actingAs($customer, 'customer');
    $mineRes = getJson(route('shop.api.cooperations.index'));
    $mineRes->assertOk();
    $item = collect($mineRes->json('data'))->firstWhere('id', $appId);

    expect($item)->not->toBeNull()
        ->and($item['follower_name'])->toBe('张经理（供应链商务组）')
        ->and($item['follower_contact'])->toBe('13800009999 / wx_zhang')
        ->and($item['issued_invite_code'])->toBe($appAfterIssue->meta['issued_invite_code']);
});
