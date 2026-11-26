<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;

class OrderController extends APIController
{
    public function __construct(protected OperationLogger $oplog) {}

    /**
     * Order listings of the authenticated customer.
     */
    public function index(): JsonResource
    {
        $customer = auth()->guard('customer')->user();

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['items'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $orders->through(fn ($order) => $this->formatOrder($order))->items();

        return new JsonResource([
            'data' => $data,
            'total' => $orders->total(),
            'current' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
        ]);
    }

    /**
     * 游客订单列表：返回**本机**的游客单。
     *
     * ⚠ 这里原来只有 whereNull('customer_id') —— 那会把所有人的游客订单返回给任何一个访客,
     *   A 打开订单页能看到 B 的游客单。现在按 X-Guest-Key(前端首次生成后持久化)过滤。
     *
     * 没有带标识(或标识太短)时返回空列表而不是"全部":宁可让用户看不到,
     * 也不能继续把别人的订单暴露出来。
     */
    public function guestIndex(Request $request): JsonResource
    {
        $guestKey = $this->guestKey($request);

        if (! $guestKey) {
            return new JsonResource([
                'data' => [],
                'total' => 0,
                'current' => 1,
                'last_page' => 1,
            ]);
        }

        // 支持 ?id={orderId} 拉取本机单笔游客订单完整详情(含地址与履约进度),无需破坏契约路由表
        if ($request->filled('id')) {
            $targetId = (int) $request->query('id');
            $single = $targetId > 0
                ? Order::query()
                    ->whereNull('customer_id')
                    ->where('guest_key', $guestKey)
                    ->whereKey($targetId)
                    ->with(['items', 'payment'])
                    ->first()
                : null;
            $formatted = $single ? $this->formatOrder($single, true) : null;

            return new JsonResource([
                'data' => $formatted ? [$formatted] : [],
                'detail' => $formatted,
                'total' => $formatted ? 1 : 0,
                'current' => 1,
                'last_page' => 1,
            ]);
        }

        $orders = Order::query()
            ->whereNull('customer_id')
            ->where('guest_key', $guestKey)
            ->with(['items'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $orders->through(fn ($order) => $this->formatOrder($order))->items();

        return new JsonResource([
            'data' => $data,
            'total' => $orders->total(),
            'current' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
        ]);
    }

    /**
     * Full order detail of the authenticated customer (with fallback for same-device pre-login guest order).
     */
    public function show(int $id): JsonResource
    {
        $customer = auth()->guard('customer')->user();
        $guestKey = $this->guestKey(request());

        $order = Order::query()
            ->where('id', $id)
            ->where(function ($q) use ($customer, $guestKey) {
                $q->where('customer_id', $customer->id);
                if ($guestKey) {
                    $q->orWhere(function ($gq) use ($guestKey) {
                        $gq->whereNull('customer_id')->where('guest_key', $guestKey);
                    });
                }
            })
            ->with(['items', 'payment'])
            ->first();

        if (! $order) {
            return new JsonResource([
                'message' => 'Order not found.',
            ]);
        }

        return new JsonResource([
            'data' => $this->formatOrder($order, true),
        ]);
    }

    /**
     * Convert an order model into a frontend-friendly array.
     */
    protected function formatOrder(Order $order, bool $withDetails = false): array
    {
        $firstSceneRental = null;
        $firstRental = null;
        $totalDepositCents = 0;

        foreach ($order->items as $orderItem) {
            $add = is_array($orderItem->additional ?? null) ? $orderItem->additional : [];
            if (! empty($add['scene_rental']) && is_array($add['scene_rental'])) {
                if ($firstSceneRental === null) {
                    $firstSceneRental = $add['scene_rental'];
                }
                if (! empty($add['scene_rental']['deposit_yuan'])) {
                    $totalDepositCents += (int) round(((float) $add['scene_rental']['deposit_yuan']) * 100);
                }
            } elseif (! empty($add['rental']) && is_array($add['rental'])) {
                if ($firstRental === null) {
                    $firstRental = $add['rental'];
                }
                if (! empty($add['rental']['deposit_yuan'])) {
                    $totalDepositCents += (int) round(((float) $add['rental']['deposit_yuan']) * 100);
                }
            }
        }

        if ($firstSceneRental !== null) {
            $firstSceneRental['total_qty'] = (int) ($order->items_qty ?: $order->total_qty_ordered ?: 1);
            if ($totalDepositCents > 0) {
                $firstSceneRental['deposit_yuan'] = number_format($totalDepositCents / 100, 2, '.', '');
            }
        }
        if ($firstRental !== null) {
            $firstRental['total_qty'] = (int) ($order->items_qty ?: $order->total_qty_ordered ?: 1);
            if ($totalDepositCents > 0) {
                $firstRental['deposit_yuan'] = number_format($totalDepositCents / 100, 2, '.', '');
            }
        }

        $data = [
            'id' => $order->id,
            'increment_id' => $order->increment_id,
            'is_guest' => (bool) $order->is_guest,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'order_type' => $order->order_type,
            'created_at' => $order->created_at->format('Y-m-d H:i:s'),
            'items_count' => $order->items_count,
            'items_qty' => $order->items_qty,
            'grand_total' => $order->grand_total,
            'grand_total_formatted' => core()->formatPrice($order->grand_total),
            'sub_total' => $order->sub_total,
            'sub_total_formatted' => core()->formatPrice($order->sub_total),
            'tax_total' => $order->tax_total,
            'tax_total_formatted' => core()->formatPrice($order->tax_total),
            'shipping_amount' => $order->shipping_amount,
            'shipping_amount_formatted' => core()->formatPrice($order->shipping_amount),
            'discount_amount' => $order->discount_amount ?? 0,
            'customer_name' => trim($order->customer_first_name.' '.$order->customer_last_name),
            'customer_email' => $order->customer_email,
            'scene_workflow' => app(\Webkul\Shop\Services\Scenes\SceneLinkedOrderService::class)->context((int) $order->id),
            'scene_rental' => $firstSceneRental,
            'rental_info' => $firstSceneRental ?: $firstRental,
        ];

        // 技能单:把授权信息接进订单详情。
        // 「设备SN码」「交付方式」这两个字段订单详情页早就画好了,但一直没人赋值,永远显示「—」。
        // 一次查出整单授权按 order_item_id 索引,避免逐条目查库。
        $entitlements = collect();

        if ($order->order_type === 'skill') {
            $entitlements = SkillEntitlement::query()
                ->where('order_id', $order->id)
                ->with('device')
                ->get()
                ->keyBy('order_item_id');
        }

        $data['items'] = $order->items->map(function ($item) use ($entitlements) {
            $entitlement = $entitlements->get($item->id);

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'sku' => $item->sku,
                'type' => $item->type,
                'qty_ordered' => $item->qty_ordered,
                'price' => $item->price,
                'price_formatted' => core()->formatPrice($item->price),
                'total' => $item->total,
                'total_formatted' => core()->formatPrice($item->total),
                'base_image' => $item->product?->base_image,
                // 下单瞬间的快照(技能单才有,其余类型为 null)
                'additional' => $item->additional,
                // 授权侧的真实状态
                'license_key' => $entitlement?->license_key,
                'device_sn' => $entitlement?->device?->sn,
                'skill_status' => $entitlement?->status,
            ];
        })->values();

        // 订单级的「设备SN码 / 交付方式」—— 取首个技能授权。多技能单目前展示第一条。
        $firstEntitlement = $entitlements->first();

        $data['sn_code'] = $firstEntitlement?->device?->sn;

        $data['delivery_method'] = $firstEntitlement
            ? app(SkillDeliveryManager::class)->label($firstEntitlement->delivery_channel)
            : null;

        if ($withDetails) {
            $data['billing_address'] = $order->billing_address ? $order->billing_address->toArray() : null;
            $data['shipping_address'] = $order->shipping_address ? $order->shipping_address->toArray() : null;
            $data['payment'] = $order->payment ? $order->payment->toArray() : null;

            if (\Illuminate\Support\Facades\Schema::hasTable('merchant_orders')) {
                $mOrders = DB::table('merchant_orders')
                    ->where('bagisto_order_id', $order->id)
                    ->orderBy('id')
                    ->get();
                if ($mOrders->isNotEmpty()) {
                    $firstM = $mOrders->first();
                    $data['fulfillment'] = [
                        'merchant_order_no' => $firstM->order_no,
                        'status'            => $firstM->status,
                        'carrier'           => $firstM->carrier,
                        'tracking_no'       => $firstM->tracking_no,
                        'device_sn'         => $firstM->device_sn,
                        'contract_no'       => $firstM->contract_no,
                        'shipped_at'        => $firstM->shipped_at,
                        'returned_at'       => $firstM->returned_at,
                        'inspected_at'      => $firstM->inspected_at,
                        'completed_at'      => $firstM->completed_at,
                    ];
                    $timeline = [
                        ['title' => '订单已创建，锁定设备库存', 'time' => $order->created_at->format('Y-m-d H:i:s'), 'done' => true],
                    ];
                    if ($firstM->shipped_at) {
                        $shipDesc = '商家已出库发货';
                        if ($firstM->carrier || $firstM->tracking_no) {
                            $shipDesc .= '（'.trim(($firstM->carrier ?? '').' '.($firstM->tracking_no ?? '')).'）';
                        }
                        if ($firstM->device_sn) {
                            $shipDesc .= ' · SN: '.$firstM->device_sn;
                        }
                        $timeline[] = ['title' => $shipDesc, 'time' => (string) $firstM->shipped_at, 'done' => true];
                    }
                    if ($firstM->returned_at) {
                        $timeline[] = ['title' => '设备已登记归还待验机', 'time' => (string) $firstM->returned_at, 'done' => true];
                    }
                    if ($firstM->inspected_at) {
                        $timeline[] = ['title' => '归还验机定损已完成', 'time' => (string) $firstM->inspected_at, 'done' => true];
                    }
                    if ($firstM->completed_at) {
                        $timeline[] = ['title' => '订单履约已完结', 'time' => (string) $firstM->completed_at, 'done' => true];
                    } elseif (! empty($firstM->cancelled_at)) {
                        $timeline[] = ['title' => '订单已取消，库存已释放', 'time' => (string) $firstM->cancelled_at, 'done' => true];
                    }
                    $lastIdx = count($timeline) - 1;
                    $timeline[$lastIdx]['isLast'] = true;
                    $data['timeline'] = $timeline;
                }
            }
        }

        return $data;
    }

    /**
     * 客户自助取消本人待处理订单(信任层:真实自助服务)。
     *
     * 登录用户按 customer_id 限定(同时兼容本机登录前创建的 X-Guest-Key 游客单);游客按 X-Guest-Key 限定。
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $guestKey = $this->guestKey($request);

        if (! $customer && ! $guestKey) {
            return $this->fail(ApiErrorCode::GUEST_KEY_REQUIRED, '订单不存在。', 200);
        }

        // 与支付回调及商家发货共享订单行锁。必须在拿到最新订单后重检归属、状态及关联商家出库状态。
        $outcome = DB::transaction(function () use ($id, $customer, $guestKey) {
            $query = Order::query()->whereKey($id);

            if ($customer) {
                $query->where(function ($q) use ($customer, $guestKey) {
                    $q->where('customer_id', $customer->id);
                    if ($guestKey) {
                        $q->orWhere(function ($gq) use ($guestKey) {
                            $gq->whereNull('customer_id')->where('guest_key', $guestKey);
                        });
                    }
                });
            } else {
                $query->whereNull('customer_id')->where('guest_key', $guestKey);
            }

            $order = $query->lockForUpdate()->first();

            if (! $order) {
                return ['order' => null, 'canceled' => false];
            }
            if (app(\Webkul\Shop\Services\Scenes\SceneLinkedOrderService::class)->context((int) $order->id)) {
                return ['order' => $order, 'canceled' => false, 'scene' => true];
            }

            if ($order->status !== 'pending') {
                return ['order' => $order, 'canceled' => false];
            }

            // 同步锁检商家履约单：若商家已出库则禁止单方取消；若待发货则同步取消并原路释放锁定库存
            if (\Illuminate\Support\Facades\Schema::hasTable('merchant_orders')) {
                $mOrders = \Webkul\Shop\Models\MerchantOrder::query()
                    ->where('bagisto_order_id', $order->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($mOrders as $mOrder) {
                    if (! in_array($mOrder->status, [
                        \Webkul\Shop\Models\MerchantOrder::STATUS_PENDING_SHIPMENT,
                        \Webkul\Shop\Models\MerchantOrder::STATUS_CANCELLED,
                    ], true)) {
                        return ['order' => $order, 'canceled' => false, 'shipped' => true];
                    }
                }

                $hasExtTable = \Illuminate\Support\Facades\Schema::hasTable('merchant_product_exts');
                $hasLockLedger = \Illuminate\Support\Facades\Schema::hasTable('merchant_inventory_locks');

                foreach ($mOrders as $mOrder) {
                    if ($mOrder->status !== \Webkul\Shop\Models\MerchantOrder::STATUS_PENDING_SHIPMENT) {
                        continue;
                    }
                    if ($hasExtTable) {
                        $ext = DB::table('merchant_product_exts')
                            ->where('seller_id', $mOrder->seller_id)
                            ->where('product_id', $mOrder->product_id)
                            ->lockForUpdate()
                            ->first();
                        if ($ext) {
                            $qty = max(1, (int) $mOrder->qty);
                            $nextLocked = max(0, (int) ($ext->locked_stock ?? 0) - $qty);
                            $nextRentable = $mOrder->fulfillment_type === 'rental'
                                ? ((int) ($ext->rentable_stock ?? 0) + $qty)
                                : (int) ($ext->rentable_stock ?? 0);
                            $nextSellable = $mOrder->fulfillment_type === 'rental'
                                ? (int) ($ext->sellable_stock ?? 0)
                                : ((int) ($ext->sellable_stock ?? 0) + $qty);

                            DB::table('merchant_product_exts')->where('id', $ext->id)->update([
                                'locked_stock'   => $nextLocked,
                                'rentable_stock' => $nextRentable,
                                'sellable_stock' => $nextSellable,
                                'version'        => (int) ($ext->version ?? 1) + 1,
                                'updated_at'     => now(),
                            ]);

                            if ($hasLockLedger) {
                                \Webkul\Shop\Models\MerchantInventoryLock::create([
                                    'seller_id'         => (int) $mOrder->seller_id,
                                    'shop_id'           => (int) $mOrder->shop_id,
                                    'product_id'        => (int) $mOrder->product_id,
                                    'action_type'       => 'cancel_release',
                                    'qty_change'        => $qty,
                                    'sellable_after'    => $nextSellable,
                                    'rentable_after'    => $nextRentable,
                                    'locked_after'      => $nextLocked,
                                    'maintenance_after' => (int) ($ext->maintenance_stock ?? 0),
                                    'reference_no'      => $mOrder->order_no,
                                    'reason'            => '买家取消商城订单，锁定库存原路释放 (商城单 '.$order->increment_id.')',
                                    'actor_id'          => $customer?->id,
                                ]);
                            }
                        }
                    }

                    $mOrder->status = \Webkul\Shop\Models\MerchantOrder::STATUS_CANCELLED;
                    $mOrder->cancel_party = 'buyer';
                    $mOrder->cancel_reason = '买家在商城端自助取消订单';
                    $mOrder->cancelled_at = now();
                    $mOrder->version = (int) $mOrder->version + 1;
                    $mOrder->save();
                }
            }

            $order->status = 'canceled';
            $order->save();

            // 撤销与取消在同一事务、同一订单锁内，避免付款并发重发授权。
            \Illuminate\Support\Facades\Event::dispatch('sales.order.cancel.after', $order);

            return ['order' => $order, 'canceled' => true];
        });

        $order = $outcome['order'];

        if (! $order) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在。', 200);
        }

        if (! $outcome['canceled']) {
            if ($outcome['scene'] ?? false) {
                return response()->json(['code' => 'SCENE_CHANGE_REQUIRED', 'message' => '场景订单请进入交付项目提交取消申请，资源、费用与退款需分别核实。'], 409);
            }
            if ($outcome['shipped'] ?? false) {
                return $this->fail(ApiErrorCode::ORDER_NOT_CANCELABLE, '商家已出库或进入履约流程，请联系客服处理。', 200);
            }

            return $this->fail(ApiErrorCode::ORDER_NOT_CANCELABLE, '仅待处理订单可自助取消，请联系客服。', 200);
        }

        // X4 · 订单取消留痕。取消会**对称撤销技能授权**(M4),是一次会影响交付的资金/授权动作,
        //   此前 Shop 侧一处不留痕。用 log() 非 logOrFail:取消是用户的正当自助动作,
        //   审计写不进去不该让取消失败。
        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $customer?->id,
            'order.cancel',
            'order',
            $order->id,
            ['order_increment_id' => $order->increment_id, 'status' => 'canceled'],
            $request->ip(),
        );

        return response()->json([
            'success' => true,
            'message' => '订单已取消。',
            'status' => 'canceled',
        ]);
    }
}
