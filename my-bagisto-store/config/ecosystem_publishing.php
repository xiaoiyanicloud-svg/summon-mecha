<?php

/*
|--------------------------------------------------------------------------
| 生态可信发布注册表（ADR-0001 附录 B / G1-08A / G1-08B）
|--------------------------------------------------------------------------
|
| 这是提交 / 批准 / 拒绝 / 发布 / 排期 / 暂缓 / 恢复 / 撤稿状态机、
| B 项开关、动作词表与发布前置的**单一事实源**。业务代码
| （PublicationState / PublicationPreconditions / Review / Publication services）
| 只按本表判断，禁止在服务里另写状态字面量。
|
| 边界：
|   · G1-08A 只做 draft -> in_review -> approved/rejected；
|   · G1-08B 做 approved/scheduled -> published、publish_status 不变更的候选切换、
|     withheld/restored/retracted 处置；outbox 只记录**已发生**的公开事实，
|     排期时间只写父对象 `scheduled_publish_at`，绝不塞进 outbox.available_at；
|   · 任何公开开关（ecosystem.events.public_enabled / ecosystem.content.public_enabled）
|     不在本文件，保持 false；
|   · B-01 / B-03 / B-04 / B-07 未签署：活动发布只放行 platform + organizer；
|     内容 publish / schedule 全部拒绝（仍可 submit/approve 演练）；
|   · `transitions.*` 的键是父对象当前 publish_status，值是动作后的状态；
|     `published` 在提交 / 批准 / 拒绝 / 排期 / 发布新候选后都保持 `published`（A-04）。
|
*/
return [
    // 审核动作总开关（不是公开开关）。关闭时 submit/approve/reject 一律拒绝。
    'event_review_enabled'   => (bool) env('ECOSYSTEM_EVENT_REVIEW_ENABLED', true),
    'content_review_enabled' => (bool) env('ECOSYSTEM_CONTENT_REVIEW_ENABLED', true),

    // G1-08B 发布 / 排期能力；默认 false，必须由运营显式开启。
    // 内容域还额外要求 B-04 / B-07 已签署，见 PublicationState::assertPublishEnabled()。
    'event_publish_enabled'   => (bool) env('ECOSYSTEM_EVENT_PUBLISH_ENABLED', false),
    'content_publish_enabled' => (bool) env('ECOSYSTEM_CONTENT_PUBLISH_ENABLED', false),
    'scheduler_enabled'       => (bool) env('ECOSYSTEM_SCHEDULER_ENABLED', false),

    // B 项签署状态。未签署时保持失败关闭。
    'b01_external_links_signed'            => false,
    'b03_organizer_verification_signed'    => false,
    'b04_content_slug_signed'              => false,
    'b07_content_legal_signed'             => false,

    // B-01 / B-03 未签署时的提交/发布放行组合；签署后由业务改配置放开。
    'publish_allowed_source_types'   => ['platform'],
    'publish_allowed_platform_roles' => ['organizer'],

    // ── 词表（禁止在服务里散落字面量） ──
    'publish_statuses' => [
        'draft',
        'in_review',
        'approved',
        'scheduled',
        'published',
    ],

    'dispositions' => [
        'normal',
        'withheld',
        'retracted',
    ],

    'actions' => [
        'review'      => ['submit', 'approve', 'reject'],
        'publication' => ['schedule', 'unschedule', 'publish', 'withhold', 'restore', 'retract'],
    ],

    // 活动提交前置要求的枚举（ADR G1-D02 / G1-D03）。
    'event_kinds' => [
        'competition',
        'salon',
        'workshop',
        'expo',
        'open_day',
    ],

    'event_hosting_statuses' => [
        'scheduled',
        'postponed',
        'rescheduled',
        'completed',
        'canceled',
    ],

    // B-06 未签署时只允许这些举办状态进入审核 / 发布；postponed/rescheduled/canceled 写能力关闭。
    'review_allowed_hosting_statuses' => [
        'scheduled',
        'completed',
    ],

    // 状态转移表：父 publish_status => 动作后状态。
    'transitions' => [
        'event' => [
            'submit' => [
                'draft'     => 'in_review',
                'published' => 'published',
            ],
            'approve' => [
                'in_review' => 'approved',
                'published' => 'published',
            ],
            'reject' => [
                'in_review' => 'draft',
                'published' => 'published',
            ],

            // ── G1-08B ──
            // 从未发布：approved -> scheduled；已发布对象保持 published，
            // 候选由 approved_revision_id + scheduled_publish_at 表达。
            'schedule' => [
                'approved'  => 'scheduled',
                'scheduled' => 'scheduled',
                'published' => 'published',
            ],
            // 取消排期：从未发布对象回到 approved；已发布对象保持 published。
            'unschedule' => [
                'scheduled' => 'approved',
                'published' => 'published',
            ],
            // 立即发布 / 到点发布：已发布对象切换新候选时状态不变。
            'publish' => [
                'approved'  => 'published',
                'scheduled' => 'published',
                'published' => 'published',
            ],
            // 暂缓 / 恢复只作用于当前已发布对象，publish_status 保持 published。
            'withhold' => [
                'published' => 'published',
            ],
            'restore' => [
                'published' => 'published',
            ],
            // 撤稿是终态：任意已知状态都保留原 publish_status，仅改 disposition。
            'retract' => [
                'draft'     => 'draft',
                'in_review' => 'in_review',
                'approved'  => 'approved',
                'scheduled' => 'scheduled',
                'published' => 'published',
            ],
        ],
        'content' => [
            'submit' => [
                'draft'     => 'in_review',
                'published' => 'published',
            ],
            'approve' => [
                'in_review' => 'approved',
                'published' => 'published',
            ],
            'reject' => [
                'in_review' => 'draft',
                'published' => 'published',
            ],

            // ── G1-08B（与活动同构） ──
            'schedule' => [
                'approved'  => 'scheduled',
                'scheduled' => 'scheduled',
                'published' => 'published',
            ],
            'unschedule' => [
                'scheduled' => 'approved',
                'published' => 'published',
            ],
            'publish' => [
                'approved'  => 'published',
                'scheduled' => 'published',
                'published' => 'published',
            ],
            'withhold' => [
                'published' => 'published',
            ],
            'restore' => [
                'published' => 'published',
            ],
            'retract' => [
                'draft'     => 'draft',
                'in_review' => 'in_review',
                'approved'  => 'approved',
                'scheduled' => 'scheduled',
                'published' => 'published',
            ],
        ],
    ],

    // outbox 事件类型映射：只有**已发生**的公开事实才写 outbox；
    // schedule / unschedule 不写（G1-09 扫描 scheduled_publish_at）。
    'outbox_event_types' => [
        'event' => [
            'publish' => 'event.published',
            'withhold' => 'event.withheld',
            'restore' => 'event.restored',
            'retract' => 'event.retracted',
        ],
        'content' => [
            'publish' => 'content.published',
            'withhold' => 'content.withheld',
            'restore' => 'content.restored',
            'retract' => 'content.retracted',
        ],
    ],

    // 审计 action 词表（OperationLogger::logOrFail 的 action）。
    'audit_actions' => [
        'event' => [
            // G1 Admin 新建活动草稿壳（EventDraftService）。
            'create'         => 'ecosystem.event.create',
            'submit'         => 'ecosystem.event.submit',
            'approve'        => 'ecosystem.event.approve',
            'reject'         => 'ecosystem.event.reject',
            'begin_revision' => 'ecosystem.event.begin_revision',
            'schedule'       => 'ecosystem.event.schedule',
            'unschedule'     => 'ecosystem.event.unschedule',
            'publish'        => 'ecosystem.event.publish',
            'withhold'       => 'ecosystem.event.withhold',
            'restore'        => 'ecosystem.event.restore',
            'retract'        => 'ecosystem.event.retract',
            // G1-09：到期扫描发现确定性失败时的回退动作（系统 actor）。
            'schedule_aborted' => 'ecosystem.event.schedule_aborted',
        ],
        'content' => [
            'submit'         => 'ecosystem.content.submit',
            'approve'        => 'ecosystem.content.approve',
            'reject'         => 'ecosystem.content.reject',
            'begin_revision' => 'ecosystem.content.begin_revision',
            'schedule'       => 'ecosystem.content.schedule',
            'unschedule'     => 'ecosystem.content.unschedule',
            'publish'        => 'ecosystem.content.publish',
            'withhold'       => 'ecosystem.content.withhold',
            'restore'        => 'ecosystem.content.restore',
            'retract'        => 'ecosystem.content.retract',
            // G1-09：到期扫描发现确定性失败时的回退动作（系统 actor）。
            'schedule_aborted' => 'ecosystem.content.schedule_aborted',
        ],
    ],

    // 只允许稳定原因码进入审计 payload；不写自由文本（可能含 PII）。
    // withhold / retract 的调用方原因只进内部日志，公开读取永远拿不到。
    'reason_codes' => [
        'created'            => 'created',
        'submitted'          => 'submitted',
        'approved'           => 'approved',
        'review_rejected'    => 'review_rejected',
        'new_working_draft'  => 'new_working_draft',
        'scheduled'          => 'scheduled',
        'unscheduled'        => 'unscheduled',
        'published'          => 'published',
        'scheduled_published' => 'scheduled_published',
        'withheld'           => 'withheld',
        'restored'           => 'restored',
        'retracted'          => 'retracted',
        // G1-09：到期发布确定性失败后的安全回退；只用于系统审计与告警。
        'schedule_aborted'   => 'schedule_aborted',
    ],

    // 处置原因（withhold / retract）的内部长度上限；只写日志，不进公开字段。
    'disposition_reason_max_length' => 500,

    // 审计 payload 白名单（只含 ID / 版本 / 原因码 / 指纹版本）。
    'audit_payload_keys' => [
        'revision_id',
        'revision_no',
        'from',
        'to',
        'fingerprint_version',
        'reason_code',
    ],
];
