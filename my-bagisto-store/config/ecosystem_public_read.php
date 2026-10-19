<?php

/*
|--------------------------------------------------------------------------
| 生态公开读取 kill switch（ADR-0001 G1-D08 / 规划 §5.4 / G1-09）
|--------------------------------------------------------------------------
|
| 这是**独立于业务数据库**的紧急开关：`PublicReadGate::assertAvailable()`
| 只读本配置，不查任何业务表。命中时公开 API / 公开媒体统一 503
| `SERVICE_UNAVAILABLE`，**不得**伪装成空列表或 404 —— 那会让运营误以为
| “没有数据”，而真实情况是“平台主动停读”。
|
| 与域开关的分工：
|   · `ecosystem.events.public_enabled` / `ecosystem.content.public_enabled`
|     表示某个域是否允许公开读取（G1 验收前保持 false）；
|   · 本开关是跨域的“全停”闸，优先于域开关检查，也优先于任何 DB 查询。
|
| ⚠ 变更本配置后必须走部署/配置发布流程（`config:cache` 后再 reload）；
|   只改进程内配置不会影响其他 worker。
|
| 默认 false：G1 不打开公开读取，但 kill switch 本身必须随时可用。
*/
return [
    'emergency_disabled' => (bool) env('ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED', false),

    // 只用于日志/运维识别，不进入公开响应体。
    'reason' => env('ECOSYSTEM_PUBLIC_READ_DISABLED_REASON', 'public_read_emergency_disabled'),
];
