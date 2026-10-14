<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 小召·召唤引擎 v2(AI 版)开关
    |--------------------------------------------------------------------------
    |
    | false(默认) → POST /api/summon 走原有 rule-v1 确定性匹配,行为与从前完全一致
    | true        → POST /api/summon 转交 SummonAiController(Coze Agent 驱动)
    |
    | 切换只改 .env 的 SUMMON_AI_ENABLED,不需要改代码。
    | 出问题把开关关掉即可立刻回到 rule-v1。
    |
    | 另:即便开关打开,SummonAiController 内部在 Coze 不可用时也会**自动降级**
    | 回 rule-v1(响应带 engine=rule-v1-fallback),所以接口不会因 AI 故障而失败。
    |
    */
    'ai_enabled' => env('SUMMON_AI_ENABLED', false),
];
