<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * Agent 网关管理器 —— v3.4 步骤 8。
 * 按配置解析当前启用网关(写法对齐 payment_gateways / skill_delivery)。
 */
class AgentGatewayManager
{
    public function gateway(?string $key = null): AgentGateway
    {
        $key ??= config('deploy_gateways.default', 'mock');

        $class = config('deploy_gateways.gateways.'.$key.'.class');

        if (! $class || ! class_exists($class)) {
            throw new \RuntimeException("Agent 网关 [{$key}] 未注册。");
        }

        return app($class);
    }
}
