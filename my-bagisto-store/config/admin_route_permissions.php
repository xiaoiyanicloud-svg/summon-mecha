<?php

/*
|--------------------------------------------------------------------------
| Admin 自定义路由 → 权限位注册表（Fail-Closed）
|--------------------------------------------------------------------------
|
| Bagisto 原生 Bouncer 中间件仅校验在 config/acl.php 里按单个 route 字段登记的路由。
| 本表补齐所有未在 config/ecosystem_admin.php 或 scene-workflow-routes.php 中托管的
| 召唤机甲自定义路由（包含详情读、AJAX 检索、以及全部 POST/PUT/DELETE 写入动作）。
|
| 当管理员所属角色非超级管理员（permission_type != 'all'）时，访问本表登记的任意路由
| 均由 Bouncer 前置拦截并返回 HTTP 403（fail-closed）。
*/

return [
    'route_permissions' => [
        // 仪表盘与全局检索
        'admin.dashboard.stats'                     => 'dashboard',
        'admin.dashboard.ops'                       => 'dashboard.overview',
        'admin.alerts.read'                         => 'dashboard.alerts',
        'admin.alerts.read-all'                     => 'dashboard.alerts',
        'admin.after-sales.show'                    => 'dashboard.after-sales',
        'admin.after-sales.approve'                 => 'dashboard.after-sales.approve',
        'admin.after-sales.reject'                  => 'dashboard.after-sales.reject',

        // 扩展全球/工作台检索
        'admin.search.skills'                       => 'catalog.skills',
        'admin.search.developers'                   => 'customers.developers',
        'admin.search.cooperations'                 => 'customers.cooperations',

        // 销售预订列表与数据接口
        'admin.sales.bookings.index'                => 'sales.bookings',
        'admin.sales.bookings.get'                  => 'sales.bookings',

        // 场景目录管理写操作
        'admin.catalog.scenes.store'                => 'catalog.scenes.create',
        'admin.catalog.scenes.update'               => 'catalog.scenes.edit',
        'admin.catalog.scenes.status'               => 'catalog.scenes.edit',
        'admin.catalog.scenes.hot'                  => 'catalog.scenes.edit',
        'admin.catalog.scenes.consult-count'        => 'catalog.scenes.edit',
        'admin.catalog.scenes.mass_update'          => 'catalog.scenes.edit',
        'admin.catalog.scenes.mass_delete'          => 'catalog.scenes.delete',

        // 技能版本 / 导入 / 资产 / 上下架管理
        'admin.catalog.skills.edit'                 => 'catalog.skills',
        'admin.catalog.skills.import.store'         => 'catalog.skill-import',
        'admin.catalog.skills.versions.store'       => 'catalog.skills.manage',
        'admin.catalog.skills.versions.update'      => 'catalog.skills.manage',
        'admin.catalog.skills.assets.store'         => 'catalog.skills.manage',
        'admin.catalog.skills.versions.publish'     => 'catalog.skills.manage',
        'admin.catalog.skills.versions.relist'      => 'catalog.skills.manage',
        'admin.catalog.skills.versions.delete'      => 'catalog.skills.manage',

        // 技能准入审核台
        'admin.catalog.skill-reviews.show'          => 'catalog.skill-reviews',
        'admin.catalog.skill-reviews.judge'         => 'catalog.skill-reviews.judge',
        'admin.catalog.skill-reviews.layer0'        => 'catalog.skill-reviews.judge',

        // 机型库 / 类目 / 标签 / 配件适配写操作
        'admin.catalog.device-models.store'         => 'catalog.device-models.create',
        'admin.catalog.device-models.update'        => 'catalog.device-models.edit',
        'admin.catalog.skill-categories.store'      => 'catalog.skill-categories.create',
        'admin.catalog.skill-categories.update'     => 'catalog.skill-categories.edit',
        'admin.catalog.skill-tags.store'            => 'catalog.skill-tags.create',
        'admin.catalog.skill-tags.update'           => 'catalog.skill-tags.edit',
        'admin.catalog.accessories.profile.update'  => 'catalog.products.edit',
        'admin.catalog.accessories.fitments.store'  => 'catalog.products.edit',
        'admin.catalog.accessories.fitments.delete' => 'catalog.products.edit',

        // 内容安全 / 举报 / 评价处置台
        'admin.catalog.content-security.dispose'         => 'catalog.content-security.dispose',
        'admin.catalog.skill-reports.dispose'            => 'catalog.skill-reports.dispose',
        'admin.catalog.skill-review-moderation.moderate' => 'catalog.skill-review-moderation.moderate',

        // 开发者管理 / 实名审核 / 启停
        'admin.customers.developers.cert.download'               => 'customers.developers.view',
        'admin.customers.developers.realname.approve'            => 'customers.developers.review',
        'admin.customers.developers.realname.reject'             => 'customers.developers.review',
        'admin.customers.developers.realname.layer.approve'      => 'customers.developers.review',
        'admin.customers.developers.realname.layer.reject'       => 'customers.developers.review',
        'admin.customers.developers.realname.layer.approve_all'  => 'customers.developers.review',
        'admin.customers.developers.status'                      => 'customers.developers.review',

        // 生态合作申请 & 快捷/专属发码（发码严格要求 developer-invites.manage 权限）
        'admin.customers.cooperations.status'       => 'customers.cooperations.review',
        'admin.customers.cooperations.invites.store' => 'customers.developer-invites.manage',
        'admin.customers.cooperations.issue_invite' => 'customers.developer-invites.manage',

        // 客服中心 & 在线工作台
        'admin.customers.tickets.updates'               => 'customers.tickets.view',
        'admin.customers.support.workbench'             => 'customers.tickets.view',
        'admin.customers.support.conversations'         => 'customers.tickets.view',
        'admin.customers.support.workbench-script'      => 'customers.tickets.view',
        'admin.customers.tickets.reply'                 => 'customers.tickets.handle',
        'admin.customers.tickets.read'                  => 'customers.tickets.handle',
        'admin.customers.tickets.claim'                 => 'customers.tickets.handle',
        'admin.customers.tickets.release'               => 'customers.tickets.handle',
        'admin.customers.tickets.transfer'              => 'customers.tickets.handle',
        'admin.customers.tickets.hold'                  => 'customers.tickets.handle',
        'admin.customers.tickets.resume'                => 'customers.tickets.handle',
        'admin.customers.tickets.notes.store'           => 'customers.tickets.handle',
        'admin.customers.tickets.typing'                => 'customers.tickets.handle',
        'admin.customers.tickets.status'                => 'customers.tickets.handle',
        'admin.customers.support.presence'              => 'customers.tickets.handle',
        'admin.customers.support.quick-replies.event'   => 'customers.tickets.handle',
        'admin.customers.tickets.escalate'              => 'customers.incidents.handle',

        // 事故受理
        'admin.customers.incidents.handle'          => 'customers.incidents.handle',

        // 合规备案台账写操作
        'admin.settings.compliance.store'           => 'settings.compliance.create',
        'admin.settings.compliance.update'          => 'settings.compliance.edit',
    ],
];
