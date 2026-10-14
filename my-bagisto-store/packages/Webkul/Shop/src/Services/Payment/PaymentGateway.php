<?php

namespace Webkul\Shop\Services\Payment;

/**
 * 支付网关接口(v3.4 步骤 4)。
 *
 * 抽象出这一层的目的:支付资质(微信商户/类目)还没就绪,但"下单→支付→发授权"
 * 这条链路要能现在就开发和联调 —— 用 MockGateway 跑通,资质到位后只需补一个
 * WechatGateway 实现,业务代码零改动。
 */
interface PaymentGateway
{
    /**
     * 网关标识。
     */
    public function key(): string;

    /**
     * 为订单创建支付单,返回前端发起支付所需的参数。
     *
     * @return array{out_trade_no: string, params: array, mock: bool}
     */
    public function createPayment($order): array;

    /**
     * 处理支付回调,返回支付结果(是否成功、金额、第三方流水号)。
     */
    public function handleCallback(array $payload): PaymentResult;

    /**
     * 发起退款。
     *
     * @return array{ok: bool, refund_no: string|null, message: string}
     */
    public function refund($order, float $amount, string $outRefundNo): array;
}
