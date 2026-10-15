<?php

/**
 * Agent 网关注册表 —— v3.4 步骤 8。
 *
 * default 现为 mock(真传输未就绪)。MQTT/EMQX 或厂商云实现到位后切 default 即可。
 */
return [
    // 默认用 HTTP 轮询(可立刻上线,零 infra);EMQX/Go 就绪后切 'mqtt'
    'default' => env('AGENT_GATEWAY', 'poll'),

    // Agent 接入鉴权模式(§4.22 F1)—— 三档渐进切换:
    //   shared —— 只认共享密钥(旧行为,回退用)
    //   auto   —— 有 per-device 凭据就认凭据,否则退回共享密钥(**当前默认,过渡态**)
    //   device —— 只认 per-device 凭据,共享密钥被拒(共享密钥「明确弃用」的目标态)
    'agent_auth_mode' => env('AGENT_AUTH_MODE', 'auto'),

    // per-device 凭据(证书/secret)的有效期(天)
    'device_credential' => [
        'ttl_days' => (int) env('AGENT_DEVICE_CRED_TTL_DAYS', 365),
    ],

    // ⚠ 过渡期共享密钥。目标是弃用:
    //   agent_auth_mode=device 后本值不再被接受;届时可整体删除本键。
    //   per-device 凭据由 DeviceCredentialService 在 enroll 时签发(每设备一份,可单独吊销)。
    'agent_key' => env('AGENT_KEY'),

    // Agent 自更新工件(§4.22 F1 / B-13)—— 平台当前发布的最新 Agent 版本。
    // 未配置(version/url/hash 任一为空)时 /api/agent/self-update 恒回 NOT_CONFIGURED。
    // ⚠ 服务端**只提供更高版本**(防降级);下载/替换/失败回滚是设备侧行为,待真机。
    'agent_latest' => [
        'version' => env('AGENT_LATEST_VERSION'),
        'url'     => env('AGENT_LATEST_URL'),
        'hash'    => env('AGENT_LATEST_HASH'),
    ],

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
