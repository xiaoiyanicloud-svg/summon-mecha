<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Services\Developer\DeveloperInviteService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

function mineTestCustomer(array $overrides = []): Customer
{
    return Customer::create(array_merge([
        'first_name'  => '陈',
        'last_name'   => '平台',
        'email'       => 'mine_' . uniqid() . '@summon-mecha.test',
        'password'    => bcrypt('Secret123!'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ], $overrides));
}

function mineTestOrder(Customer $customer, string $status = 'processing', float $total = 1280.00): int
{
    $orderId = DB::table('orders')->insertGetId([
        'increment_id'        => 'SM' . now()->format('ymdHis') . random_int(100, 999),
        'status'              => $status,
        'channel_name'        => 'Default',
        'is_guest'            => 0,
        'customer_id'         => $customer->id,
        'customer_email'      => $customer->email,
        'customer_first_name' => $customer->first_name,
        'customer_last_name'  => $customer->last_name,
        'grand_total'         => $total,
        'base_grand_total'    => $total,
        'sub_total'           => $total,
        'base_sub_total'      => $total,
        'total_item_count'    => 1,
        'total_qty_ordered'   => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('order_items')->insert([
        'order_id'     => $orderId,
        'sku'          => 'RENT-G1-' . $orderId,
        'type'         => 'simple',
        'name'         => 'Unitree G1 商演租赁套餐',
        'qty_ordered'  => 1,
        'price'        => $total,
        'base_price'   => $total,
        'total'        => $total,
        'base_total'   => $total,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return (int) $orderId;
}

it('账户中心 · 个人企业资料修改、改密、找回密码、驾驶舱聚合与 PIPL 注销守卫闭环', function () {
    $customer = mineTestCustomer();
    actingAs($customer, 'customer');

    putJson(route('shop.api.customers.profile.update'), [
        'first_name'   => '林',
        'last_name'    => '远征',
        'phone'        => '13812345678',
        'company_name' => '深圳远征具身科技有限公司',
        'job_title'    => '采购总监',
    ])->assertOk()
        ->assertJsonPath('data.company_name', '深圳远征具身科技有限公司')
        ->assertJsonPath('data.job_title', '采购总监')
        ->assertJsonPath('data.phone', '13812345678');

    getJson(route('shop.api.customers.overview'))
        ->assertOk()
        ->assertJsonPath('data.customer.company_name', '深圳远征具身科技有限公司')
        ->assertJsonStructure(['data' => ['customer', 'identities', 'counts' => ['order_counts']]]);

    putJson(route('shop.api.customers.password.update'), [
        'current_password'          => 'Secret123!',
        'new_password'              => 'NewSecret456!',
        'new_password_confirmation' => 'NewSecret456!',
    ])->assertOk();

    // 忘记密码 + 验证码重置
    postJson(route('shop.api.customers.forgot-password'), [
        'email' => $customer->email,
    ])->assertOk();

    $cached = Cache::get('customer_pwd_reset:' . strtolower($customer->email));
    expect($cached)->toBeArray()->and($cached['code'])->toHaveLength(6);

    postJson(route('shop.api.customers.reset-password'), [
        'email'                 => $customer->email,
        'code'                  => $cached['code'],
        'password'              => 'ResetPass789!',
        'password_confirmation' => 'ResetPass789!',
    ])->assertOk();

    // 存在进行中订单时阻断 PIPL 注销
    $activeOrderId = mineTestOrder($customer, 'processing');
    actingAs($customer->fresh(), 'customer');

    postJson(route('shop.api.customers.deletion-request'), [
        'password' => 'ResetPass789!',
        'reason'   => '测试在途单拦截',
    ])->assertStatus(409);

    DB::table('orders')->where('id', $activeOrderId)->update(['status' => 'completed']);

    postJson(route('shop.api.customers.deletion-request'), [
        'password' => 'ResetPass789!',
        'reason'   => '主体迁移完毕申请注销',
    ])->assertStatus(201)
        ->assertJsonPath('data.status', 'pending_review');
});

it('发票与抬头簿 · 支持增值税专票四要素校验、抬头簿增删改与开票自动落库', function () {
    $customer = mineTestCustomer();
    actingAs($customer, 'customer');

    $orderId = mineTestOrder($customer, 'completed', 5600.00);

    // 缺少专票四要素应返回 422
    postJson(route('shop.api.invoices.store'), [
        'order_id'     => $orderId,
        'invoice_kind' => 'special',
        'type'         => 'company',
        'title'        => '杭州具身智能研究院有限公司',
        'tax_no'       => '91330108MA27XXXXXX',
    ])->assertStatus(422);

    // 完整专票四要素 + save_header 自动沉淀抬头簿
    postJson(route('shop.api.invoices.store'), [
        'order_id'        => $orderId,
        'invoice_kind'    => 'special',
        'type'            => 'company',
        'title'           => '杭州具身智能研究院有限公司',
        'tax_no'          => '91330108MA27ABCDEF',
        'bank_name'       => '招商银行杭州高新支行',
        'bank_account'    => '571900123456789',
        'company_address' => '杭州市滨江区江南大道 88 号',
        'company_phone'   => '0571-88889999',
        'receive_email'   => 'finance@summon-mecha.test',
        'save_header'     => true,
    ])->assertStatus(201)
        ->assertJsonPath('data.invoice_kind', 'special')
        ->assertJsonPath('data.bank_name', '招商银行杭州高新支行')
        ->assertJsonPath('data.receive_email', 'finance@summon-mecha.test');

    $headersRes = getJson(route('shop.api.invoices.headers.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $headerId = (int) $headersRes->json('data.0.id');

    putJson(route('shop.api.invoices.headers.update', $headerId), [
        'type'         => 'company',
        'invoice_kind' => 'ordinary',
        'title'        => '杭州具身智能研究院（普票抬头）',
        'tax_no'       => '91330108MA27ABCDEF',
        'is_default'   => true,
    ])->assertOk()
        ->assertJsonPath('data.title', '杭州具身智能研究院（普票抬头）');

    deleteJson(route('shop.api.invoices.headers.destroy', $headerId))
        ->assertOk();
});

it('统一售后与维保 · 支持租售配件订单报修、待处理撤回及技能驳回后 D10 二次复核申诉', function () {
    $customer = mineTestCustomer();
    actingAs($customer, 'customer');

    $orderId = mineTestOrder($customer, 'processing', 3200.00);

    // 1. 发起订单维保售后并撤回
    $orderAs = postJson(route('shop.api.after-sale.store'), [
        'order_id'        => $orderId,
        'type'            => 'repair',
        'reason_category' => 'hardware_fault',
        'detail'          => '右膝关节电机过热报警需检修',
    ])->assertStatus(201)
        ->assertJsonPath('data.target_domain', 'order')
        ->assertJsonPath('data.can_cancel', true);

    $orderAsId = (int) $orderAs->json('data.id');

    postJson(route('shop.api.after-sale.cancel', $orderAsId))
        ->assertOk()
        ->assertJsonPath('data.status', AfterSale::STATUS_CANCELLED);

    // 2. 技能售后驳回后发起 D10 二次申诉
    $rejectedSkillAs = AfterSale::create([
        'customer_id'     => $customer->id,
        'target_domain'   => AfterSale::DOMAIN_SKILL,
        'entitlement_id'  => 99901,
        'type'            => AfterSale::TYPE_REFUND,
        'reason_category' => 'execution_error',
        'detail'          => '下发后动作报错',
        'status'          => AfterSale::STATUS_REJECTED,
        'review_note'     => '缺少报错截图',
    ]);

    postJson(route('shop.api.after-sale.appeal', $rejectedSkillAs->id), [
        'appeal_detail' => '已补充端侧 broker 日志时间戳与报错码 E_JOINT_LIMIT',
    ])->assertOk()
        ->assertJsonPath('data.status', AfterSale::STATUS_REVIEWING)
        ->assertJsonPath('data.can_appeal', false);
});

it('通知、机型目录、订单时间轴、事故脱敏回显与合规备案接口正常返回', function () {
    $customer = mineTestCustomer();
    actingAs($customer, 'customer');

    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'cooperation.submitted',
        'title'          => '合作申请已受理',
        'content'        => '商务将在 1-3 个工作日联系您',
    ]);

    getJson(route('shop.api.notifications.index', ['category' => 'cooperation']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.target_url', '/pages/mine/cooperate?tab=history');

    postJson(route('shop.api.notifications.read-all'))
        ->assertOk()
        ->assertJsonPath('data.updated', 1);

    // 设备型号目录与 model_id 登记持久化
    $model = DeviceModel::query()->where('status', DeviceModel::STATUS_ACTIVE)->first() ?: DeviceModel::create([
        'brand'         => 'Unitree',
        'model_code'    => 'unitree_g1_test_' . uniqid(),
        'model_name'    => 'G1 EDU',
        'comm_channels' => ['agent'],
        'status'        => DeviceModel::STATUS_ACTIVE,
    ]);

    getJson(route('shop.api.device-models.index'))
        ->assertOk();

    $devRes = postJson(route('shop.api.devices.store'), [
        'sn'            => 'SN-TEST-' . strtoupper(uniqid()),
        'display_name'  => '展厅测试 1 号',
        'model_id'      => $model->id,
        'national_code' => 'CN-ROBOT-001',
    ])->assertStatus(201)
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.national_code', 'CN-ROBOT-001');

    $deviceId = (int) $devRes->json('data.id');

    // 订单详情自动生成真实时间轴兜底
    $orderId = mineTestOrder($customer, 'processing', 999.00);
    $ordDetail = getJson(route('shop.api.customers.orders.show', $orderId))
        ->assertOk();
    expect($ordDetail->json('data.timeline'))->toBeArray()->not->toBeEmpty();

    // 事故上报与详情回显本人设备序列号，且严禁暴露 trace / handling_log
    $incCreate = postJson(route('shop.api.incidents.store'), [
        'device_id'   => $deviceId,
        'severity'    => 'moderate',
        'description' => '测试关节碰撞停机',
    ])->assertStatus(201);

    $incidentId = (int) $incCreate->json('data.id');
    Incident::query()->whereKey($incidentId)->update([
        'trace'        => json_encode(['internal_secret' => 'do_not_leak']),
        'handling_log' => json_encode([['staff' => 'internal_note']]),
    ]);

    $incShow = getJson(route('shop.api.incidents.show', $incidentId))
        ->assertOk()
        ->assertJsonPath('data.device_sn', $devRes->json('data.sn'));

    expect($incShow->json('data'))->not->toHaveKey('trace')
        ->and($incShow->json('data'))->not->toHaveKey('handling_log');

    // 下发历史与合规备案接口
    getJson(route('shop.api.deploy.tasks.index'))->assertOk();
    getJson(route('shop.api.cms.compliance-filings'))
        ->assertOk()
        ->assertJsonStructure(['data' => ['app_name', 'has_filings', 'honest_statement']]);
});

it('ToB 合作资质私密盘上传、供应链邀请码门户分流、申请撤回及单次 SSO 免登票据全链路', function () {
    Storage::fake('private');

    $customer = mineTestCustomer();
    actingAs($customer, 'customer');

    // 1. 真实上传营业执照图片至 private 盘
    $file = UploadedFile::fake()->image('business-license.jpg', 800, 600);
    $upRes = postJson(route('shop.api.cooperations.attachments.store'), [
        'file' => $file,
    ])->assertStatus(201);

    $attToken = $upRes->json('data.attachment_token');
    expect($attToken)->toBeString()->not->toBeEmpty();

    // 2. 供应链邀请码核验：context=supplier 指向 /merchant/onboarding 且保留 dev_join_url 兼容字段
    $invite = app(DeveloperInviteService::class)->generate(null, '供应链定向邀约', 3, now()->addDays(7));

    $verifyRes = postJson(route('shop.api.cooperations.verify_invite'), [
        'code'    => $invite->code,
        'context' => 'supplier',
    ])->assertOk()
        ->assertJsonPath('data.redeemable', true)
        ->assertJsonPath('data.target_portal', 'merchant');

    expect($verifyRes->json('data.onboarding_url'))->toContain('/merchant/onboarding?code=' . $invite->code)
        ->and($verifyRes->json('data.dev_join_url'))->toContain('/dev/join?code=' . $invite->code);

    // 3. 提交持码供应链申请（绑定私密盘资质）-> 查询详情（不泄露物理路径）-> 自助撤回
    $submitRes = postJson(route('shop.api.cooperations.store'), [
        'coop_type'      => CooperationApplication::TYPE_SUPPLIER_INVITE,
        'org_name'       => '苏州精密灵巧手制造有限公司',
        'contact_name'   => '周工',
        'contact_phone'  => '13987654321',
        'coop_direction' => '核心零部件与电池配件',
        'meta'           => [
            'entry_mode'               => 'with_code',
            'invite_code'              => $invite->code,
            'supply_items'             => '六维力传感器、灵巧手模组',
            'capacity_scale'           => '月供 20~100 台',
            'warranty_commitment'      => '原厂全国联保 + 24h响应',
            'license_attachment_token' => $attToken,
        ],
    ])->assertStatus(201)
        ->assertJsonPath('data.has_license_attachment', true)
        ->assertJsonPath('data.target_portal', 'merchant');

    $appId = (int) $submitRes->json('data.id');

    $detailRes = getJson(route('shop.api.cooperations.show', $appId))
        ->assertOk()
        ->assertJsonPath('data.has_license_attachment', true);
    expect($detailRes->json('data.meta'))->not->toHaveKey('license_disk_path');

    // 此时该客户已具备已核验供应链准入资格，可签发商家工作台单次 SSO 免登票据
    $ticketRes = postJson(route('shop.api.portal.handoff-ticket'), [
        'key' => 'merchant_admin',
    ])->assertOk();

    $rawSsoTicket = $ticketRes->json('data.ticket');
    expect($rawSsoTicket)->toBeString()->toHaveLength(64);

    // 首次核销成功建立 merchant guard 会话；二次重放立即失效阻断
    get(route('shop.merchant.sso', ['ticket' => $rawSsoTicket]))
        ->assertRedirect();
    expect(auth('merchant')->id())->toBe($customer->id);

    auth('merchant')->logout();
    get(route('shop.merchant.sso', ['ticket' => $rawSsoTicket]))
        ->assertRedirect(route('shop.merchant.login'));
    expect(auth('merchant')->check())->toBeFalse();

    // 开发者门户 SSO 免登票据同样单次核销生效
    actingAs($customer, 'customer');
    $devTicket = postJson(route('shop.api.portal.handoff-ticket'), [
        'key' => 'developer_skill',
    ])->assertOk()->json('data.ticket');

    get(route('shop.dev.sso', ['ticket' => $devTicket]))
        ->assertRedirect();
    expect(auth('developer')->id())->toBe($customer->id);

    // 业务工作台移动端概览只读隔离（未绑定 seller_id 时绝不查询全站平台自营商品）
    actingAs($customer, 'customer');
    getJson(route('shop.api.portal.summary'))
        ->assertOk()
        ->assertJsonPath('data.developer.skill_count', null);

    // 撤回待审核合作申请后状态变为 withdrawn，允许重新提交
    postJson(route('shop.api.cooperations.withdraw', $appId))
        ->assertOk()
        ->assertJsonPath('data.status', CooperationApplication::STATUS_WITHDRAWN);
});

it('客服工单 · 支持携带本人交易订单卡与设备卡发起会话，并拦截越权关联他人订单', function () {
    $owner = mineTestCustomer();
    $other = mineTestCustomer();

    $ownerOrderId = mineTestOrder($owner, 'processing', 2199.00);
    $otherOrderId = mineTestOrder($other, 'processing', 2199.00);

    actingAs($owner, 'customer');

    // 越权携带他人 order_id 被严格拦截 (422)
    postJson(route('shop.api.tickets.store'), [
        'content'     => '尝试越权关联他人订单',
        'category'    => SupportTicket::CATEGORY_PAYMENT,
        'attachments' => [[
            'type'     => 'order',
            'order_id' => $otherOrderId,
            'name'     => '他人订单',
        ]],
    ])->assertStatus(422);

    // 携带本人订单卡成功落库并回显
    $okRes = postJson(route('shop.api.tickets.store'), [
        'content'     => '请问这笔租赁订单预计几点送达展馆？',
        'category'    => SupportTicket::CATEGORY_PAYMENT,
        'attachments' => [[
            'type'         => 'order',
            'order_id'     => $ownerOrderId,
            'increment_id' => 'SM-TEST-ORDER',
            'name'         => 'Unitree G1 商演租赁套餐',
            'price'        => '2199',
            'status_label' => '待确认',
        ]],
    ])->assertCreated();

    $ticketId = (int) $okRes->json('data.id');

    getJson(route('shop.api.tickets.show', $ticketId))
        ->assertOk()
        ->assertJsonPath('data.messages.0.attachments.0.type', 'order')
        ->assertJsonPath('data.messages.0.attachments.0.order_id', $ownerOrderId);
});
