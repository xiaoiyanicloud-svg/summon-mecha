<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

/**
 * A3 · 售后审批 check-then-act → 重复退款。
 *
 * 资金级:两个并发 approve 只能退一次款。守卫已在 M7 移进事务内 + 行锁,
 * 本文件把这条保证**钉死**,并补上"A3 新增的库级兜底"(refund_request.after_sale_id 唯一键)。
 */

function a3Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Afs',
        'last_name'   => 'Race',
        'email'       => 'a3'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 一整套:订单 → 条目 → 授权 → 售后单。返回 [entitlementId, afterSaleId, orderItemId]。
 */
function a3Scenario(float $total = 999.00, string $entStatus = SkillEntitlement::STATUS_ACTIVE): array
{
    $customer = a3Customer();

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'A3'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing',
        'grand_total'  => $total,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $itemId = DB::table('order_items')->insertGetId([
        'order_id'     => $orderId,
        'product_id'   => 900000 + random_int(1, 9999),
        'type'         => 'simple',
        'name'         => '技能包',
        'sku'          => 'A3SKU'.uniqid(),
        'qty_ordered'  => 1,
        'price'        => $total,
        'total'        => $total,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => $itemId,
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => $entStatus,
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
        'policy_basis'   => 'not_installed',
        'status'         => AfterSale::STATUS_APPLIED,
    ]);

    return [$entitlement->id, $afterSale->id, $itemId];
}

it('A3 · 同一售后单 approve 两次 → 只退一次款,只落一条退款单、一条留痕', function () {
    [, $afterSaleId, $itemId] = a3Scenario(888.00);

    $service = app(AfterSaleService::class);

    $first = $service->approve($afterSaleId, 1);
    expect($first['ok'])->toBeTrue()
        ->and($first['data']['amount'])->toBe(888.0);

    // 第二次(等价于并发里"后到的那一个"拿到锁之后)必须被状态守卫挡下
    $second = $service->approve($afterSaleId, 1);
    expect($second['ok'])->toBeFalse()
        ->and($second['message'])->toContain('已处理');

    // 钱只出去一次:退款单唯一、金额只计一次、审批留痕唯一
    expect(RefundRequest::where('after_sale_id', $afterSaleId)->count())->toBe(1)
        ->and((float) RefundRequest::where('after_sale_id', $afterSaleId)->sum('amount'))->toBe(888.0);

    expect(DB::table('operation_log')
        ->where('action', 'after_sale.approve')
        ->where('target_id', $afterSaleId)
        ->count())->toBe(1);

    // 该订单条目累计退款不得超实付
    $total = (float) RefundRequest::query()
        ->join('after_sale', 'after_sale.id', '=', 'refund_request.after_sale_id')
        ->where('after_sale.order_item_id', $itemId)
        ->whereIn('refund_request.status', RefundRequest::COUNTED_STATUSES)
        ->sum('refund_request.amount');

    expect($total)->toBeLessThanOrEqual(888.0);
});

it('A3 · refund_request.after_sale_id 唯一键:同一售后单不能落第二条退款记录', function () {
    [, $afterSaleId] = a3Scenario(100.00);

    RefundRequest::create([
        'after_sale_id'    => $afterSaleId,
        'wechat_refund_no' => 'RF'.uniqid(),
        'amount'           => 100.0,
        'status'           => RefundRequest::STATUS_PENDING,
    ]);

    expect(fn () => RefundRequest::create([
        'after_sale_id'    => $afterSaleId,
        'wechat_refund_no' => 'RF'.uniqid(),
        'amount'           => 100.0,
        'status'           => RefundRequest::STATUS_PENDING,
    ]))->toThrow(QueryException::class);
});

it('A3 · 重复申请被拒(同一授权的处理中售后单只能有一张)', function () {
    $customer = a3Customer();

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'A3D'.uniqid(), 'customer_id' => $customer->id,
        'status' => 'processing', 'grand_total' => 50.00, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $itemId = DB::table('order_items')->insertGetId([
        'order_id' => $orderId, 'product_id' => 900000 + random_int(1, 9999),
        'type' => 'simple', 'name' => 'x', 'sku' => 'A3D'.uniqid(), 'qty_ordered' => 1,
        'price' => 50.00, 'total' => 50.00, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $ent = SkillEntitlement::create([
        'customer_id' => $customer->id, 'order_id' => $orderId, 'order_item_id' => $itemId,
        'product_id' => 900000 + random_int(1, 9999), 'status' => SkillEntitlement::STATUS_GRANTED,
        'delivery_channel' => 'app', 'license_key' => SkillEntitlement::newLicenseKey(), 'granted_at' => now(),
    ]);

    $service = app(AfterSaleService::class);

    $first = $service->apply($customer->id, $ent->id, 'other', '不想要了');
    expect($first['ok'])->toBeTrue();

    $again = $service->apply($customer->id, $ent->id, 'other', '再申请一次');
    expect($again['ok'])->toBeFalse()
        ->and($again['message'])->toContain('处理中');

    expect(AfterSale::where('entitlement_id', $ent->id)->count())->toBe(1);
});
