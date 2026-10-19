<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 赛事活动
    |--------------------------------------------------------------------------
    |
    | G0 安全闸：旧站内报名接口先默认关闭。G1 发布真实活动、G2 完成身份/
    | 名额/并发/审计验收后，才按活动逐项开放，不能直接把这里改成 true 当上线。
    |
    */
    'events' => [
        // G1 可信发布完成前保持 false:公开读取返回空列表 / 404,且完全不查库。
        // 打开只代表"允许读取已发布记录",不代表允许报名。
        'public_enabled' => (bool) env('ECOSYSTEM_EVENT_PUBLIC_ENABLED', false),

        // 双重闸:两个都为 true 才允许站内报名写库。
        // registration_enabled = 产品开关;registration_write_verified = G2 身份/名额/
        // 并发/审计验收完成。任何一项为 false,旧 POST 入口都必须 403 停写。
        'registration_enabled' => (bool) env('ECOSYSTEM_EVENT_REGISTRATION_ENABLED', false),
        'registration_write_verified' => (bool) env('ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED', false),

        'registration_disabled_reason' => env(
            'ECOSYSTEM_EVENT_REGISTRATION_DISABLED_REASON',
            '站内报名正在升级，暂未开放。'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | 动态资讯
    |--------------------------------------------------------------------------
    |
    | G1 独立内容发布域尚未接入。保留显式开关，避免前端在内容域未完成时
    | 把 CMS 静态页或样例内容当作资讯展示。
    |
    */
    'content' => [
        'public_enabled' => (bool) env('ECOSYSTEM_CONTENT_PUBLIC_ENABLED', false),
    ],
];
