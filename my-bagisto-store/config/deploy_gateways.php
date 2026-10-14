<?php

/**
 * Agent 网关注册表 —— v3.4 步骤 8。
 *
 * default 现为 mock(真传输未就绪)。MQTT/EMQX 或厂商云实现到位后切 default 即可。
 */
return [
    // 默认用 HTTP 轮询(可立刻上线,零 infra);EMQX/Go 就绪后切 'mqtt'
    'default' => env('AGENT_GATEWAY', 'poll'),

    // Agent 接入接口的共享密钥(未配置则 agent 路由组整体 503)
    'agent_key' => env('AGENT_KEY'),

    'gateways' => [
        // HTTP 轮询:设备主动拉指令(真传输,当前默认)
        'poll' => [
            'class' => Webkul\Shop\Services\Deploy\HttpPollAgentGateway::class,
        ],

        // 模拟网关:开发/单测(把指令存缓存供模拟器拉取)
        'mock' => [
            'class' => Webkul\Shop\Services\Deploy\MockAgentGateway::class,
        ],

        // MQTT/EMQX 网关:P1(需 EMQX + Go 服务)
        // 'mqtt' => [
        //     'class' => Webkul\Shop\Services\Deploy\MqttAgentGateway::class,
        // ],
    ],
];
