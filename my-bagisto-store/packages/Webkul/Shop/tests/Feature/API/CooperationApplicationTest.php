<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\Notification;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

function coopTestCustomer(): Customer
{
    return Customer::create([
        'first_name'  => '李',
        'last_name'   => '机甲',
        'email'       => 'coop_' . uniqid() . '@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function coopTestAdmin(): Admin
{
    $role = Role::where('permission_type', 'all')->first() ?: Role::create([
        'name'            => 'Administrator',
        'permission_type' => 'all',
    ]);

    return Admin::create([
        'name'     => 'Coop Admin',
        'email'    => 'admin_coop_' . uniqid() . '@example.com',
        'password' => bcrypt('admin123'),
        'role_id'  => $role->id,
        'status'   => 1,
    ]);
}

it('合作申请 · 三个核心入口（本体厂商 / RaaS合伙人 / 网红达人）与合伙人推荐均可成功提交并查询', function () {
    $customer = coopTestCustomer();
    actingAs($customer, 'customer');

    // 1. 本体厂商 (manufacturer)
    $resManufacturer = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_MANUFACTURER,
        'org_name'       => '星际重工机器人有限公司_' . uniqid(),
        'contact_name'   => '王总监',
        'contact_phone'  => '13800138001',
        'contact_wechat' => 'star_robotics',
        'region'         => '深圳',
        'coop_direction' => '租赁与销售渠道共建',
        'summary'        => '自有双足人形与四足产线，期望接入召唤机甲全国租赁网络。',
        'meta'           => [
            'robot_models' => 'StarBot-X1 双足人形 / StarDog-M2 四足',
        ],
    ]);

    $resManufacturer->assertCreated()
        ->assertJsonPath('data.coop_type', 'manufacturer')
        ->assertJsonPath('data.type_label', '本体厂商')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.meta.robot_models', 'StarBot-X1 双足人形 / StarDog-M2 四足');

    // 2. RaaS合伙人 (raas)
    $resRaas = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_RAAS,
        'org_name'       => '灵巧手算法工作室_' . uniqid(),
        'contact_name'   => '陈工',
        'contact_phone'  => '13800138002',
        'coop_direction' => '动作捕捉与重定向',
        'summary'        => '专注宇树 G1 舞蹈与迎宾动作包开发。',
        'meta'           => [
            'entity_type'   => 'company',
            'robot_models'  => '宇树 G1 / 宇树 Go2',
            'portfolio_url' => 'https://github.com/example/mecha-skills',
        ],
    ]);

    $resRaas->assertCreated()
        ->assertJsonPath('data.coop_type', 'raas')
        ->assertJsonPath('data.type_label', 'RaaS合伙人')
        ->assertJsonPath('data.meta.entity_type', 'company');

    // 3. 网红达人 (influencer)
    $resInfluencer = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_INFLUENCER,
        'org_name'       => '机甲少女阿狸_' . uniqid(),
        'contact_name'   => '阿狸',
        'contact_phone'  => '13800138003',
        'region'         => '杭州',
        'coop_direction' => '免费用机评测',
        'summary'        => '科技数码测评博主，近期策划人形机器人街头互动专题。',
        'meta'           => [
            'platform'       => '抖音',
            'followers_tier' => '50万-100万',
            'account_url'    => 'https://v.douyin.com/example',
        ],
    ]);

    $resInfluencer->assertCreated()
        ->assertJsonPath('data.coop_type', 'influencer')
        ->assertJsonPath('data.type_label', '网红达人')
        ->assertJsonPath('data.meta.platform', '抖音');

    // 4. 合伙人推荐 (partner_referral)
    $resReferral = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_PARTNER_REFERRAL,
        'org_name'       => '华东会展策划集团_' . uniqid(),
        'contact_name'   => '赵经理',
        'contact_phone'  => '13800138004',
        'region'         => '上海',
        'coop_direction' => '金牌合伙人推荐',
        'summary'        => '每年承办 30+ 场科技展会，具备强渠道资源。',
        'meta'           => [
            'level'             => '金牌',
            'recommender_type'  => 'sales',
            'license_file_name' => 'license.png',
        ],
    ]);

    $resReferral->assertCreated()
        ->assertJsonPath('data.coop_type', 'partner_referral')
        ->assertJsonPath('data.type_label', '合伙人推荐');

    // 查询当前客户申请记录列表，4 条均能查到
    $listRes = getJson(route('shop.api.cooperations.index'));
    $listRes->assertOk()
        ->assertJsonCount(4, 'data');
});

