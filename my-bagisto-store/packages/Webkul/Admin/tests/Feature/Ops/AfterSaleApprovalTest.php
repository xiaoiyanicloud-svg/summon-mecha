<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * B-1 · C 端退款「申请→审批→打款」的平台端审批入口。
 *
 * 缺口的原貌：申请端已通（`POST /api/after-sale`），但 `AfterSaleService::approve()`
 * **全仓零调用方**，Admin 无 after-sale 路由/视图 —— `after_sale` 里已有真实申请，
 * 却没人能批，用户点完退款就永远停在"已受理"。
 *
 * 本文件验证新审批台：能列、能看、能批准（走既有 Service）、能驳回；
 * 且**不新造规则**（金额/政策/防重全在 `AfterSaleService` 内）。
 */

function b1Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'B1',
        'last_name'   => 'Refund',
        'email'       => 'b1'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 一整套：客户 → 订单 → 条目 → 授权 → 售后单。返回 [afterSaleId, orderItemId]。 */
function b1Scenario(
    float $total = 999.00,
    string $afterSaleStatus = AfterSale::STATUS_APPLIED,
    string $policyBasis = 'not_installed',
    string $entitlementStatus = SkillEntitlement::STATUS_GRANTED,
): array {
    $customer = b1Customer();

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'B1'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing',
        'grand_total'  => $total,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $itemId = DB::table('order_items')->insertGetId([
        'order_id'    => $orderId,
        'product_id'  => 910000 + random_int(1, 9999),
        'type'        => 'simple',
        'name'        => '技能包',
        'sku'         => 'B1SKU'.uniqid(),
        'qty_ordered' => 1,
        'price'       => $total,
        'total'       => $total,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => $itemId,
        'product_id'       => 910000 + random_int(1, 9999),
        'status'           => $entitlementStatus,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);

    $afterSale = AfterSale::create([
        'order_id'       => $orderId,
        'order_item_id'  => $itemId,
        'entitlement_id' => $entitlement->id,
        'customer_id'    => $customer->id,
        'type'           => AfterSale::TYPE_REFUND,
        'policy_basis'   => $policyBasis,
        'status'         => $afterSaleStatus,
    ]);

    return [$afterSale->id, $itemId];
}

it('B-1 · 审批台列表页可渲染（此前 Admin 无任何 after-sale 入口）', function () {
    $this->loginAsAdmin();

    get(route('admin.after-sales.index'))
        ->assertOk()
        ->assertSeeText('售后审批');
});

it('B-1 · 详情页展示政策依据与审批表单', function () {
    [$afterSaleId] = b1Scenario();

    $this->loginAsAdmin();

    get(route('admin.after-sales.show', $afterSaleId))
        ->assertOk()
        ->assertSeeText('售后单 #'.$afterSaleId)
        ->assertSeeText('未下发·可退')
        ->assertSeeText('人工审批');
});

it('B-1 · 批准：走既有 approve()，落退款单 + 资金留痕，售后单进终态', function () {
    [$afterSaleId] = b1Scenario(888.00);

    $this->loginAsAdmin();

    post(route('admin.after-sales.approve', $afterSaleId))->assertRedirect();

    // 退款单落库，金额=该条目实付（走 Service 的 M5 逻辑）
    $refund = RefundRequest::where('after_sale_id', $afterSaleId)->first();
    expect($refund)->not->toBeNull()
        ->and((float) $refund->amount)->toBe(888.0);

    // 售后单不再停"已受理"
    expect(AfterSale::find($afterSaleId)->status)
        ->toBeIn([AfterSale::STATUS_APPROVED, AfterSale::STATUS_REFUNDED]);

    // M8 资金留痕
    expect(DB::table('operation_log')
        ->where('action', 'after_sale.approve')
        ->where('target_id', $afterSaleId)
        ->count())->toBe(1);

    // 授权被冻结（approve 里 revokeEntitlement → AppChannel::revoke）
    $entitlementId = AfterSale::find($afterSaleId)->entitlement_id;
    expect(SkillEntitlement::find($entitlementId)->status)->toBe(SkillEntitlement::STATUS_REVOKED);
});

it('B-1 · 驳回：置 rejected 并记录理由', function () {
    [$afterSaleId] = b1Scenario(500.00, AfterSale::STATUS_REVIEWING, 'activated');

    $this->loginAsAdmin();

    post(route('admin.after-sales.reject', $afterSaleId), ['reason' => '已激活且无质量问题'])
        ->assertRedirect();

    $afterSale = AfterSale::find($afterSaleId);

    expect($afterSale->status)->toBe(AfterSale::STATUS_REJECTED)
        ->and($afterSale->reviewed_at)->not->toBeNull()
        ->and($afterSale->detail)->toContain('已激活且无质量问题');

    // 驳回不产生退款单
    expect(RefundRequest::where('after_sale_id', $afterSaleId)->count())->toBe(0);
});

it('B-1 · 已处理的售后单再批准被拒，不重复退款（服务端守卫）', function () {
    [$afterSaleId] = b1Scenario(300.00);

    $this->loginAsAdmin();

    post(route('admin.after-sales.approve', $afterSaleId))->assertRedirect();
    post(route('admin.after-sales.approve', $afterSaleId))->assertRedirect();

    // 钱只出去一次
    expect(RefundRequest::where('after_sale_id', $afterSaleId)->count())->toBe(1);
});
