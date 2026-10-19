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
        'admin.catalog.ecosystem.events.show'                  => 'ecosystem.events.view',
        'admin.catalog.ecosystem.events.metadata'              => 'ecosystem.events.edit',
        'admin.catalog.ecosystem.events.revisions.store'       => 'ecosystem.events.create',
        'admin.catalog.ecosystem.events.revisions.preview'     => 'ecosystem.events.view',
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
        'admin.catalog.ecosystem.content.revisions.store'      => 'ecosystem.content.edit',
        'admin.catalog.ecosystem.content.revisions.preview'    => 'ecosystem.content.view',
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
    ],
];
