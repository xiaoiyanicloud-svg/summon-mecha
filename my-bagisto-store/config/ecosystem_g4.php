<?php

/*
|--------------------------------------------------------------------------
| G4 分发与运营 · 能力开关（2026-10-20）
|--------------------------------------------------------------------------
|
| 本文件是 G4-01 ~ G4-09 的**唯一开关登记表**。所有默认值为 false：
| 代码、测试、迁移先落地，再按 G4-runbook.md 的阶段性顺序逐项打开。
|
| 与既有开关的叠加关系（不会互相替代）：
|   · 公开读取仍先看 `ecosystem_public_read.emergency_disabled`（kill switch）；
|   · 域开关 `ecosystem.events.public_enabled` / `ecosystem.content.public_enabled`
|     仍决定对象是否公开；本文件只决定“要不要提供分发入口”；
|   · 报名写闸仍由 `ecosystem.events.registration_enabled` +
|     `registration_write_verified` 决定；G4 的任何开关都不能绕过它们。
|
| ⚠ 收藏是 G4 唯一新增的用户写入能力，默认关闭；打开前必须完成
|   G4-08 的权限/隔离/幂等演练。
|
*/
return [
    /*
    |--------------------------------------------------------------------------
    | G4-01 搜索与筛选
    |--------------------------------------------------------------------------
    |
    | 关闭时 `q` / `registration_state` 参数返回 400（明确“未启用”），
    | 不静默忽略 —— 静默忽略会让调用方以为搜索生效。
    |
    */
    'search' => [
        'enabled' => (bool) env('ECOSYSTEM_SEARCH_ENABLED', false),

        // 关键词 trim 后的最大字符数（mb_strlen）。短于 1 视为未传。
        'keyword_max_length' => (int) env('ECOSYSTEM_SEARCH_KEYWORD_MAX_LENGTH', 50),

        // 仅 page 模式生效；游标模式不受深偏移上限影响。
        'max_page' => (int) env('ECOSYSTEM_SEARCH_MAX_PAGE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | G4-02 收藏
    |--------------------------------------------------------------------------
    */
    'favorites' => [
        'enabled' => (bool) env('ECOSYSTEM_FAVORITES_ENABLED', false),

        // 单用户收藏上限；防止把收藏表当图床/爬虫缓存。
        'max_per_customer' => 500,

        'default_per_page' => 20,
        'max_per_page' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | G4-03 关联
    |--------------------------------------------------------------------------
    */
    'relations' => [
        'enabled' => (bool) env('ECOSYSTEM_RELATIONS_ENABLED', false),

        // 单来源最多解析 12 项；超出部分截断并记日志，不无限膨胀详情响应。
        'max_per_source' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | G4-04 / G4-05 SSR、canonical、OG、sitemap
    |--------------------------------------------------------------------------
    |
    | `base_url` 必须配置成对外的正式站点根（含 scheme，不带尾斜杠）。
    | 留空时回退到当前请求根，仅适合本机验证；生产必须显式配置。
    |
    */
    'ssr' => [
        'enabled' => (bool) env('ECOSYSTEM_SSR_ENABLED', false),

        'base_url' => (string) env('ECOSYSTEM_PUBLIC_SITE_URL', ''),

        'site_name' => (string) env('ECOSYSTEM_SITE_NAME', '召唤机甲'),

        'list_per_page' => 20,

        // OG/JSON-LD 描述截断长度（字符）。
        'description_max_length' => 200,
    ],

    'sitemap' => [
        'enabled' => (bool) env('ECOSYSTEM_SITEMAP_ENABLED', false),

        // 单次输出上限。超过时记 error 并截断，不静默假装完整。
        'max_urls' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | G4-06 指标
    |--------------------------------------------------------------------------
    |
    | 指标页读取开关与访问记录开关分开：
    |   · 关闭 metrics.enabled：运营台/指标页明确不可用；
    |   · 关闭 content_views_enabled：访问量指标返回 available=false，
    |     不返回 0 冒充“没人看”。
    |
    */
    'metrics' => [
        'enabled' => (bool) env('ECOSYSTEM_METRICS_ENABLED', false),

        'content_views_enabled' => (bool) env('ECOSYSTEM_CONTENT_VIEWS_ENABLED', false),

        // 同一访客对同一对象的去重窗口（秒）。
        'view_dedupe_seconds' => (int) env('ECOSYSTEM_METRICS_VIEW_DEDUPE_SECONDS', 3600),

        // 单次统计区间上限（天）。
        'max_range_days' => (int) env('ECOSYSTEM_METRICS_MAX_RANGE_DAYS', 366),
    ],

    /*
    |--------------------------------------------------------------------------
    | G4-07 运营工作台
    |--------------------------------------------------------------------------
    */
    'ops' => [
        'enabled' => (bool) env('ECOSYSTEM_OPS_WORKBENCH_ENABLED', false),

        'closing_soon_hours' => 48,

        'source_reverify_days' => 180,

        'retraction_window_days' => 30,

        'max_items_per_section' => 50,

        // 每个分区的负责人（展示在页面上，不进入公开响应）。
        'responsible' => [
            'review' => (string) env('ECOSYSTEM_OPS_RESPONSIBLE_REVIEW', '内容 / 活动运营'),
            'registration' => (string) env('ECOSYSTEM_OPS_RESPONSIBLE_REGISTRATION', '报名运营'),
            'notification' => (string) env('ECOSYSTEM_OPS_RESPONSIBLE_NOTIFICATION', '平台运维'),
            'source' => (string) env('ECOSYSTEM_OPS_RESPONSIBLE_SOURCE', '内容合规'),
            'disposition' => (string) env('ECOSYSTEM_OPS_RESPONSIBLE_DISPOSITION', '内容运营负责人'),
        ],
    ],
];
