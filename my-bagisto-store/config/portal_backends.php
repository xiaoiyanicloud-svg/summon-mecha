<?php

/**
 * 统一后台选择入口与多租户商家后台配置注册表。
 *
 * 对齐 config/developer_portal.php 与 config/skill_delivery.php 的“配置注册表”范式：
 * - 前端绝不硬编码“三个后台”，一律由 GET /api/portal/backends 动态下发；
 * - 新增第 N 个后台只需在 backends 数组追加配置（或写入 portal_backend_registry 表），
 *   前后端业务代码零改动。
 */
return [
    /*
    |--------------------------------------------------------------------------
    | 商家后台路由前缀
    |--------------------------------------------------------------------------
    | ⚠ 严禁包含 `/install`（Bagisto 全局 CanInstall 中间件会拦截并重定向至 /admin）。
    */
    'merchant_prefix' => env('MERCHANT_PORTAL_PREFIX', 'merchant'),

    /*
    |--------------------------------------------------------------------------
    | 商家后台准入模式
    |--------------------------------------------------------------------------
    | strict_membership = true 时，只有已入驻商家（merchant_profiles / merchant_members 激活）
    | 或已通过供应链邀约审核（cooperation_applications）的客户拥有 `portal.merchant.access` 权限；
    | 普通买家在后台选择入口将看到置灰状态及明确原因，且直连 /merchant 会被 403 拦截。
    */
    'strict_membership' => (bool) env('MERCHANT_PORTAL_STRICT_MEMBERSHIP', true),

    /*
    |--------------------------------------------------------------------------
    | 商家店铺 RBAC 角色与权限码矩阵
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'owner' => [
            'name'        => '店主（主账号）',
            'label'       => '店主（主账号）',
            'permissions' => ['*'],
        ],
        'admin' => [
            'name'        => '店铺管理员',
            'label'       => '店铺管理员',
            'permissions' => [
                'portal.merchant.access',
                'merchant.dashboard.view',
                'merchant.shop.manage',
                'merchant.product.view',
                'merchant.product.write',
                'merchant.product.submit',
                'merchant.product.submit_review',
                'merchant.product.shelf',
                'merchant.inventory.view',
                'merchant.inventory.write',
                'merchant.inventory.manage',
                'merchant.order.view',
                'merchant.order.fulfill',
                'merchant.after_sale.view',
                'merchant.after_sales.manage',
                'merchant.settlement.view',
                'merchant.member.manage',
                'merchant.audit.view',
            ],
        ],
        'catalog_ops' => [
            'name'        => '商品运营',
            'label'       => '商品运营',
            'permissions' => [
                'portal.merchant.access',
                'merchant.dashboard.view',
                'merchant.product.view',
                'merchant.product.write',
                'merchant.product.submit',
                'merchant.product.submit_review',
                'merchant.product.shelf',
                'merchant.inventory.view',
            ],
        ],
        'warehouse' => [
            'name'        => '仓管与租赁履约',
            'label'       => '仓管与租赁履约',
            'permissions' => [
                'portal.merchant.access',
                'merchant.dashboard.view',
                'merchant.product.view',
                'merchant.inventory.view',
                'merchant.inventory.write',
                'merchant.inventory.manage',
                'merchant.order.view',
                'merchant.order.fulfill',
                'merchant.after_sale.view',
                'merchant.after_sales.manage',
            ],
        ],
        'finance' => [
            'name'        => '财务结算',
            'label'       => '财务结算',
            'permissions' => [
                'portal.merchant.access',
                'merchant.dashboard.view',
                'merchant.order.view',
                'merchant.settlement.view',
                'merchant.audit.view',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 商家入驻协议（电子签留痕，version 变更必须升版）
    |--------------------------------------------------------------------------
    */
    'agreements' => [
        'merchant_service_agreement' => [
            'version' => '1.0',
            'title'   => '召唤机甲商家入驻与交易服务协议',
            'text'    => '商家承诺所上架的机器人整机（售卖/租赁）及配件具备合法货源与质量合格证明，'
                .'严格遵守平台 L1/L2/L3 机型兼容性分级承诺，不将仅展示机型虚标为开箱即用，'
                .'并按约定履行发货、租赁整备、验机定损与售后质保义务。',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 可用后台注册表（默认基线，可被 portal_backend_registry 表按 code 覆盖或扩展）
    |--------------------------------------------------------------------------
    */
    'backends' => [
        [
            'code'            => 'merchant_admin',
            'name'            => '商家后台',
            'badge'           => '机器人租售 · 配件 · 履约',
            'description'     => '管理机器人售卖与租赁商品、配件适配、双轨库存、订单履约与结算看板',
            'icon'            => '🏪',
            'route_url'       => '/merchant',
            'permissions'     => ['portal.merchant.access'],
            'enabled'         => true,
            'disabled_reason' => '尚未开通商家后台权限，请先完成供应链/商家入驻审核',
            'sort'            => 10,
        ],
        [
            'code'            => 'developer_skill',
            'name'            => '开发者技能后台',
            'badge'           => '技能包 · 审核 · 分发',
            'description'     => '上传与管理机器人技能包（.skill），追踪三层安全审核进度与机型适配',
            'icon'            => '🧑‍💻',
            'route_url'       => '/dev',
            'permissions'     => ['portal.developer.access'],
            'enabled'         => true,
            'disabled_reason' => '尚未开通开发者技能后台权限',
            'sort'            => 20,
        ],
        [
            'code'            => 'scene_partner_portal',
            'name'            => '场景交付伙伴后台',
            'badge'           => '即将开放',
            'description'     => '面向城市认证交付团队，提供现场部署、租后巡检维保与驻场工单调度',
            'icon'            => '🌐',
            'route_url'       => '/partner-ops',
            'permissions'     => ['portal.scene_partner.access'],
            'enabled'         => false,
            'disabled_reason' => '城市交付伙伴后台内测准备中，暂未开放',
            'sort'            => 30,
        ],
    ],
];
