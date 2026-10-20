<?php

return [
    /*
    |--------------------------------------------------------------------------
    | G2 免费活动报名
    |--------------------------------------------------------------------------
    |
    | 所有开关默认安全关闭。生产开放必须同时满足：
    |   ecosystem.events.registration_enabled = true
    |   ecosystem.events.registration_write_verified = true
    |   目标 scope.status = open 且 policy_confirmed_at 非空
    |
    | 本文件只放报名域自己的参数；全局双重闸仍在 config/ecosystem.php。
    |
    */
    'enabled' => (bool) env('ECOSYSTEM_REGISTRATION_ENABLED', false),

    /*
    | B-06 取消/改期政策未签署前，活动生命周期写能力保持关闭。
    | 关闭时运营只能用 G1 的暂缓/撤稿停止展示；不得用本服务改 hosting_status。
    */
    'lifecycle' => [
        'enabled' => (bool) env('ECOSYSTEM_REGISTRATION_LIFECYCLE_ENABLED', false),
    ],

    /*
    | 联系人加密使用 Laravel APP_KEY（Crypt）。phone_hash 另用独立 HMAC 键：
    |   · 密钥不落库、不进日志；
    |   · 轮换前必须先 dry-run 评估历史 hash 的可读性；
    |   · 未配置时由 RegistrationContactVault 在运行时从 APP_KEY 派生，
    |     仅用于本机/测试；生产应显式配置。
    */
    'pii_key' => env('ECOSYSTEM_REGISTRATION_PII_KEY', ''),

    /*
    | 历史 APP_KEY（逗号分隔）。仅用于轮换期解密旧联系人密文；
    | 轮换完成后通过 `ecosystem:rotate-registration-contact` 重新加密，
    | 再从这里移除旧 key。不要长期保留。
    */
    'previous_app_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ECOSYSTEM_REGISTRATION_PREVIOUS_APP_KEYS', ''))
    ))),

    'public_no_prefix' => env('ECOSYSTEM_REGISTRATION_PUBLIC_NO_PREFIX', 'EV'),

    // 幂等：同一 key 的重试窗口。超过窗口后仍保留记录，但不再自动清理。
    'request_key_max_length' => 100,

    // 报名表只接受白名单字段；自定义字段也必须走同一校验。
    'form' => [
        'fields' => ['name', 'phone', 'city', 'note'],
        'required' => ['name', 'phone'],
        'max_lengths' => [
            'name'  => 50,
            'phone' => 20,
            'city'  => 100,
            'note'  => 500,
        ],
    ],

    // 审核到期扫描：submitted 超过 review_closes_at 后置 rejected（不释放不存在的席位）。
    'review_expiry' => [
        'enabled' => (bool) env('ECOSYSTEM_REGISTRATION_REVIEW_EXPIRY_ENABLED', true),
        'batch_size' => (int) env('ECOSYSTEM_REGISTRATION_REVIEW_EXPIRY_BATCH', 200),
    ],

    // 入场凭证：原始 token 只出现一次，库里只存 sha256。
    'checkin' => [
        'token_bytes' => 32,
        // 活动没有结束时间时的兜底有效期（秒），默认 30 天。
        'fallback_ttl_seconds' => (int) env('ECOSYSTEM_REGISTRATION_CHECKIN_FALLBACK_TTL', 2592000),
        'qr_prefix' => 'SUMMONMECHA-CHECKIN:',
    ],

    // 专用导出：私有盘 + 短期 token；通用 DataGrid 导出不在此列。
    'export' => [
        'disk' => env('ECOSYSTEM_REGISTRATION_EXPORT_DISK', 'local'),
        'ttl_seconds' => (int) env('ECOSYSTEM_REGISTRATION_EXPORT_TTL', 600),
        'max_rows' => (int) env('ECOSYSTEM_REGISTRATION_EXPORT_MAX_ROWS', 20000),
        'directory' => 'ecosystem/registration-exports',
    ],

    // 资料留存：B-05 未签署前只做 dry-run，不自动删除任何历史。
    'retention' => [
        'cleanup_enabled' => false,
        'attempt_retention_days' => (int) env('ECOSYSTEM_REGISTRATION_RETENTION_DAYS', 365),
    ],

    // 活动人员能力词表（与 EventStaffAssignment::CAPABILITIES 一致）。
    'staff_capabilities' => ['review', 'cancel', 'pii', 'export', 'checkin', 'settings'],

    // outbox 通知类型（G2-12）。只发站内信；未接短信/微信/支付。
    'notification' => [        'types' => [
            'registration.submitted',
            'registration.confirmed',
            'registration.rejected',
            'registration.canceled',
            'registration.reapplied',
            'registration.change_requested',
            'registration.change_acknowledged',
            'registration.change_expired',
            'registration.checked_in',
            'registration.event_canceled',
            'registration.reminder',
        ],
    ],
];
