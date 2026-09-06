<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Sales\Models\Order;

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

        $data['items'] = $order->items->map(function ($item) {
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
            ];
        })->values();

        if ($withDetails) {
            $data['billing_address']  = $order->billing_address ? $order->billing_address->toArray() : null;
            $data['shipping_address'] = $order->shipping_address ? $order->shipping_address->toArray() : null;
            $data['payment']          = $order->payment ? $order->payment->toArray() : null;
        }

        return $data;
    }

    /**
     * 客户自助取消本人待处理订单(信任层:真实自助服务)。
     */
    public function cancel(int $id): JsonResource
    {
        $customer = auth()->guard('customer')->user();

        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

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