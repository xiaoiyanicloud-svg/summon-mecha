<?php

/*
|--------------------------------------------------------------------------
| 生态媒体注册表（ADR-0001 §4.9 / §4.10 / G1-D06 / G1-06）
|--------------------------------------------------------------------------
|
| 这是 `ecosystem_media` / `ecosystem_media_refs` 的枚举、存储与大小限制的
| **单一事实源**。业务代码（MediaService / MediaAccessService）与模型守卫
| 都按本表判断，禁止在服务里另写字面量。
|
| 边界：
|   · 媒体文件一律落**私有盘**，没有公开 URL；公开读取必须经过
|     MediaAccessService 的门禁，由 controller 流式输出；
|   · `allowed_mimes` 只接受真实解码后的 MIME；客户端声明的 Content-Type
|     和扩展名都不算数；
|   · 文件 / sha256 不可原位替换 —— 换文件必须新建媒体并重新审核；
|   · `max_refs_per_revision` 由 MediaRefService 在整组替换时强制。
|
*/
return [
    // 私有存储盘；不允许指向带 url / public visibility 的盘。
    'disk' => env('ECOSYSTEM_MEDIA_DISK', 'local'),

    // 只允许这四类真实 MIME（ADR §4.9）。
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

    // MIME => 允许的扩展名（小写，不含点）。第一项是落盘时使用的规范扩展名。
    'extensions' => [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'application/pdf' => ['pdf'],
    ],

    // MIME => 类别，用于选择大小限制与校验方式。
    'kinds' => [
        'image/jpeg'      => 'image',
        'image/png'       => 'image',
        'image/webp'      => 'image',
        'application/pdf' => 'document',
    ],

    // 图片 5MB；文档 10MB（按字节）。
    'images' => [
        'max_bytes' => 5 * 1024 * 1024,
    ],
    'docs' => [
        'max_bytes' => 10 * 1024 * 1024,
    ],

    // 图片单边最大像素；防止解码炸弹。
    'max_dimension' => 8000,

    // 一个 revision 最多引用的媒体数（MediaRefService::replaceForRevision 强制）。
    'max_refs_per_revision' => 20,

    // 引用角色（ADR §4.10）。
    'roles' => [
        'cover',
        'inline',
        'attachment',
        'rule_document',
    ],

    // 媒体自身审核状态。
    'statuses' => [
        'pending',
        'approved',
        'rejected',
        'quarantined',
    ],

    // 引用审核状态。
    'review_statuses' => [
        'pending',
        'approved',
        'rejected',
    ],

    // 引用归属对象；owner_type 只有这两个值，**不做多态外键**。
    'owner_types' => [
        'event_revision',
        'content_revision',
    ],
];
