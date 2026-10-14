<?php

namespace Webkul\Shop\Services\Payment;

/**
 * 模拟支付网关 —— 资质未就绪时的开发/联调替身。
 *
 * ⚠ 只在非生产环境可用。拦截点在 `PaymentManager::assertUsableHere()`
 *   (注册表里本网关声明为 `production_safe => false`,生产环境解析即抛异常)。
 *
 * ⚠ **本网关完全没有防伪**:`handleCallback` 只看回调参数 `mock_paid=1` 就判成功,
 *   无验签、无金额核对。所以它的价值仅限于"让下单 → 支付 → 发授权这条链路可测",
 *   不能作为任何"支付已打通"的证据 —— 真实收款能力的唯一凭据是真网关的验签回调。
 */
class MockGateway implements PaymentGateway
{
    /**
     * 商户订单号的格式，**在这一处定义**。
     *
     * ⚠ 格式里的分隔符不是装饰：此前是 `MOCK{YmdHis}{id}`（时间戳与 id 直接相连），
     *   回调侧用 `/(\d+)$/` 抽 id 时会把时间戳一起吞掉 —— 结果**永远反查不到订单**，
     *   整条 mock 回调链其实是 404。这个 bug 是补第一条支付测试时才暴露出来的
     *   （此前全仓 0 支付测试，"mock 全链路已通"没有证据支撑）。
     */
    private const PREFIX = 'MOCK';

    public function key(): string
    {
        return 'mock';
    }

    /**
     * 由订单号生成商户订单号。格式：`MOCK-{YmdHis}-{orderId}`。
     */
    public static function makeOutTradeNo(\DateTimeInterface $at, int $orderId): string
    {
        return self::PREFIX.'-'.$at->format('YmdHis').'-'.$orderId;
    }

    /**
     * 反向解析 —— 只认本网关自己生成的格式，解析不出就返回 null。
     *
     * ⚠ 刻意用**严格全匹配**而不是"取结尾数字"：前者解析不出会老实返回 null，
     *   后者对任何结尾带数字的串都能编出一个订单 id 来。
     */
    public static function orderIdFrom(string $outTradeNo): ?int
    {
        if (preg_match('/^'.self::PREFIX.'-\d{14}-(\d+)$/', $outTradeNo, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    public function createPayment($order): array
    {
        $outTradeNo = self::makeOutTradeNo(now(), (int) $order->id);

        return [
            'out_trade_no' => $outTradeNo,
            'params'       => [
                'mock'         => true,
                'out_trade_no' => $outTradeNo,
                'amount'       => $order->grand_total,
                // 联调时直接调 /api/callback/payment 即可完成"支付"
                'callback_hint' => '/api/callback/payment?out_trade_no='.$outTradeNo.'&mock_paid=1',
            ],
            'mock'         => true,
        ];
    }

    public function handleCallback(array $payload): PaymentResult
    {
        $outTradeNo = $payload['out_trade_no'] ?? '';

        if (! $outTradeNo) {
            return PaymentResult::failed('', '缺少 out_trade_no', $payload);
        }

        // 模拟网关:只要带 mock_paid=1 就算支付成功
        if (($payload['mock_paid'] ?? null) == 1) {
            return PaymentResult::paid($outTradeNo, (float) ($payload['amount'] ?? 0), 'MOCKTXN'.time(), $payload);
        }

        return PaymentResult::failed($outTradeNo, '未标记支付成功', $payload);
    }

    public function refund($order, float $amount, string $outRefundNo): array
    {
        return [
            'ok'        => true,
            'refund_no' => 'MOCKREFUND'.$outRefundNo,
            'message'   => '模拟退款成功',
        ];
    }
}
