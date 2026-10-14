<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Seller;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 开发者入驻审核台（v3.4 步骤 9 的平台端侧）。
 *
 * 在此之前 `realname_status` **永远停在 pending**：常量定义了但零使用，
 * 平台端一个入口也没有 —— "开发者是谁"这件事从未被确认过。
 *
 * ⚠ 界面与文案的口径必须诚实：这里的"通过"是**对已提交信息的人工确认**，
 *   不是身份核验（当前表单只有联系人姓名/电话，没有证件）。
 *   测试里有一条专门断言这个口径不被文案改掉。
 */

function drCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'User',
        'email'       => 'dr'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function drProfile(array $overrides = []): DeveloperProfile
{
    $customer = drCustomer();

    $seller = Seller::create([
        'code'              => 'dr_'.uniqid(),
        'name'              => '测试开发者',
        'status'            => 'active',
        'owner_customer_id' => $customer->id,
    ]);

    return DeveloperProfile::create(array_merge([
        'customer_id'     => $customer->id,
        'seller_id'       => $seller->id,
        'dev_type'        => DeveloperProfile::DEV_TYPE_PERSONAL,
        'realname_status' => DeveloperProfile::REALNAME_PENDING,
        'cert_payload'    => ['contact_name' => '张三', 'contact_phone' => '13800000000'],
        'status'          => DeveloperProfile::STATUS_ACTIVE,
    ], $overrides));
}

it('开发者列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.customers.developers.index'))
        ->assertOk()
        ->assertSeeText('开发者管理');
});

it('开发者详情页可以渲染，并原样列出已提交的资质留档', function () {
    $profile = drProfile();

    $this->loginAsAdmin();

    $response = get(route('admin.customers.developers.show', $profile->id));

    $response->assertOk()
        ->assertSeeText('主体信息')
        ->assertSeeText('提交的资质留档')
        // 留档内容原样可见 —— 审核人自己判断证据强度
        ->assertSeeText('contact_name')
        ->assertSeeText('13800000000');
});

it('口径断言：界面必须写明「人工确认」而非「身份核验」', function () {
    $this->loginAsAdmin();

    $html = get(route('admin.customers.developers.index'))->getContent();

    expect($html)->toContain('人工确认')
        // 不能出现把它说成已完成实名核验的表述
        ->and($html)->not->toContain('实名认证通过');
});

it('实名审核通过：状态推进为 approved，并留痕', function () {
    $profile = drProfile();

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.developers.realname.approve', $profile->id), [
        'note' => '电话核实过',
    ])->assertRedirect();

    expect($profile->fresh()->realname_status)->toBe(DeveloperProfile::REALNAME_APPROVED);

    expect(DB::table('operation_log')
        ->where('action', 'developer.realname.approve')
        ->where('target_id', $profile->id)
        ->where('actor_id', $admin->id)
        ->exists())->toBeTrue();
});

it('实名审核驳回：必须给原因，且状态推进为 rejected', function () {
    $profile = drProfile();

    $this->loginAsAdmin();

    // 不给原因 → 校验失败，状态不变
    post(route('admin.customers.developers.realname.reject', $profile->id))
        ->assertSessionHasErrors('reason');

    expect($profile->fresh()->realname_status)->toBe(DeveloperProfile::REALNAME_PENDING);

    post(route('admin.customers.developers.realname.reject', $profile->id), [
        'reason' => '联系方式无效',
    ])->assertRedirect();

    expect($profile->fresh()->realname_status)->toBe(DeveloperProfile::REALNAME_REJECTED);
});

it('账号处置：停用 / 恢复是幂等的两次切换，且不删数据', function () {
    $profile = drProfile();

    $this->loginAsAdmin();

    post(route('admin.customers.developers.status', $profile->id))->assertRedirect();
    expect($profile->fresh()->status)->toBe(DeveloperProfile::STATUS_SUSPENDED);

    // 停用不删数据
    expect(DeveloperProfile::find($profile->id))->not->toBeNull();

    post(route('admin.customers.developers.status', $profile->id))->assertRedirect();
    expect($profile->fresh()->status)->toBe(DeveloperProfile::STATUS_ACTIVE);
});

it('账号处置会留痕', function () {
    $profile = drProfile();

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.developers.status', $profile->id));

    expect(DB::table('operation_log')
        ->where('action', 'developer.status.toggle')
        ->where('target_id', $profile->id)
        ->where('actor_id', $admin->id)
        ->exists())->toBeTrue();
});
