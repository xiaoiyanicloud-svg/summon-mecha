<?php

namespace Webkul\Shop\Services\Payment;

/**
 * 支付网关管理器 —— 按配置解析当前启用网关(v3.4 步骤 4)。
 *
 * 写法对齐项目既有套路(config 注册表 + 容器解析,见 config/skill_delivery.php)。
 */
class PaymentManager
{
    /**
     * 取当前启用的网关。
     *
     * ⚠ 生产环境会拒绝解析"未声明 production_safe 的网关"。
     *   这条守卫是**执行期**的,不是注释里的承诺 —— 因为支付网关搞错方向的后果
     *   是"用户以为付了钱、平台没收到钱"(mock 的支付成功由请求参数自报),
     *   属于宁可起不来也不能静默走错的那一类。
     */
    public function gateway(?string $key = null): PaymentGateway
    {
        $key ??= config('payment_gateways.default', 'mock');

        $class = config('payment_gateways.gateways.'.$key.'.class');

        if (! $class || ! class_exists($class)) {
            throw new \RuntimeException("支付网关 [{$key}] 未注册。");
        }

        $this->assertUsableHere($key);

        return app($class);
    }

    /**
     * 当前环境是否允许用这个网关。
     *
     * 判据是**允许列表**:注册表里没写 `production_safe => true` 的,生产环境一律拒绝。
     * 反过来(默认允许、逐个拉黑)会漏 —— 将来新增一个沙箱/测试网关时,
     * 忘了拉黑就自动在生产生效了。
     */
    private function assertUsableHere(string $key): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (config('payment_gateways.gateways.'.$key.'.production_safe') === true) {
            return;
        }

        throw new \RuntimeException(
            "支付网关 [{$key}] 不得在生产环境使用:它不收真实款项。".
            '请把 PAYMENT_GATEWAY 指向已完成资质接入、带验签的正式网关。'
        );
    }
}