it('合作申请 · 防重与参数校验：缺少必填项返回 422，同一主体重复提交待审核申请返回 409', function () {
    $guestKey = 'gk_test_' . uniqid();
    $orgName  = '重复测试本体厂_' . uniqid();

    // 缺少本体型号返回 422
    postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_MANUFACTURER,
        'org_name'       => $orgName,
        'contact_name'   => '林总',
        'contact_phone'  => '13912345678',
        'coop_direction' => '品牌联合推广',
        'guest_key'      => $guestKey,
        'meta'           => [],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    // 首次合法提交成功
    postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_MANUFACTURER,
        'org_name'       => $orgName,
        'contact_name'   => '林总',
        'contact_phone'  => '13912345678',
        'coop_direction' => '品牌联合推广',
        'guest_key'      => $guestKey,
        'meta'           => ['robot_models' => 'Titan-01'],
    ])->assertCreated();

    // 同一主体+手机号+类型在待审核期间再次提交 → 409 CONFLICT
    postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_MANUFACTURER,
        'org_name'       => $orgName,
        'contact_name'   => '林总',
        'contact_phone'  => '13912345678',
        'coop_direction' => '品牌联合推广',
        'guest_key'      => $guestKey,
        'meta'           => ['robot_models' => 'Titan-01'],
    ])->assertStatus(409)
        ->assertJsonPath('code', 'CONFLICT');

    // 游客凭 guest_key 可查到自己的申请，其他游客查不到
    getJson(route('shop.api.cooperations.index', ['guest_key' => $guestKey]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    getJson(route('shop.api.cooperations.index', ['guest_key' => 'gk_other_' . uniqid()]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('合作申请 · Admin 运营台审核流转与客户站内通知闭环', function () {
    $customer = coopTestCustomer();
    actingAs($customer, 'customer');

    $createRes = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_RAAS,
        'org_name'       => '极客具身智能实验室_' . uniqid(),
        'contact_name'   => '周博',
        'contact_phone'  => '13711112222',
        'coop_direction' => 'ROS2与运控算法',
        'meta'           => [
            'entity_type'  => 'company',
            'robot_models' => '宇树 G1',
        ],
    ])->assertCreated();

    $appId = (int) $createRes->json('data.id');

    $admin = coopTestAdmin();
    $this->withoutVite();
    actingAs($admin, 'admin');

    // Admin 列表页与详情页正常渲染
    get(route('admin.customers.cooperations.index'))->assertOk();
    get(route('admin.customers.cooperations.show', $appId))->assertOk();

    // Admin 更新状态为 approved 并附带商务备注
    post(route('admin.customers.cooperations.status', $appId), [
        'status'     => CooperationApplication::STATUS_APPROVED,
        'admin_note' => '资质审核通过，商务顾问已添加您的微信，欢迎前往 /dev 上传技能。',
    ])->assertRedirect(route('admin.customers.cooperations.show', $appId));

    $updated = CooperationApplication::findOrFail($appId);
    expect($updated->status)->toBe(CooperationApplication::STATUS_APPROVED)
        ->and($updated->admin_note)->toContain('资质审核通过')
        ->and($updated->reviewed_at)->not->toBeNull();

    // 客户收到状态变更站内通知
    $notif = Notification::query()
        ->where('recipient_type', 'customer')
        ->where('recipient_id', $customer->id)
        ->where('type', 'cooperation.status_updated')
        ->latest('id')
        ->first();

    expect($notif)->not->toBeNull()
        ->and($notif->title)->toContain('已通过');
});
