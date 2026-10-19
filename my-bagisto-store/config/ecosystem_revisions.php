<?php

/*
|--------------------------------------------------------------------------
| 生态修订注册表（ADR-0001 §4.3 / §4.5 / G1-04）
|--------------------------------------------------------------------------
|
| 这是 `event_revisions.review_state`、`event_rule_versions.status` 等枚举的
| **单一事实源**。业务代码与模型守卫都按本表判断，禁止在服务/模型里另写字面量。
|
| immutable_* 含义：
|   · revision 一旦进入 in_review / approved / rejected，内容列即冻结；
|     被拒后不重新打开本 revision，编辑必须创建 revision_no + 1；
|   · 规则版本一旦离开 draft（approved / superseded / withdrawn），
|     正文、文件与指纹即冻结，新规则只能发新 version_no。
|
| fingerprint_version 参与后续内容指纹计算（G1-04 下一批实现指纹服务）；
| 改算法/拼接口径时必须同时递增它，否则新旧指纹不可比。
|
*/
return [
    // 活动修订审核轴。
    'review_states' => [
        'draft',
        'in_review',
        'approved',
        'rejected',
    ],

    // 规则版本状态轴。
    'rule_statuses' => [
        'draft',
        'approved',
        'superseded',
        'withdrawn',
    ],

    // 平台获准持有的依据类型（ADR §4.3 authorization_basis）。
    'authorization_bases' => [
        'self_organized',
        'written_authorization',
        'co_organization_agreement',
        'official_link_only',
    ],

    // 参与方式（与 events.participation_mode 同词表，但 revision 保存快照）。
    'participation_modes' => [
        'offline',
        'online',
        'hybrid',
    ],

    // 处于这些审核态时，revision 内容列不可更新。
    'immutable_review_states' => [
        'in_review',
        'approved',
        'rejected',
    ],

    // 处于这些状态时，规则正文 / 文件 / 指纹不可更新。
    'immutable_rule_statuses' => [
        'approved',
        'superseded',
        'withdrawn',
    ],

    // 内容指纹口径版本；算法变更必须递增。
    'fingerprint_version' => 1,
];
