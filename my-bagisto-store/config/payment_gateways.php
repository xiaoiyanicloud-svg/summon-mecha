<?php

/**
 * 支付网关注册表(v3.4 步骤 4)。
 *
 * ⚠ default 现在指向 mock(资质未就绪)。微信资质到位后,把 default 改成 wechat
 *   —— 但**不是"改一行就活"**:`WechatGateway` 的三个方法目前都是抛异常占位,
 *   切过去之后每一次 createPayment 都会 503。真正的开关是下面那个 `production_safe`
 *   标记,它由 `PaymentManager` 在执行时强制校验(见该文件)。
 *
 * ⚠ 本文件此前声称"生产环境禁止用 mock(已按 env 兜底)" —— 那是**假声明**:
 *   本文件与 PaymentManager 都没有任何环境判断,生产上不设 PAYMENT_GATEWAY
 *   时 mock 就是默认网关,而 mock 的"支付成功"由回调参数自报,无验签。
 *   现改为登记式声明 + 执行期拒绝:凡是没显式声明 `production_safe => true` 的,
 *   在生产环境**一律拒绝解析**(允许列表,不是拒绝列表)。
 */
return [
    'default' => env('PAYMENT_GATEWAY', 'mock'),

    'gateways' => [
        'mock' => [
            'class' => Webkul\Shop\Services\Payment\MockGateway::class,

            // 不做真实扣款、回调无验签(mock_paid=1 即成功)。只能本地/联调。
            'production_safe' => false,
        ],

        'wechat' => [
            'class' => Webkul\Shop\Services\Payment\WechatGateway::class,

            // ⚠ 目前也是 false,而且**这就是事实**:三个方法全是抛异常的占位
            //   (createPayment / handleCallback / refund),没有一行真实对接。
            //   把它写成 true 会制造"微信支付已就绪"的假象。
            //   接入真实商户资质与签名验签后,再翻成 true。
            'production_safe' => false,
        ],
    ],
];
