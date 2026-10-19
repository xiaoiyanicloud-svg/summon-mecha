<?php

/*
|--------------------------------------------------------------------------
| 生态内容注册表（ADR-0001 §4.6–§4.8 / G1-D06 / G1-05）
|--------------------------------------------------------------------------
|
| 这是 `content_posts` / `content_revisions` / `content_sources` 枚举与
| slug 规则的**单一事实源**。业务代码与模型守卫都按本表判断，
| 禁止在服务/模型里另写字面量。
|
| 边界：
|   · 内容类型、来源类型、权利依据、审核轴、发布轴、处置轴均与 CMS 静态页无关；
|   · slug 冲突策略已冻结（§3 G1-D06）：全局唯一、首次发布后不可变、撤稿后不复用；
|   · `source_type_rights_basis` 是提交/批准前的匹配表：source_type 只能配
|     表中列出的 rights_basis；
|   · `sanitizer_placeholder_version` 是 G1-05 的占位值，仅供历史数据 / 测试夹具；
|     G1-07 起 revision 写入真实净化版本，见 config/ecosystem_rich_text.php。
|
*/
return [
    // 内容类型（ADR §4.6）。
    'content_types' => [
        'news',
        'announcement',
        'case',
        'competition_report',
    ],

    // 来源类型（ADR G1-D06）。
    'source_types' => [
        'original',
        'authorized_repost',
        'link_summary',
    ],

    // 权利依据（ADR §4.8）。
    'rights_bases' => [
        'platform_original',
        'written_repost_permission',
        'official_link_only',
        'public_document_summary',
    ],

    // revision 审核轴。
    'review_states' => [
        'draft',
        'in_review',
        'approved',
        'rejected',
    ],

    // 处于这些审核态时，revision 内容列不可更新。
    'immutable_review_states' => [
        'in_review',
        'approved',
        'rejected',
    ],

    // 发布轴（与活动同词表）。
    'publish_statuses' => [
        'draft',
        'in_review',
        'approved',
        'scheduled',
        'published',
    ],

    // 处置轴；retracted 是终态，旧 slug / 旧 ID / 旧 revision 不复活。
    'dispositions' => [
        'normal',
        'withheld',
        'retracted',
    ],

    // slug 冻结规则（ADR §3 G1-D06）。
    'slug' => [
        'min_length' => 3,
        'max_length' => 120,
        // 只允许小写 ASCII、数字和连字符；不做拼音转写、不自动小写化。
        'pattern' => '/^[a-z0-9-]+$/',
        // 等于保留词或以“保留词-”开头均视为占用。
        'reserved_prefixes' => [
            'api',
            'admin',
            'cms',
            'content',
            'media',
        ],
    ],

    // source_type => 允许的 rights_basis。
    'source_type_rights_basis' => [
        'original' => ['platform_original'],
        'authorized_repost' => ['written_repost_permission'],
        // 链接摘要要么只做外链，要么摘录公开文件；两者都不允许复制全文。
        'link_summary' => ['official_link_only', 'public_document_summary'],
    ],

    // G1-05 尚未接入富文本净化；revision 必须显式记录这一事实（G1-07 替换）。
    'sanitizer_placeholder_version' => 'unsanitized-g1-05',
];
