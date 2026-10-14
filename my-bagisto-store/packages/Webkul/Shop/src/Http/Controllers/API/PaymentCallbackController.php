<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Listeners\SkillDelivery;
use Webkul\Shop\Services\Payment\MockGateway;
use Webkul\Shop\Services\Payment\PaymentManager;

/**
 * 支付回调(v3.4 步骤 4)。
 *
 * ⚠ 本接口**不挂 customer 鉴权**(回调来自支付网关,不是用户)。生产必须:
 *   - 校验网关签名(微信资质就绪后在 WechatGateway::handleCallback 内做)
 *   - 幂等(同一订单重复回调不重复发授权)
 *
 * 支付成功后:订单置 processing → 触发 v1 既有的授权发放监听逻辑(幂等)。
 */
class PaymentCallbackController extends APIController
{
    public function __construct(protected PaymentManager $payments) {}

    public function handle(Request $request)
    {
        // mock 网关回调:带 out_trade_no + mock_paid=1
        //
        // ⚠ 网关解析失败要**明确拒绝**(503 = 让对方重试),不能在拿不到网关时
        //   继续往下走 —— 本接口不挂鉴权,而"把订单翻成 processing 并发授权"是实打实的
        //   价值转移。生产环境因 `production_safe` 守卫会走到这里(PaymentManager 抛错),
        //   这正是期望行为:宁可不处理,也不能凭一个自报参数发东西出去。
        try {
            $gateway = $this->payments->gateway();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $result = $gateway->handleCallback($request->all());

        if (! $result->paid) {
            return response()->json(['message' => '支付未成功:'.$result->message], 422);
        }

        // 从 out_trade_no 反查订单(mock 里 out_trade_no 末段是 order id;真实网关用订单号映射)
        $order = $this->resolveOrder($request, $result->outTradeNo);

        if (! $order) {
            return response()->json(['message' => '订单未找到。'], 404);
        }

        // 幂等:已支付则直接返回
        if (in_array($order->status, ['processing', 'completed'], true)) {
            return response()->json(['message' => '已处理(幂等)。', 'order_id' => $order->id]);
        }

        $order->status = 'processing';
        $order->save();

        // 触发 v1 既有授权发放(幂等:order_item_id 唯一键兜底)
        app(SkillDelivery::class)->afterOrderCreated($order);

        return response()->json([
            'message'  => '支付成功,授权已发放。',
            'order_id' => $order->id,
        ]);
    }

    /**
     * 从回调负载反查订单。
     *
     * ⚠ 此前这里是 `preg_match('/(\d+)$/', $outTradeNo)` —— "取结尾的数字当订单 id"。
     *   两个毛病，都很严重：
     *   1. **它根本不对**：mock 的商户订单号是 `MOCK{YmdHis}{id}`（无分隔符），
     *      结尾那串数字是"时间戳+id"连在一起，取出来是个天文数字 → 永远 404。
     *      也就是说这条 mock 回调链从未真正跑通过（补第一条支付测试时才暴露）。
     *   2. **它很危险**：换成任何结尾带数字的真实商户订单号，都能被编出一个
     *      看起来合法的订单 id 出来 —— 而本接口不挂鉴权。
     *
     *   现在改为**按网关自己声明的格式严格解析**：解析不出就老实返回 null（404），
     *   而不是猜。真实网关接入时在这里加"商户订单号 → 本平台订单"的映射
     *   （微信侧应落 `out_trade_no` 到订单，而不是靠字符串反推）。
     */
    protected function resolveOrder(Request $request, string $outTradeNo)
    {
        if ($request->filled('order_id')) {
            return OrderProxy::modelClass()::find($request->input('order_id'));
        }

        if ($orderId = MockGateway::orderIdFrom($outTradeNo)) {
            return OrderProxy::modelClass()::find($orderId);
        }

        return null;
    }
}
