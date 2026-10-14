<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\JsonResponse;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;

class OrderController extends APIController
{

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
            'data'       => $data,
            'total'      => $orders->total(),
            'current'    => $orders->currentPage(),
            'last_page'  => $orders->lastPage(),
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
                'data'      => [],
                'total'     => 0,
                'current'   => 1,
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
            'data'      => $data,
            'total'     => $orders->total(),
            'current'   => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
        ]);
    }

    /**
     * Full order detail of the authenticated customer.
     *
     * @param  int  $id
     */
    public function show(int $id): JsonResource
    {
        $customer = auth()->guard('customer')->user();

        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
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
        $data = [
            'id'                       => $order->id,
            'increment_id'             => $order->increment_id,
            'status'                   => $order->status,
            'status_label'             => $order->status_label,
            'order_type'               => $order->order_type,
            'created_at'               => $order->created_at->format('Y-m-d H:i:s'),
            'items_count'              => $order->items_count,
            'items_qty'                => $order->items_qty,
            'grand_total'              => $order->grand_total,
            'grand_total_formatted'    => core()->formatPrice($order->grand_total),
            'sub_total'                => $order->sub_total,
            'sub_total_formatted'      => core()->formatPrice($order->sub_total),
            'tax_total'                => $order->tax_total,
            'tax_total_formatted'      => core()->formatPrice($order->tax_total),
            'shipping_amount'          => $order->shipping_amount,
            'shipping_amount_formatted' => core()->formatPrice($order->shipping_amount),
            'customer_name'            => $order->customer_first_name.' '.$order->customer_last_name,
            'customer_email'           => $order->customer_email,
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
                'id'              => $item->id,
                'product_id'      => $item->product_id,
                'name'            => $item->name,
                'sku'             => $item->sku,
                'type'            => $item->type,
                'qty_ordered'     => $item->qty_ordered,
                'price'           => $item->price,
                'price_formatted' => core()->formatPrice($item->price),
                'total'           => $item->total,
                'total_formatted' => core()->formatPrice($item->total),
                'base_image'      => $item->product?->base_image,
                // 下单瞬间的快照(技能单才有,其余类型为 null)
                'additional'      => $item->additional,
                // 授权侧的真实状态
                'license_key'     => $entitlement?->license_key,
                'device_sn'       => $entitlement?->device?->sn,
                'skill_status'    => $entitlement?->status,
            ];
        })->values();

        // 订单级的「设备SN码 / 交付方式」—— 取首个技能授权。多技能单目前展示第一条。
        $firstEntitlement = $entitlements->first();

        $data['sn_code'] = $firstEntitlement?->device?->sn;

        $data['delivery_method'] = $firstEntitlement
            ? app(SkillDeliveryManager::class)->label($firstEntitlement->delivery_channel)
            : null;

        if ($withDetails) {
            $data['billing_address']  = $order->billing_address ? $order->billing_address->toArray() : null;
            $data['shipping_address'] = $order->shipping_address ? $order->shipping_address->toArray() : null;
            $data['payment']          = $order->payment ? $order->payment->toArray() : null;
        }

        return $data;
    }

    /**
     * 客户自助取消本人待处理订单(信任层:真实自助服务)。
     *
     * 登录用户按 customer_id 限定;游客按本机标识(X-Guest-Key)限定。
     *
     * ⚠ 原来只有 `->where('customer_id', $customer?->id)`,游客时 $customer 为空,
     *   生成的 SQL 是 `customer_id = NULL` —— 在 SQL 里永远不成立,所以**游客根本取消不了自己的单**。
     *   必须分开写:登录用 =,游客用 IS NULL + guest_key。
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $query = Order::query()->where('id', $id);

        if ($customer) {
            $query->where('customer_id', $customer->id);
        } else {
            $guestKey = $this->guestKey($request);

            if (! $guestKey) {
                return response()->json([
                    'success' => false,
                    'message' => '订单不存在。',
                ]);
            }

            $query->whereNull('customer_id')->where('guest_key', $guestKey);
        }

        $order = $query->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => '订单不存在。',
            ]);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => '仅待处理订单可自助取消，请联系客服。',
            ]);
        }

        $order->status = 'canceled';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => '订单已取消。',
            'status'  => 'canceled',
        ]);
    }
}