<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 小召·召唤引擎 v2(AI 版) —— Coze 接入配置
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md「扩展优先走配置注册表」:Shop src 里唯一直接读 env() 的地方就是
    | SummonAiController —— 这违背了约定,而且有实伤:
    |
    | ⚠ 生产执行 `php artisan config:cache` 之后,配置被编译成一份静态数组,
    |   **config 文件之外的 env() 一律返回 null**。于是 AI 召唤整条链路会静默失效
    |   (base_url 退回 localhost、pat/bot_id 变空 → 直接走 rule-v1 降级),**不报任何错**。
    |   把三个键登记进本文件,控制器改读 config(),config:cache 后依然可用。
    |
    | 三键:
    |   base_url —— Coze 服务地址(自建版默认 http://localhost:8888)
    |   pat      —— 个人访问令牌;为空则视为未配置,接口降级回 rule-v1
    |   bot_id   —— 目标 Bot;为空同样降级
    |
    | 切换/改密钥只改 .env 的 COZE_*,不需要改代码。
    |
    */
    'base_url' => env('COZE_BASE_URL', 'http://localhost:8888'),

    'pat' => env('COZE_PAT', ''),

    'bot_id' => env('COZE_BOT_ID', ''),
];
