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

    /**
     * 平台密钥材料(签名密钥对 + 设备 CA)的存放位置 —— 任务卡 S-6。
     *
     * 两套密钥都靠「文件存在性」懒生成,此前硬编码在 `local` 盘的 `keys/` 下:
     *   · 签名密钥对:platform_private.pem / platform_public.pem
     *   · 设备 CA:   device_ca_private.pem / device_ca_public.pem(后者存的是证书)
     *
     * ⚠ 生产必须把 `AGENT_KEYS_DIR` 指到**受限的持久卷**(私有挂载,不随部署漂移),
     *   否则重新部署/换机即等于丢密钥 —— 库里没有任何可恢复的副本。
     * ⚠ 文件名不配置:它们是签名/证书协议的一部分,多一个可配置项就多一处
     *   "两个 env 打架"的失败面。要换位置只换「盘 + 目录」。
     * ⚠ 默认值刻意与旧硬编码逐字一致(disk=local,dir=keys)→ 现网行为零变化。
     * ⚠ env() 只允许出现在 config 文件里(config:cache 之后命令里读 env() 恒为 null)。
     */
    'keys' => [
        'disk' => env('AGENT_KEYS_DISK', 'local'),
        'dir'  => env('AGENT_KEYS_DIR', 'keys'),
    ],

    /**
     * 技能包限时下载 URL 的有效期(分钟)—— 任务卡 S-1。
     *
     * 为什么必须是**可配置**而不是写死:它是一对互相拉扯的约束的平衡点 ——
     *   · 太长:URL 一旦泄露(日志/代理/截图),别人有很久的时间窗反复拉包;
     *   · 太短:设备"取到指令"与"下载完成"之间会失手(离线挂起后上线、网络抖动重试)。
     * 这两个值的量级随部署环境(包体积、网络质量)变化,所以进配置。
     *
     * 默认 10 分钟:远大于一个技能包的下载耗时,又远小于一次 20 分钟的试用窗口 ——
     * 试用到期回收时,包地址早已失效,不会残留下载窗口。
     *
     * ⚠ 指令里的 URL 是在**设备取指令的那一刻**签发的(见 DeployTaskService::buildCommand),
     *   不是建任务时,否则离线挂起几小时后的下发会拿到一个过期地址。
     */
    'package_url_ttl_minutes' => (int) env('AGENT_PACKAGE_URL_TTL_MINUTES', 10),

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
