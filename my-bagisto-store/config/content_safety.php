<?php

/**
 * 内容安全配置 —— v3.4 步骤 6（计划 §4.8 L0 内容关键字 / §4.21）。
 *
 * 与 skill_delivery.php / skill_importers.php 同一套"配置注册表"写法：
 * 换实现 = 加一行 + 改 default，业务代码零改动。
 *
 * ⚠ 本层的定位（诚实说明）：
 *   - 现在跑的是 **本地词表**（离线、零依赖、立刻可用）；
 *   - 它**不是**微信 msgSecCheck 的替代品 —— 词表覆盖不了变体、谐音、图片、音频；
 *   - `wechat` 驱动是**留位**：接它需要小程序 appid + access_token（外部依赖）。
 *     在它接通之前，别对外声称"内容安全已完备"。
 */
return [
    /*
    |--------------------------------------------------------------------------
    | 当前驱动
    |--------------------------------------------------------------------------
    | local  —— 本地词表（可用）
    | wechat —— 微信 msgSecCheck（留位，未实现；配了会报"驱动未注册"）
    */
    'driver' => env('CONTENT_SAFETY_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | 驱动注册表
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'local' => [
            'class' => Webkul\Shop\Services\ContentSafety\LocalKeywordScanner::class,
            'words_path' => env(
                'CONTENT_SAFETY_WORDS_PATH',
                __DIR__.'/../packages/Webkul/Shop/src/Resources/data/forbidden-words.txt'
            ),
        ],

        // 留位：接微信内容安全（需 appid + access_token，且是外部网络依赖）
        // 'wechat' => [
        //     'class' => Webkul\Shop\Services\ContentSafety\WechatSecCheckScanner::class,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 词表缺失时怎么办
    |--------------------------------------------------------------------------
    | `fail` —— **拒绝该次提审**（默认）。理由码 CONTENT_UNSCREENED。
    |
    | 为什么默认 fail-closed：内容安全是合规闸门，"没查"和"查了没问题"是两回事。
    | 放行会把未经筛查的技能推上架 —— 那是真实世界的合规风险，比"上传被拦"严重得多。
    | 词表**随仓库附带**（见 words_path 默认值），所以"文件不存在"属于配置事故，
    | 而不是正常状态；失败信息会直接告诉运维该配哪。
    |
    | 若某段时间确实需要先放行（例如迁移期），显式改成 `pass` ——
    | 但要知道那等于关掉内容安全。
    */
    'on_missing_words' => env('CONTENT_SAFETY_ON_MISSING', 'fail'),
];
