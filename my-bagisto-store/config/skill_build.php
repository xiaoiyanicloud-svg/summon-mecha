<?php

/**
 * 构建命令运行时配置（B1）。
 *
 * 所有新开关默认关闭：异步构建默认关闭，保持既有同步 API 行为；
 * 需要 outbox worker 时显式打开 `SKILL_BUILD_ASYNC_ENABLED=true`。
 */
return [
    'async_enabled' => (bool) env('SKILL_BUILD_ASYNC_ENABLED', false),

    'compiler_version' => (string) env('SKILL_BUILD_COMPILER_VERSION', 'portable-v1'),

    'worker_batch' => (int) env('SKILL_BUILD_WORKER_BATCH', 10),

    /*
    |--------------------------------------------------------------------------
    | 源包安全预算（B4）
    |--------------------------------------------------------------------------
    |
    | ⚠ 与上面的 `async_enabled` 不同，**这些不是功能开关，默认值就是生效值**。
    |   把安全上限"默认关闭"等于默认不设防 —— 源包是外部不可信输入，
    |   预算只能被**显式放大**（确有需要时改 env），不能靠开开关才生效。
    |
    | 读取实现见 `Services/SkillCompile/Portable/SourcePackageReader`。
    |
    */
    'source_package' => [
        // 压缩包本身的体积上限（打开之前就挡，避免先解压再说）。
        'max_archive_bytes' => (int) env('SKILL_SOURCE_MAX_ARCHIVE_BYTES', 67108864), // 64 MiB

        // 条目数上限（含目录条目）。
        'max_entries' => (int) env('SKILL_SOURCE_MAX_ENTRIES', 256),

        // 单条目解压上限（按**实际读取字节**计，不信 ZIP 里的声明大小）。
        'max_entry_bytes' => (int) env('SKILL_SOURCE_MAX_ENTRY_BYTES', 16777216), // 16 MiB

        // 全包解压总上限。
        'max_total_uncompressed_bytes' => (int) env('SKILL_SOURCE_MAX_TOTAL_BYTES', 67108864), // 64 MiB

        // 压缩比上限（防炸弹）。只对解压后超过阈值的条目生效 ——
        // 静音 WAV 这类合法素材的压缩比天然极高，一刀切会误杀。
        'max_compression_ratio' => (int) env('SKILL_SOURCE_MAX_RATIO', 100),
        'ratio_check_threshold_bytes' => (int) env('SKILL_SOURCE_RATIO_THRESHOLD_BYTES', 1048576), // 1 MiB
    ],

    /*
    |--------------------------------------------------------------------------
    | 动作类构建登记（B6）
    |--------------------------------------------------------------------------
    |
    | 动作重定向算法在平台外（独立工具/服务），平台只登记"该技能需要为哪些机型重定向"。
    | 但**登记也必须 fail-closed**：格式不在白名单、fps 离谱、文件路径可疑，
    | 一律 failed，不许带着脏参数进入待办队列（下游工具会照着脏参数干活）。
    |
    | 格式白名单与 `SkillPackageBuilder::SUPPORTED_MOTION_FORMATS` 保持同一套值。
    |
    */
    'motion' => [
        'allowed_formats' => ['bvh', 'npz', 'csv', 'fbx', 'npy'],
        'min_fps'         => (int) env('SKILL_MOTION_MIN_FPS', 1),
        'max_fps'         => (int) env('SKILL_MOTION_MAX_FPS', 240),
    ],
];
