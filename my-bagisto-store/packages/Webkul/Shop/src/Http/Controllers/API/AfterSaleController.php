<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\MerchantAfterSalesTicket;
use Webkul\Shop\Models\MerchantOrder;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

/**
 * 全品类售后与维保中心 —— 用户侧（升级支持虚拟技能 D10 规则 + 整机租赁/购买/配件订单售后双向打通）。
 */
class AfterSaleController extends APIController
{
    public function __construct(
        protected AfterSaleService $service,
        protected OperationLogger $oplog,
    ) {}

    /**
     * 我的售后列表（聚合平台 after_sale 与关联商户维保/退换工单 merchant_after_sales_tickets）。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();
        $cid = (int) $customer->id;

        $rows = AfterSale::query()
            ->where('customer_id', $cid)
            ->orderByDesc('id')
            ->get();

        $orderIds = $rows->pluck('order_id')->filter()->unique()->values()->all();
        $ordersMap = $orderIds !== []
            ? Order::query()->whereIn('id', $orderIds)->with('items')->get()->keyBy('id')
            : collect();

        $entitlementIds = $rows->pluck('entitlement_id')->filter()->unique()->values()->all();
        $entitlementsMap = $entitlementIds !== []
            ? SkillEntitlement::query()->whereIn('id', $entitlementIds)->with('product')->get()->keyBy('id')
            : collect();

        $refundMap = Schema::hasTable('refund_request') && $rows->isNotEmpty()
            ? RefundRequest::query()
                ->whereIn('after_sale_id', $rows->pluck('id'))
                ->orderByDesc('id')
                ->get()
                ->unique('after_sale_id')
                ->keyBy('after_sale_id')
            : collect();

        $items = $rows->map(function (AfterSale $a) use ($ordersMap, $entitlementsMap, $refundMap) {
            /** @var Order|null $order */
            $order = $a->order_id ? $ordersMap->get($a->order_id) : null;
            /** @var SkillEntitlement|null $ent */
            $ent = $a->entitlement_id ? $entitlementsMap->get($a->entitlement_id) : null;
            $refund = $refundMap->get($a->id);

            $domain = ($a->target_domain ?? null) ?: ($a->entitlement_id ? AfterSale::DOMAIN_SKILL : AfterSale::DOMAIN_ORDER);
            $title = $ent?->product?->name
                ?: ($order && $order->items->isNotEmpty() ? $order->items->pluck('name')->implode('、') : null);

