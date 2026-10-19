<?php

/*
|--------------------------------------------------------------------------
| 生态主办方注册表（ADR-0001 G1-D01 / G1-D05）
|--------------------------------------------------------------------------
|
| 这是 `ecosystem_organizers.platform_role` / `status` 与 `events.source_type`
| 的单一事实源。`Services\Ecosystem\EventSourceValidator` 与
| `Services\Ecosystem\OrganizerService` 都按本表校验，禁止在业务代码里另写字面量。
|
| publish_allowed_* 是 B-01 / B-03 的落地开关：
|   两份阻塞项未签署前，只允许平台自办（platform + organizer）进入发布流程；
|   外部目录、联合主办、授权承办一律保持 draft。
|   签署后由业务改配置放开，而不是改代码里的 if。
|
*/
return [
    // 活动来源类型（与内容域的 source_type 枚举不同，必须分别校验）。
    'source_types' => [
        'platform',
        'external',
    ],

    // 平台角色：一期一个主体一个角色。
    'platform_roles' => [
        'organizer',
        'co_organizer',
        'authorized_operator',
        'directory_only',
    ],

    // 核验状态。
    'statuses' => [
        'pending',
        'verified',
        'rejected',
        'suspended',
    ],

    // 核验通过前至少要有其中一项（ADR §4.4 不变式）。
    'evidence_fields' => [
        'public_materials',
        'private_evidence_ref',
    ],

    // 主体已核验后，改动这些字段必须重新核验（回 pending 并清空核验人与时间）。
    'verification_reset_fields' => [
        'name',
        'platform_role',
        'source_url',
        'public_materials',
        'private_evidence_ref',
    ],

    // 发布放行组合（B-01/B-03 默认口径）。
    'publish_allowed_source_types' => [
        'platform',
    ],

    'publish_allowed_platform_roles' => [
        'organizer',
    ],
];
