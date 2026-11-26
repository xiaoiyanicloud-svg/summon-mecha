<?php

/**
 * 事件正文签名开关（WBS A1）。
 *
 * - `require_v3`：**持有可用设备证书**的设备必须为事件正文提供 agent-fact/v3 签名；
 *   共享密钥时代、无凭据的历史设备仍可走 v2（只读兼容窗口）。
 * - `v2_window_enabled`：关闭后，连无凭据设备也不接受未签名正文。
 * - 时间窗：reported_at 过旧或来自未来过远一律拒绝。
 *   ⚠ 离线 outbox 可能重投数小时/数天，故默认窗口放宽到 7 天；
 *     重放的主防线是 Inbox 的 event_id 幂等（同载荷 duplicate / 异载荷 409），
 *     不是这个时间窗。
 */
return [
    'require_v3' => (bool) env('AGENT_FACT_SIGNATURE_REQUIRED', true),
    'v2_window_enabled' => (bool) env('AGENT_FACT_SIGNATURE_V2_WINDOW', true),
    'max_age_seconds' => (int) env('AGENT_FACT_SIGNATURE_MAX_AGE_SECONDS', 604800),
    'max_clock_skew_seconds' => (int) env('AGENT_FACT_SIGNATURE_MAX_CLOCK_SKEW_SECONDS', 300),
];
