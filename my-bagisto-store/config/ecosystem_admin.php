<?php

/*
|--------------------------------------------------------------------------
| 生态发布工作台 · 路由 → 权限登记表（G1 Admin）
|--------------------------------------------------------------------------
|
| 后台「生态发布」的每一条路由都必须在这里登记权限。控制器在每个动作入口
| 读取本表并显式拒绝（403）；漏登记的路由在运行时直接 500（fail-closed），
| 并由 `EcosystemPublishingAclG1Test` 逐路由断言。
|
| 为什么不把授权只交给 `config/acl.php` 的 route 映射：
|   Bagisto 的 Bouncer 中间件对“已映射路由”统一返回 401，而 G1 工作台要求
|   直接 URL / Ajax 一律返回可读的 403（并带表单错误）。因此
|   `User\Http\Middleware\Bouncer` 对 `admin.catalog.ecosystem.*` 路由先读取
|   **本表**做 403 前置防线（不依赖控制器是否记得调用 authorizeRoute），
|   通过后再由控制器按同一张表做细粒度授权；`acl.php` 仍登记同一批权限 key
|   供角色分配。
|
| 权限 key 与 `config/acl.php` 中的条目必须一一对应。
|
*/
return [
    'route_permissions' => [
        // 工作台 / 活动
        'admin.catalog.ecosystem.index'                        => 'ecosystem.events.view',
        'admin.catalog.ecosystem.events.create'                => 'ecosystem.events.create',
        'admin.catalog.ecosystem.events.store'                 => 'ecosystem.events.create',
        'admin.catalog.ecosystem.events.show'                  => 'ecosystem.events.view',
        'admin.catalog.ecosystem.events.metadata'              => 'ecosystem.events.edit',
        'admin.catalog.ecosystem.events.revisions.store'       => 'ecosystem.events.create',
        'admin.catalog.ecosystem.events.revisions.preview'     => 'ecosystem.events.view',
        'admin.catalog.ecosystem.events.revisions.update'      => 'ecosystem.events.edit',
        'admin.catalog.ecosystem.events.revisions.media_refs.store'  => 'ecosystem.events.edit',
        'admin.catalog.ecosystem.events.revisions.media_refs.review' => 'ecosystem.media.review',
        'admin.catalog.ecosystem.events.revisions.submit'      => 'ecosystem.events.submit',
        'admin.catalog.ecosystem.events.revisions.approve'     => 'ecosystem.events.review',
        'admin.catalog.ecosystem.events.revisions.reject'      => 'ecosystem.events.review',
        'admin.catalog.ecosystem.events.revisions.schedule'    => 'ecosystem.events.schedule',
        'admin.catalog.ecosystem.events.revisions.publish'     => 'ecosystem.events.publish',
        'admin.catalog.ecosystem.events.unschedule'            => 'ecosystem.events.schedule',
        'admin.catalog.ecosystem.events.withhold'              => 'ecosystem.events.withhold',
        'admin.catalog.ecosystem.events.restore'               => 'ecosystem.events.withhold',
        'admin.catalog.ecosystem.events.retract'               => 'ecosystem.events.retract',

        // 资讯
        'admin.catalog.ecosystem.content.index'                => 'ecosystem.content.view',
        'admin.catalog.ecosystem.content.store'                => 'ecosystem.content.create',
        'admin.catalog.ecosystem.content.show'                 => 'ecosystem.content.view',
        'admin.catalog.ecosystem.content.metadata'             => 'ecosystem.content.edit',
        'admin.catalog.ecosystem.content.revisions.store'      => 'ecosystem.content.edit',
        'admin.catalog.ecosystem.content.revisions.preview'    => 'ecosystem.content.view',
        'admin.catalog.ecosystem.content.revisions.update'     => 'ecosystem.content.edit',
        'admin.catalog.ecosystem.content.revisions.media_refs.store'  => 'ecosystem.content.edit',
        'admin.catalog.ecosystem.content.revisions.media_refs.review' => 'ecosystem.media.review',
        'admin.catalog.ecosystem.content.revisions.submit'     => 'ecosystem.content.submit',
        'admin.catalog.ecosystem.content.revisions.approve'    => 'ecosystem.content.review',
        'admin.catalog.ecosystem.content.revisions.reject'     => 'ecosystem.content.review',
        'admin.catalog.ecosystem.content.revisions.schedule'   => 'ecosystem.content.schedule',
        'admin.catalog.ecosystem.content.revisions.publish'    => 'ecosystem.content.publish',
        'admin.catalog.ecosystem.content.unschedule'           => 'ecosystem.content.schedule',
        'admin.catalog.ecosystem.content.withhold'             => 'ecosystem.content.withhold',
        'admin.catalog.ecosystem.content.restore'              => 'ecosystem.content.withhold',
        'admin.catalog.ecosystem.content.retract'              => 'ecosystem.content.retract',

        // 主办方
        'admin.catalog.ecosystem.organizers.index'             => 'ecosystem.organizers.view',
        'admin.catalog.ecosystem.organizers.store'             => 'ecosystem.organizers.manage',
        'admin.catalog.ecosystem.organizers.verify'            => 'ecosystem.organizers.verify',
        'admin.catalog.ecosystem.organizers.reject'            => 'ecosystem.organizers.verify',
        'admin.catalog.ecosystem.organizers.suspend'           => 'ecosystem.organizers.verify',

        // 媒体工作台
        'admin.catalog.ecosystem.media.index'                  => 'ecosystem.media.view',
        'admin.catalog.ecosystem.media.preview'                => 'ecosystem.media.view',
        'admin.catalog.ecosystem.media.store'                  => 'ecosystem.media.upload',
        'admin.catalog.ecosystem.media.approve'                => 'ecosystem.media.review',
        'admin.catalog.ecosystem.media.reject'                 => 'ecosystem.media.review',
        'admin.catalog.ecosystem.media.quarantine'             => 'ecosystem.media.review',

        // G2 报名运营（scope / 人员范围）。审核、PII、导出、签到在后续批次登记。
        'admin.catalog.ecosystem.registrations.scope'          => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.scope.store'    => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.scope.update'   => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.scope.activate' => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.scope.close'    => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.scope.reopen'   => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.scope.capacity' => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.staff'          => 'ecosystem.registrations.staff',
        'admin.catalog.ecosystem.registrations.staff.grant'    => 'ecosystem.registrations.staff',
        'admin.catalog.ecosystem.registrations.staff.revoke'   => 'ecosystem.registrations.staff',

        'admin.catalog.ecosystem.registrations.index'          => 'ecosystem.registrations.view',
        'admin.catalog.ecosystem.registrations.show'           => 'ecosystem.registrations.view',
        'admin.catalog.ecosystem.registrations.approve'        => 'ecosystem.registrations.review',
        'admin.catalog.ecosystem.registrations.reject'         => 'ecosystem.registrations.review',
        'admin.catalog.ecosystem.registrations.cancel'         => 'ecosystem.registrations.cancel',
        'admin.catalog.ecosystem.registrations.checkin'        => 'ecosystem.registrations.checkin',
        'admin.catalog.ecosystem.registrations.checkin.store'  => 'ecosystem.registrations.checkin',
        'admin.catalog.ecosystem.registrations.changes.cancel' => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.changes.reschedule' => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.registrations.export'         => 'ecosystem.registrations.export',
        'admin.catalog.ecosystem.registrations.export.download'=> 'ecosystem.registrations.export',

        // G4-03 关联维护（活动/资讯分别沿用各自的编辑权限）
        'admin.catalog.ecosystem.relations.events.index'       => 'ecosystem.events.edit',
        'admin.catalog.ecosystem.relations.events.store'       => 'ecosystem.events.edit',
        'admin.catalog.ecosystem.relations.events.destroy'     => 'ecosystem.events.edit',
        'admin.catalog.ecosystem.relations.content.index'      => 'ecosystem.content.edit',
        'admin.catalog.ecosystem.relations.content.store'      => 'ecosystem.content.edit',
        'admin.catalog.ecosystem.relations.content.destroy'    => 'ecosystem.content.edit',

        // G4-06 / G4-07 运营指标与工作台
        'admin.catalog.ecosystem.metrics.index'                => 'ecosystem.metrics.view',
        'admin.catalog.ecosystem.ops.index'                    => 'ecosystem.ops.view',
        'admin.catalog.ecosystem.ops.outbox.retry'             => 'ecosystem.ops.retry',

        // G3 赛务（系列/届次/赛项/规则/赛程导入）
        'admin.catalog.ecosystem.competition.index'            => 'ecosystem.competition.view',
        'admin.catalog.ecosystem.competition.series.store'     => 'ecosystem.competition.settings',
        'admin.catalog.ecosystem.competition.editions.store'   => 'ecosystem.competition.settings',
        'admin.catalog.ecosystem.competition.tracks.store'     => 'ecosystem.competition.settings',
        'admin.catalog.ecosystem.competition.tracks.rules'     => 'ecosystem.competition.settings',
        'admin.catalog.ecosystem.competition.tracks.activate'  => 'ecosystem.competition.settings',
        'admin.catalog.ecosystem.competition.tracks.close'     => 'ecosystem.competition.settings',
        'admin.catalog.ecosystem.competition.tracks.scope.activate' => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.competition.tracks.scope.close'    => 'ecosystem.registrations.settings',
        'admin.catalog.ecosystem.competition.schedule'         => 'ecosystem.competition.view',
        'admin.catalog.ecosystem.competition.schedule.preview' => 'ecosystem.competition.schedule',
        'admin.catalog.ecosystem.competition.schedule.import'  => 'ecosystem.competition.schedule',
        'admin.catalog.ecosystem.competition.stages.publish'   => 'ecosystem.competition.publish',

        // G3-08 成绩（录分 / 复核发布分权）
        'admin.catalog.ecosystem.competition.results'          => 'ecosystem.competition.view',
        'admin.catalog.ecosystem.competition.results.draft'    => 'ecosystem.competition.scoring',
        'admin.catalog.ecosystem.competition.results.submit'   => 'ecosystem.competition.scoring',
        'admin.catalog.ecosystem.competition.results.preview_ranking' => 'ecosystem.competition.results',
        'admin.catalog.ecosystem.competition.results.ranking'  => 'ecosystem.competition.results',
        'admin.catalog.ecosystem.competition.results.publish'  => 'ecosystem.competition.results',
        'admin.catalog.ecosystem.competition.results.finalize' => 'ecosystem.competition.results',
        'admin.catalog.ecosystem.competition.results.revise'   => 'ecosystem.competition.results',

        // G3-09 申诉
        'admin.catalog.ecosystem.competition.appeals'          => 'ecosystem.competition.view',
        'admin.catalog.ecosystem.competition.appeals.accept'   => 'ecosystem.competition.appeal',
        'admin.catalog.ecosystem.competition.appeals.decide'   => 'ecosystem.competition.appeal',
        'admin.catalog.ecosystem.competition.appeal_attachments.download' => 'ecosystem.competition.appeal',

        // G3-10 奖项
        'admin.catalog.ecosystem.competition.awards'           => 'ecosystem.competition.view',
        'admin.catalog.ecosystem.competition.awards.version'   => 'ecosystem.competition.awards',
        'admin.catalog.ecosystem.competition.awards.publish'   => 'ecosystem.competition.awards',
        'admin.catalog.ecosystem.competition.awards.revoke'    => 'ecosystem.competition.awards',

        // G3-11 作品展示
        'admin.catalog.ecosystem.competition.showcases'        => 'ecosystem.competition.view',
        'admin.catalog.ecosystem.competition.showcases.store'  => 'ecosystem.competition.showcase',
        'admin.catalog.ecosystem.competition.showcases.review' => 'ecosystem.competition.showcase',
        'admin.catalog.ecosystem.competition.showcases.publish'=> 'ecosystem.competition.showcase',
        'admin.catalog.ecosystem.competition.showcases.reject' => 'ecosystem.competition.showcase',
        'admin.catalog.ecosystem.competition.showcases.withdraw' => 'ecosystem.competition.showcase',
    ],
];
