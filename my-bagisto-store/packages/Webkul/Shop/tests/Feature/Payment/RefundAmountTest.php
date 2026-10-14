<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

/**
 * M5 · 退款金额恒为 0,退款单号可能撞号。
 *
 * 背景:`AfterSaleService::approve()` 此前恒 `refund(null, 0.0, $outRefundNo)`、
 * `RefundRequest.amount` 也恒落 0;退款单号 `'RF'.$id.now()->format('ymdHis')`
 * 秒级时间戳,同秒重试会撞。
 *
 * 修法:金额取自该授权对应订单条目的实付(OrderItem.total);退款前校验
 * "已退金额累计不得超实付";退款单号换 ULID(不可碰撞)。
 */

function refundCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Refund',
        'last_name'   => 'Test',
        'email'       => 'refund'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function refundOrder(Customer $customer, float $grandTotal): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => 'REFUND'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing',
        'grand_total'  => $grandTotal,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function refundOrderItem(int $orderId, float $total): int
{
    return DB::table('order_items')->insertGetId([
        'order_id'     => $orderId,
        'product_id'   => 900000 + random_int(1, 9999),
        'type'         => 'simple',
        'name'         => '技能包',
        'sku'          => 'SKU'.uniqid(),
        'qty_ordered'  => 1,
        'price'        => $total,
        'total'        => $total,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function refundEntitlement(Customer $customer, int $orderId, int $orderItemId): SkillEntitlement
{
    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => $orderItemId,
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);
}

function refundAfterSale(int $orderId, int $orderItemId, int $entitlementId, int $customerId): int
{
    return AfterSale::create([
        'order_id'       => $orderId,
        'order_item_id'  => $orderItemId,
        'entitlement_id' => $entitlementId,
        'customer_id'    => $customerId,
        'type'           => AfterSale::TYPE_REFUND,
        'policy_basis'   => 'not_installed',
        'status'         => AfterSale::STATUS_APPLIED,
    ])->id;
}

it('999 元技能走完售后 → RefundRequest.amount 是 999(不是 0),且落退款单号', function () {
    $customer = refundCustomer();
    $orderId = refundOrder($customer, 999.00);
    $itemId = refundOrderItem($orderId, 999.00);
    $entitlement = refundEntitlement($customer, $orderId, $itemId);
    $afterSaleId = refundAfterSale($orderId, $itemId, $entitlement->id, $customer->id);

    $result = app(AfterSaleService::class)->approve($afterSaleId, 1);

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['amount'])->toBe(999.0);

    $refund = RefundRequest::query()->where('after_sale_id', $afterSaleId)->first();

    expect($refund)->not->toBeNull()
        ->and((float) $refund->amount)->toBe(999.0)
        ->and($refund->wechat_refund_no)->not->toBeNull();
});

it('同秒连发两次退款 → 退款单号不同(不撞号)', function () {
    $customer = refundCustomer();
    $orderId = refundOrder($customer, 100.00);

    $itemA = refundOrderItem($orderId, 100.00);
    $entA = refundEntitlement($customer, $orderId, $itemA);
    $asA = refundAfterSale($orderId, $itemA, $entA->id, $customer->id);

    $itemB = refundOrderItem($orderId, 100.00);
    $entB = refundEntitlement($customer, $orderId, $itemB);
    $asB = refundAfterSale($orderId, $itemB, $entB->id, $customer->id);

    $service = app(AfterSaleService::class);
    $service->approve($asA, 1);
    $service->approve($asB, 1);

    $noA = RefundRequest::query()->where('after_sale_id', $asA)->value('wechat_refund_no');
    $noB = RefundRequest::query()->where('after_sale_id', $asB)->value('wechat_refund_no');

    expect($noA)->not->toBeNull()
        ->and($noB)->not->toBeNull()
        ->and($noA)->not->toBe($noB);
});

it('同一订单条目已退满 → 再来一单售后被拒(累计不得超实付)', function () {
    $customer = refundCustomer();
    $orderId = refundOrder($customer, 300.00);
    $itemId = refundOrderItem($orderId, 300.00);

    // 一个订单条目只允许一条授权(唯一键),但可以有多个售后单 → 用它验证累计口径
    $ent = refundEntitlement($customer, $orderId, $itemId);
    $asA = refundAfterSale($orderId, $itemId, $ent->id, $customer->id);
    $asB = refundAfterSale($orderId, $itemId, $ent->id, $customer->id);

    $service = app(AfterSaleService::class);

    $first = $service->approve($asA, 1);
    expect($first['ok'])->toBeTrue();

    $second = $service->approve($asB, 1);
    expect($second['ok'])->toBeFalse();

    // 累计退款不超过实付 300
    $total = (float) RefundRequest::query()
        ->join('after_sale', 'after_sale.id', '=', 'refund_request.after_sale_id')
        ->where('after_sale.order_item_id', $itemId)
        ->whereIn('refund_request.status', ['success', 'pending'])
        ->sum('refund_request.amount');

    expect($total)->toBeLessThanOrEqual(300.0);
});
