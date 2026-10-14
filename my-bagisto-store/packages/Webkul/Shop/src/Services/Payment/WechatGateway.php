<?php

namespace Webkul\Shop\Services\Payment;

/**
 * 微信支付网关 —— 占位实现(v3.4 步骤 4)。
 *
 * ⚠ 待支付资质就绪后补齐(商户号/类目/收付通分账)。
 *   接口已按最终形态定义,补实现时业务代码零改动。
 *   收付通/二级商户分账是"第三方交易上线"的硬前置(二清合规,见计划 §4.17)。
 */
class WechatGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'wechat';
    }

    public function createPayment($order): array
    {
        throw new \RuntimeException('微信支付资质未就绪：商户号/类目审核完成后再启用 WechatGateway。');
    }

    public function handleCallback(array $payload): PaymentResult
    {
        throw new \RuntimeException('微信支付资质未就绪。');
    }

    public function refund($order, float $amount, string $outRefundNo): array
    {
        throw new \RuntimeException('微信支付资质未就绪。');
    }
}
