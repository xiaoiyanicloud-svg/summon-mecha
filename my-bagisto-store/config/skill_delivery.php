<?php

/**
 * 技能交付通道注册表。
 *
 * 写法刻意对齐 Bagisto 自己的 config/product_types.php(key => class,由容器解析),
 * 这样"预留通道"用的是项目里已有的模式,而不是新发明的机制。
 *
 * ⚠ default 只在**生成授权时**读取一次,并把 key 落到 skill_entitlements.delivery_channel。
 *   不要在下发时重新推导 —— 否则将来改默认通道会追溯性地改变历史订单的行为。
 *
 * 加一个新通道 = 实现 DeliveryChannel 接口 + 在这里加一行,业务代码零改动。
 */
return [
    /*
    |--------------------------------------------------------------------------
    | 上传通道的内部密钥
    |--------------------------------------------------------------------------
    |
    | skill-admin 路由组用它做鉴权(请求头 X-Internal-Key)。
    | 未配置时该路由组整体返回 503 —— 宁可不可用,也不要无鉴权的写接口裸奔。
    | 放进 config 而不是直接 env():项目一旦执行 config:cache,env() 在配置外会失效。
    |
    */
    'upload_key' => env('SKILL_UPLOAD_KEY'),

    /*
    |--------------------------------------------------------------------------
    | 默认交付通道
    |--------------------------------------------------------------------------
    */
    'default' => env('SKILL_DELIVERY_CHANNEL', 'app'),

    /*
    |--------------------------------------------------------------------------
    | 单文件上传上限（KB）
    |--------------------------------------------------------------------------
    |
    | ⚠ 这个值**必须 ≤ PHP 的 upload_max_filesize**，否则它是一个假承诺：
    |   超限的文件在进入校验之前就被 PHP 丢弃（表现为"上传后什么都没发生"），
    |   而接口却宣称允许更大的包。本机实测 upload_max_filesize=20M / post_max_size=40M，
    |   故默认取 20480(20MB)。要支持更大的包，先改 php.ini / nginx / 对象存储，
    |   再同步调大这里 —— 顺序不能反。
    |
    | 两个通道（internal.key 与开发者门户）共用这一个值，不另立第二份。
    |
    */
    'max_asset_kb' => env('SKILL_MAX_ASSET_KB', 20480),

    /*
    |--------------------------------------------------------------------------
    | 技能包落哪个盘
    |--------------------------------------------------------------------------
    |
    | 默认 `private`（本地盘，storage/app/private）。切对象存储只改这一行 + 跑一次
    | `php artisan skill:move-assets`，**业务代码零改动**（所有读写都经 SkillAsset::disk()）。
    |
    | ⚠ 无论用哪个盘，都必须是**私读**的：技能包绝不能有公开 URL，
    |   下载一律走 SkillController 的鉴权流式接口。
    |
    */
    'asset_disk' => env('SKILL_ASSET_DISK', 'private'),

    /*
    |--------------------------------------------------------------------------
    | 通道实现
    |--------------------------------------------------------------------------
    */
    'channels' => [
        // P0:应用内交付 —— 零外部依赖,不推送任何东西到设备
        'app' => [
            'class' => Webkul\Shop\Services\SkillDelivery\AppChannel::class,
        ],

        // Agent 下发 —— **授权侧**实现:真正的下发由 deploy_task 承担,本通道只做
        //   "能不能走 / 有没有真的下发任务"的判断与结果回报,不创建任务、不改授权状态。
        // 试用授权(TrialService)写的就是这个 key,必须有注册,否则会被调用点记成交付失败。
        'agent' => [
            'class' => Webkul\Shop\Services\SkillDelivery\AgentChannel::class,
        ],

        // P1:设备带 license_key 主动来拉。是"拉"模型,不需要 broker、
        //     不要求设备有公网 IP,所以比推送容易落地。待设备端固件就绪后启用。
        // 'device_pull' => [
        //     'class' => Webkul\Shop\Services\SkillDelivery\DevicePullChannel::class,
        // ],

        // P3:MQTT/WebSocket 主动推。需要 broker + 在线状态,最贵,明确推迟。
        // 'device_push' => [
        //     'class' => Webkul\Shop\Services\SkillDelivery\DevicePushChannel::class,
        // ],
    ],
];