            return [
                'id'              => $a->id,
                'source'          => 'platform',
                'target_domain'   => $domain,
                'entitlement_id'  => $a->entitlement_id,
                'order_id'        => $a->order_id,
                'order_no'        => $order?->increment_id,
                'order_type'      => $order?->order_type ?: ($domain === 'skill' ? 'skill' : 'buy'),
                'title'           => $title,
                'type'            => $a->type,
                'policy_basis'    => $a->policy_basis,
                'status'          => $a->status,
                'reason_category' => $a->reason_category,
                'detail'          => $a->detail,
                'review_note'     => $a->review_note ?? null,
                'appeal_detail'   => $a->appeal_detail ?? null,
                'appealed_at'     => optional($a->appealed_at)->toDateTimeString(),
                'refund_amount'   => $refund ? (float) $refund->amount : ($order ? (float) $order->grand_total : null),
                'can_cancel'      => in_array($a->status, [AfterSale::STATUS_APPLIED, AfterSale::STATUS_REVIEWING], true),
                'can_appeal'      => $a->status === AfterSale::STATUS_REJECTED && empty($a->appealed_at),
                'reviewed_at'     => optional($a->reviewed_at)->toDateTimeString(),
                'created_at'      => optional($a->created_at)->toDateTimeString(),
            ];
        })->values()->all();

        // 合并商户侧直接为该买家登记的租赁维保/换货/退货单（避免重复显示已关联的工单）
        if (Schema::hasTable('merchant_after_sales_tickets') && Schema::hasTable('merchant_orders')) {
            $myMerchantOrders = MerchantOrder::query()
                ->where('buyer_id', $cid)
                ->get()
                ->keyBy('id');

            if ($myMerchantOrders->isNotEmpty()) {
                $mTickets = MerchantAfterSalesTicket::query()
                    ->whereIn('merchant_order_id', $myMerchantOrders->keys())
                    ->orderByDesc('id')
                    ->get();

                foreach ($mTickets as $mt) {
                    $mo = $myMerchantOrders->get($mt->merchant_order_id);
                    $mappedStatus = match ($mt->status) {
                        MerchantAfterSalesTicket::STATUS_OPEN       => AfterSale::STATUS_APPLIED,
                        MerchantAfterSalesTicket::STATUS_PROCESSING => AfterSale::STATUS_REVIEWING,
                        MerchantAfterSalesTicket::STATUS_RESOLVED   => AfterSale::STATUS_REFUNDED,
                        MerchantAfterSalesTicket::STATUS_REJECTED   => AfterSale::STATUS_REJECTED,
                        default                                     => $mt->status,
                    };
                    $items[] = [
                        'id'              => 'M-'.$mt->id,
                        'ticket_no'       => $mt->ticket_no,
                        'source'          => 'merchant',
                        'target_domain'   => AfterSale::DOMAIN_ORDER,
                        'entitlement_id'  => null,
                        'order_id'        => $mo?->bagisto_order_id,
                        'order_no'        => $mo?->order_no,
                        'order_type'      => $mo?->fulfillment_type === 'rental' ? 'rent' : 'buy',
                        'title'           => $mo?->product_name ?: ('履约单 #'.$mt->ticket_no),
                        'device_sn'       => $mt->device_sn ?: $mo?->device_sn,
                        'type'            => $mt->ticket_type,
                        'policy_basis'    => $mo?->fulfillment_type === 'rental' ? 'rental_sla' : 'merchant_warranty',
                        'status'          => $mappedStatus,
                        'reason_category' => $mt->ticket_type,
                        'detail'          => $mt->reason,
                        'review_note'     => $mt->resolution_notes,
                        'appeal_detail'   => null,
                        'appealed_at'     => null,
                        'refund_amount'   => $mt->refund_cents > 0 ? round($mt->refund_cents / 100, 2) : null,
                        'can_cancel'      => false,
                        'can_appeal'      => false,
                        'reviewed_at'     => optional($mt->resolved_at)->toDateTimeString(),
                        'created_at'      => optional($mt->created_at)->toDateTimeString(),
                    ];
                }
            }
        }

        return response()->json(['data' => $items]);
    }

    /**
     * 发起售后（兼容虚拟技能 entitlement_id 与实物/租赁订单 order_id 双入口）。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        // 分支 1：传了 order_id 且未传 entitlement_id —— 整机租赁 / 整机购买 / 配件订单售后
        if ($request->filled('order_id') && ! $request->filled('entitlement_id')) {
            return $this->storeOrderAfterSale($request, (int) $customer->id);
        }

        // 分支 2：既有虚拟技能授权售后（保持原有校验与错误码 100% 兼容）
        $payload = $request->validate([
            'entitlement_id'  => ['required', 'integer'],
            'reason_category' => ['nullable', 'string', 'max:64'],
            'detail'          => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->service->apply(
            $customer->id,
            (int) $payload['entitlement_id'],
            $payload['reason_category'] ?? null,
            $payload['detail'] ?? null,
        );

        if (! $result['ok']) {
            return $this->fail(ApiErrorCode::AFTER_SALE_REJECTED, (string) $result['message'], 422, ['data' => $result['data'] ?? null]);
        }

        return response()->json(
            ['message' => $result['message'], 'data' => $result['data'] ?? null],
            201
        );
    }

    /**
     * POST /api/after-sale/{id}/cancel —— 客户主动撤回待受理/审核中的售后单。
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $afterSale = AfterSale::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $afterSale) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '售后记录不存在。', 404);
        }

        if (! in_array($afterSale->status, [AfterSale::STATUS_APPLIED, AfterSale::STATUS_REVIEWING], true)) {
            return $this->fail(ApiErrorCode::CONFLICT, '当前状态不可撤回。', 409);
        }

        $updates = ['status' => AfterSale::STATUS_CANCELLED];
        if (Schema::hasColumn('after_sale', 'cancelled_at')) {
            $updates['cancelled_at'] = now();
        }
        $afterSale->update($updates);

        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $customer->id,
            'after_sale.cancel',
            'after_sale',
            $afterSale->id,
            [],
            $request->ip()
        );

        return response()->json([
            'message' => '售后申请已撤回。',
            'data'    => ['id' => $afterSale->id, 'status' => $afterSale->status],
        ]);
    }

    /**
     * POST /api/after-sale/{id}/appeal —— D10 被驳回售后单补充证据发起人工复核申诉（每单限申诉一次）。
     */
    public function appeal(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'appeal_detail' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'appeal_detail.required' => '请填写申诉说明与事实依据。',
            'appeal_detail.min'      => '申诉说明至少填写 5 个字符。',
        ]);

        $afterSale = AfterSale::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $afterSale) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '售后记录不存在。', 404);
        }

        if ($afterSale->status !== AfterSale::STATUS_REJECTED) {
            return $this->fail(ApiErrorCode::CONFLICT, '仅已驳回的售后单可发起复核申诉。', 409);
        }

        if (! empty($afterSale->appealed_at)) {
            return $this->fail(ApiErrorCode::CONFLICT, '该售后单已发起过复核申诉，如需进一步沟通请联系在线客服。', 409);
        }

        $updates = ['status' => AfterSale::STATUS_REVIEWING];
        if (Schema::hasColumn('after_sale', 'appeal_detail')) {
            $updates['appeal_detail'] = trim((string) $payload['appeal_detail']);
            $updates['appealed_at'] = now();
        } else {
            $updates['detail'] = trim((string) $afterSale->detail."\n【客户复核申诉】".trim((string) $payload['appeal_detail']));
        }
        $afterSale->update($updates);

        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $customer->id,
            'after_sale.appeal',
            'after_sale',
            $afterSale->id,
            [],
            $request->ip()
        );

        return response()->json([
            'message' => '申诉已提交，已转交平台人工复核队列。',
            'data'    => [
                'id'         => $afterSale->id,
                'status'     => $afterSale->status,
                'can_appeal' => false,
            ],
        ]);
    }

    /**
     * 实物 / 租赁 / 配件订单售后建单，并自动同步对应商户后台售后工单（如关联商户订单）。
     */
    protected function storeOrderAfterSale(Request $request, int $customerId): JsonResponse
    {
        $payload = $request->validate([
            'order_id'        => ['required', 'integer'],
            'type'            => ['nullable', 'string', 'in:refund,return,return_refund,exchange,repair,complaint'],
            'reason_category' => ['required', 'string', 'max:64'],
            'detail'          => ['required', 'string', 'min:4', 'max:2000'],
            'device_sn'       => ['nullable', 'string', 'max:64'],
        ], [
            'reason_category.required' => '请选择售后原因类别。',
            'detail.required'          => '请简要描述遇到的商品或服务问题。',
            'detail.min'               => '问题描述至少 4 个字符。',
        ]);

        $order = Order::query()
            ->where('customer_id', $customerId)
            ->where('id', (int) $payload['order_id'])
            ->with('items')
            ->first();

        if (! $order) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于你。', 404);
        }

        if ($order->order_type === 'skill') {
            return $this->fail(ApiErrorCode::AFTER_SALE_REJECTED, '虚拟技能订单请在「我的技能」中按对应授权发起售后。', 422);
        }

        if (in_array($order->status, [Order::STATUS_CANCELED, Order::STATUS_FRAUD], true)) {
            return $this->fail(ApiErrorCode::AFTER_SALE_REJECTED, '已取消订单无需发起售后。', 422);
        }

        return DB::transaction(function () use ($order, $customerId, $payload, $request) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $exists = AfterSale::query()
                ->where('customer_id', $customerId)
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', [AfterSale::STATUS_APPLIED, AfterSale::STATUS_REVIEWING])
                ->exists();

            if ($exists) {
                return $this->fail(ApiErrorCode::CONFLICT, '该订单已有处理中的售后单，请勿重复提交。', 409);
            }

            $rawType = $payload['type'] ?? 'return_refund';
            $type = $rawType === 'return' ? 'return_refund' : $rawType;
            $firstItem = $order->items->first();

            $createData = [
                'order_id'        => $lockedOrder->id,
                'order_item_id'   => $firstItem?->id,
                'entitlement_id'  => null,
                'customer_id'     => $customerId,
                'type'            => $type,
                'reason_category' => trim((string) $payload['reason_category']),
                'detail'          => trim((string) $payload['detail']),
                'policy_basis'    => $lockedOrder->order_type === 'rent' ? 'rental_sla' : 'physical_warranty',
                'status'          => AfterSale::STATUS_REVIEWING,
            ];
            if (Schema::hasColumn('after_sale', 'target_domain')) {
                $createData['target_domain'] = AfterSale::DOMAIN_ORDER;
            }

            $afterSale = AfterSale::query()->create($createData);

            // 若该订单已映射至商家履约单，自动联动创建一条商家维保/退换工单
            if (Schema::hasTable('merchant_orders') && Schema::hasTable('merchant_after_sales_tickets')) {
                $mOrder = MerchantOrder::query()
                    ->where('bagisto_order_id', $lockedOrder->id)
                    ->first();

                if ($mOrder) {
                    $mType = match ($type) {
                        'repair'   => 'repair',
                        'exchange' => 'exchange',
                        default    => 'return_refund',
                    };
                    MerchantAfterSalesTicket::query()->create([
                        'ticket_no'               => 'MAS'.now()->format('ymdHis').strtoupper(Str::random(4)),
                        'seller_id'               => $mOrder->seller_id,
                        'shop_id'                 => $mOrder->shop_id,
                        'merchant_order_id'       => $mOrder->id,
                        'product_id'              => $mOrder->product_id,
                        'ticket_type'             => $mType,
                        'device_sn'               => ! empty($payload['device_sn']) ? trim((string) $payload['device_sn']) : $mOrder->device_sn,
                        'requester_name'          => trim($lockedOrder->customer_first_name.' '.$lockedOrder->customer_last_name) ?: '客户',
                        'reason'                  => '['.$payload['reason_category'].'] '.trim((string) $payload['detail']),
                        'status'                  => MerchantAfterSalesTicket::STATUS_OPEN,
                        'refund_cents'            => 0,
                        'compensation_cents'      => 0,
                        'locks_maintenance_stock' => false,
                        'maintenance_released'    => false,
                        'version'                 => 1,
                    ]);
                }
            }

            $this->oplog->log(
                OperationLog::ACTOR_CUSTOMER,
                $customerId,
                'after_sale.order_apply',
                'after_sale',
                $afterSale->id,
                ['order_id' => $lockedOrder->id, 'type' => $type],
                $request->ip()
            );

            return response()->json([
                'message' => '售后申请已提交，服务团队将尽快核实处理。',
                'data'    => [
                    'id'            => $afterSale->id,
                    'order_id'      => $lockedOrder->id,
                    'target_domain' => AfterSale::DOMAIN_ORDER,
                    'policy_basis'  => $afterSale->policy_basis,
                    'status'        => $afterSale->status,
                    'can_cancel'    => true,
                ],
            ], 201);
        });
    }
}
