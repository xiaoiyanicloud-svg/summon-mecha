<?php

return [
    /**
     * Dashboard.
     */
    [
        'key'        => 'dashboard',
        'name'       => 'admin::app.components.layouts.sidebar.dashboard',
        'route'      => 'admin.dashboard.index',
        'sort'       => 1,
        'icon'       => 'icon-dashboard',
    ], [
        'key'        => 'dashboard.alerts',
        'name'       => '平台告警',
        'route'      => 'admin.alerts.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'dashboard.overview',
        'name'       => '运营总览',
        'route'      => 'admin.dashboard.ops',
        'sort'       => 2,
        'icon'       => '',
    ], [
        // 开票登记（M9）。刻意放这里而不是 sales 下：sales 里已有一个原生的「发票」
        // （Bagisto 的订单发货单），两个同名的"发票"挨着放必然被读混。
        'key'        => 'dashboard.invoices',
        'name'       => '发票（开票登记）',
        'route'      => 'admin.invoices.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        // 售后/退款审批台（B-1）。放在仪表盘组：与告警/总览/发票同属"运营每天要看"的一类。
        'key'        => 'dashboard.after-sales',
        'name'       => '售后审批',
        'route'      => 'admin.after-sales.index',
        'sort'       => 4,
        'icon'       => '',
    ],

    /**
     * Sales.
     */
    [
        'key'        => 'sales',
        'name'       => 'admin::app.components.layouts.sidebar.sales',
        'route'      => 'admin.sales.orders.index',
        'sort'       => 2,
        'icon'       => 'icon-sales',
    ], [
        'key'        => 'sales.orders',
        'name'       => 'admin::app.components.layouts.sidebar.orders',
        'route'      => 'admin.sales.orders.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'sales.shipments',
        'name'       => 'admin::app.components.layouts.sidebar.shipments',
        'route'      => 'admin.sales.shipments.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'sales.invoices',
        'name'       => 'admin::app.components.layouts.sidebar.invoices',
        'route'      => 'admin.sales.invoices.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'sales.refunds',
        'name'       => 'admin::app.components.layouts.sidebar.refunds',
        'route'      => 'admin.sales.refunds.index',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'sales.transactions',
        'name'       => 'admin::app.components.layouts.sidebar.transactions',
        'route'      => 'admin.sales.transactions.index',
        'sort'       => 5,
        'icon'       => '',
    ], [
        'key'        => 'sales.bookings',
        'name'       => 'admin::app.components.layouts.sidebar.booking-product',
        'route'      => 'admin.sales.bookings.index',
        'sort'       => 6,
        'icon'       => '',
    ],

    /**
     * Catalog.
     */
    [
        'key'        => 'catalog',
        'name'       => 'admin::app.components.layouts.sidebar.catalog',
        'route'      => 'admin.catalog.products.index',
        'sort'       => 3,
        'icon'       => 'icon-product',
    ], [
        'key'        => 'catalog.products',
        'name'       => 'admin::app.components.layouts.sidebar.products',
        'route'      => 'admin.catalog.products.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'catalog.categories',
        'name'       => 'admin::app.components.layouts.sidebar.categories',
        'route'      => 'admin.catalog.categories.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'catalog.scenes',
        'name'       => 'admin::app.components.layouts.sidebar.scenes',
        'route'      => 'admin.catalog.scenes.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'catalog.events',
        'name'       => '赛事活动',
        'route'      => 'admin.catalog.events.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        // G1 生态发布工作台。菜单隐藏≠授权：直接 URL / Ajax 由控制器逐动作拒绝。
        'key'        => 'ecosystem',
        'name'       => '生态发布',
        'route'      => 'admin.catalog.ecosystem.index',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'catalog.skills',
        'name'       => '技能管理',
        'route'      => 'admin.catalog.skills.index',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'catalog.skill-import',
        'name'       => '技能导入',
        'route'      => 'admin.catalog.skills.import',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'catalog.skill-reviews',
        'name'       => '审核台',
        'route'      => 'admin.catalog.skill-reviews.index',
        'sort'       => 5,
        'icon'       => '',
    ], [
        'key'        => 'catalog.device-models',
        'name'       => '机型库',
        'route'      => 'admin.catalog.device-models.index',
        'sort'       => 6,
        'icon'       => '',
    ], [
        'key'        => 'catalog.skill-categories',
        'name'       => '技能类目',
        'route'      => 'admin.catalog.skill-categories.index',
        'sort'       => 7,
        'icon'       => '',
    ], [
        'key'        => 'catalog.skill-tags',
        'name'       => '技能标签',
        'route'      => 'admin.catalog.skill-tags.index',
        'sort'       => 8,
        'icon'       => '',
    ], [
        'key'        => 'catalog.content-security',
        'name'       => '内容安全',
        'route'      => 'admin.catalog.content-security.index',
        'sort'       => 9,
        'icon'       => '',
    ], [
        'key'        => 'catalog.skill-reports',
        'name'       => '举报处置台',
        'route'      => 'admin.catalog.skill-reports.index',
        'sort'       => 10,
        'icon'       => '',
    ], [
        'key'        => 'catalog.skill-review-moderation',
        'name'       => '评价处置台',
        'route'      => 'admin.catalog.skill-review-moderation.index',
        'sort'       => 11,
        'icon'       => '',
    ], [
        'key'        => 'catalog.attributes',
        'name'       => 'admin::app.components.layouts.sidebar.attributes',
        'route'      => 'admin.catalog.attributes.index',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'catalog.families',
        'name'       => 'admin::app.components.layouts.sidebar.attribute-families',
        'route'      => 'admin.catalog.families.index',
        'sort'       => 5,
        'icon'       => '',
    ],

    /**
     * Customers.
     */
    [
        'key'        => 'customers',
        'name'       => 'admin::app.components.layouts.sidebar.customers',
        'route'      => 'admin.customers.customers.index',
        'sort'       => 4,
        'icon'       => 'icon-customer-2',
    ], [
        'key'        => 'customers.customers',
        'name'       => 'admin::app.components.layouts.sidebar.customers',
        'route'      => 'admin.customers.customers.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'customers.groups',
        'name'       => 'admin::app.components.layouts.sidebar.groups',
        'route'      => 'admin.customers.groups.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'customers.reviews',
        'name'       => 'admin::app.components.layouts.sidebar.reviews',
        'route'      => 'admin.customers.customers.review.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'customers.gdpr_requests',
        'name'       => 'admin::app.components.layouts.sidebar.gdpr-data-requests',
        'route'      => 'admin.customers.gdpr.index',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'customers.developers',
        'name'       => '开发者管理',
        'route'      => 'admin.customers.developers.index',
        'sort'       => 5,
        'icon'       => '',
    ], [
        'key'        => 'customers.developer-invites',
        'name'       => '开发者邀请码',
        'route'      => 'admin.customers.developer-invites.index',
        'sort'       => 6,
        'icon'       => '',
    ], [
        'key'        => 'customers.tickets',
        'name'       => '客服工单',
        'route'      => 'admin.customers.tickets.index',
        'sort'       => 7,
        'icon'       => '',
    ], [
        'key'        => 'customers.incidents',
        'name'       => '事故受理',
        'route'      => 'admin.customers.incidents.index',
        'sort'       => 8,
        'icon'       => '',
    ],

    /**
     * CMS.
     */
    [
        'key'        => 'cms',
        'name'       => 'admin::app.components.layouts.sidebar.cms',
        'route'      => 'admin.cms.index',
        'sort'       => 5,
        'icon'       => 'icon-cms',
    ],

    /**
     * Marketing.
     */
    [
        'key'        => 'marketing',
        'name'       => 'admin::app.components.layouts.sidebar.marketing',
        'route'      => 'admin.marketing.promotions.catalog_rules.index',
        'sort'       => 6,
        'icon'       => 'icon-promotion',
        'icon-class' => 'promotion-icon',
    ], [
        'key'        => 'marketing.promotions',
        'name'       => 'admin::app.components.layouts.sidebar.promotions',
        'route'      => 'admin.marketing.promotions.catalog_rules.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'marketing.promotions.catalog_rules',
        'name'       => 'admin::app.marketing.promotions.index.catalog-rule-title',
        'route'      => 'admin.marketing.promotions.catalog_rules.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'marketing.promotions.cart_rules',
        'name'       => 'admin::app.marketing.promotions.index.cart-rule-title',
        'route'      => 'admin.marketing.promotions.cart_rules.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'marketing.communications',
        'name'       => 'admin::app.components.layouts.sidebar.communications',
        'route'      => 'admin.marketing.communications.email_templates.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'marketing.communications.email_templates',
        'name'       => 'admin::app.components.layouts.sidebar.email-templates',
        'route'      => 'admin.marketing.communications.email_templates.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'marketing.communications.events',
        'name'       => 'admin::app.components.layouts.sidebar.events',
        'route'      => 'admin.marketing.communications.events.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'marketing.communications.campaigns',
        'name'       => 'admin::app.components.layouts.sidebar.campaigns',
        'route'      => 'admin.marketing.communications.campaigns.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'marketing.communications.subscribers',
        'name'       => 'admin::app.components.layouts.sidebar.newsletter-subscriptions',
        'route'      => 'admin.marketing.communications.subscribers.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'marketing.search_seo',
        'name'       => 'admin::app.components.layouts.sidebar.search-seo',
        'route'      => 'admin.marketing.search_seo.url_rewrites.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'marketing.search_seo.url_rewrites',
        'name'       => 'admin::app.components.layouts.sidebar.url-rewrites',
        'route'      => 'admin.marketing.search_seo.url_rewrites.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'marketing.search_seo.search_terms',
        'name'       => 'admin::app.components.layouts.sidebar.search-terms',
        'route'      => 'admin.marketing.search_seo.search_terms.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'marketing.search_seo.search_synonyms',
        'name'       => 'admin::app.components.layouts.sidebar.search-synonyms',
        'route'      => 'admin.marketing.search_seo.search_synonyms.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'marketing.search_seo.sitemaps',
        'name'       => 'admin::app.components.layouts.sidebar.sitemaps',
        'route'      => 'admin.marketing.search_seo.sitemaps.index',
        'sort'       => 4,
        'icon'       => '',
    ],

    /**
     * Reporting.
     */
    [
        'key'        => 'reporting',
        'name'       => 'admin::app.components.layouts.sidebar.reporting',
        'route'      => 'admin.reporting.sales.index',
        'sort'       => 7,
        'icon'       => 'icon-report',
        'icon-class' => 'report-icon',
    ], [
        'key'        => 'reporting.sales',
        'name'       => 'admin::app.components.layouts.sidebar.sales',
        'route'      => 'admin.reporting.sales.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'reporting.customers',
        'name'       => 'admin::app.components.layouts.sidebar.customers',
        'route'      => 'admin.reporting.customers.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'reporting.products',
        'name'       => 'admin::app.components.layouts.sidebar.products',
        'route'      => 'admin.reporting.products.index',
        'sort'       => 3,
        'icon'       => '',
    ],

    /**
     * Settings.
     */
    [
        'key'        => 'settings',
        'name'       => 'admin::app.components.layouts.sidebar.settings',
        'route'      => 'admin.settings.locales.index',
        'sort'       => 8,
        'icon'       => 'icon-settings',
        'icon-class' => 'settings-icon',
    ], [
        'key'        => 'settings.compliance',
        'name'       => '备案台账',
        'route'      => 'admin.settings.compliance.index',
        'sort'       => 0,
        'icon'       => '',
    ], [
        'key'        => 'settings.locales',
        'name'       => 'admin::app.components.layouts.sidebar.locales',
        'route'      => 'admin.settings.locales.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'settings.currencies',
        'name'       => 'admin::app.components.layouts.sidebar.currencies',
        'route'      => 'admin.settings.currencies.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'settings.exchange_rates',
        'name'       => 'admin::app.components.layouts.sidebar.exchange-rates',
        'route'      => 'admin.settings.exchange_rates.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'settings.inventory_sources',
        'name'       => 'admin::app.components.layouts.sidebar.inventory-sources',
        'route'      => 'admin.settings.inventory_sources.index',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'settings.channels',
        'name'       => 'admin::app.components.layouts.sidebar.channels',
        'route'      => 'admin.settings.channels.index',
        'sort'       => 5,
        'icon'       => '',
    ], [
        'key'        => 'settings.users',
        'name'       => 'admin::app.components.layouts.sidebar.users',
        'route'      => 'admin.settings.users.index',
        'sort'       => 6,
        'icon'       => '',
    ], [
        'key'        => 'settings.roles',
        'name'       => 'admin::app.components.layouts.sidebar.roles',
        'route'      => 'admin.settings.roles.index',
        'sort'       => 7,
        'icon'       => '',
    ], [
        'key'        => 'settings.themes',
        'name'       => 'admin::app.components.layouts.sidebar.themes',
        'route'      => 'admin.settings.themes.index',
        'sort'       => 8,
        'icon'       => '',
    ], [
        'key'        => 'settings.taxes',
        'name'       => 'admin::app.components.layouts.sidebar.taxes',
        'route'      => 'admin.settings.taxes.categories.index',
        'sort'       => 9,
        'icon'       => '',
    ], [
        'key'        => 'settings.taxes.tax_categories',
        'name'       => 'admin::app.components.layouts.sidebar.tax-categories',
        'route'      => 'admin.settings.taxes.categories.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'settings.taxes.tax_rates',
        'name'       => 'admin::app.components.layouts.sidebar.tax-rates',
        'route'      => 'admin.settings.taxes.rates.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'settings.data_transfer',
        'name'       => 'admin::app.components.layouts.sidebar.data-transfer',
        'route'      => 'admin.settings.data_transfer.imports.index',
        'sort'       => 10,
        'icon'       => '',
    ], [
        'key'        => 'settings.data_transfer.imports',
        'name'       => 'admin::app.components.layouts.sidebar.imports',
        'route'      => 'admin.settings.data_transfer.imports.index',
        'sort'       => 1,
        'icon'       => '',
    ],

    /**
     * Configuration.
     */
    [
        'key'        => 'configuration',
        'name'       => 'admin::app.components.layouts.sidebar.configure',
        'route'      => 'admin.configuration.index',
        'sort'       => 9,
        'icon'       => 'icon-configuration',
    ],
];
