<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Services\Payment\PaymentManager;

/**
 * 支付下单(v3.4 步骤 4)。
 *
 * ⚠ 资质未就绪:默认网关 = mock(见 config/payment_gateways.php)。
 *   **不是"改一行 default 就活"**:`WechatGateway` 三个方法仍是抛异常的占位,
 *   切成 wechat 后这里每次都会走到下面的 catch 返回 503。生产环境更是直接拒绝解析
 *   (mock 与 wechat 的 `production_safe` 目前都是 false,见 `PaymentManager`)。
 * 业务口径:为"本人订单"创建支付单,返回前端发起支付所需参数。
 */
class PaymentController extends APIController
{
    public function __construct(protected PaymentManager $payments) {}

    /**
     * 为一个订单创建支付单。
     */
    public function create(int $orderId): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $order = OrderProxy::modelClass()::query()
            ->where('customer_id', $customer->id)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            return response()->json(['message' => '订单不存在。'], 404);
        }

        try {
            $payload = $this->payments->gateway()->createPayment($order);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'message' => '支付单已创建。',
            'data'    => $payload,
        ]);
    }
}
