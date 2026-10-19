<?php

/*
|--------------------------------------------------------------------------
| 生态富文本净化注册表（ADR-0001 §3 G1-D06 / §4.3 / §4.7 / G1-07）
|--------------------------------------------------------------------------
|
| 这是活动正文、资讯正文与规则正文的**单一净化口径**：
|   · `allowed_tags` 与 ADR G1-D06 的允许列表逐项一致，业务代码与测试
|     都不得另写字面量；
|   · 只允许 `http` / `https` 链接；`javascript:` / `data:` / `file:`
|     等协议由 HTMLPurifier 与输出后处理双重拒绝；
|   · `sanitizer_version` 写入 revision 的 `sanitizer_version` 列，
|     净化规则或字段口径变化时必须递增，否则新旧版本不可比；
|   · `max_input_chars` 是入库前的硬上限，超长输入直接拒绝，不做截断
|     （截断可能把半个标签写进库）。
|
| `media_placeholder_host` 只用于净化阶段的临时 `src`：合法图片先写成
| `https://media.invalid/{id}`，让 HTMLPurifier 在
| `URI.DisableExternalResources=true` 下仍保留 `<img>`；净化后该 `src`
| 会被移除，最终只保留 `data-media-id`。该域名不得解析为真实资源。
|
*/
return [
    // ADR G1-D06 允许列表（顺序与 ADR 一致）。
    'allowed_tags' => [
        'p',
        'br',
        'strong',
        'em',
        'u',
        's',
        'blockquote',
        'ul',
        'ol',
        'li',
        'a',
        'img',
        'figure',
        'figcaption',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
        'code',
        'pre',
        'hr',
    ],

    // 只允许 http / https（ADR G1-D06）。
    'allowed_schemes' => [
        'http',
        'https',
    ],

    // 净化规则版本，写入 revision.sanitizer_version。
    'sanitizer_version' => 'g1-07-v1',

    // 单次净化输入字符上限；超过即拒绝（不截断）。
    'max_input_chars' => 200000,

    // 净化阶段合法图片的临时 src 主机；不对应任何真实资源。
    'media_placeholder_host' => 'media.invalid',
];
