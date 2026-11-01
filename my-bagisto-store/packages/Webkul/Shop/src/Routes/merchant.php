<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shop\Http\Controllers\Merchant\MerchantPortalController;

/**
 * 商家后台 Web SSR 路由（一二三四级子功能全链路闭环，与 /dev 开发者后台并行共存）。
 */
Route::prefix(config('portal_backends.merchant_prefix', 'merchant'))->group(function () {
    Route::get('login', [MerchantPortalController::class, 'showLogin'])
        ->name('shop.merchant.login');
    Route::post('login', [MerchantPortalController::class, 'login'])
        ->middleware('throttle:login')
        ->name('shop.merchant.login.store');

    Route::middleware('merchant.session')->group(function () {
        Route::post('logout', [MerchantPortalController::class, 'logout'])
            ->name('shop.merchant.logout');

        Route::get('onboarding', [MerchantPortalController::class, 'showOnboarding'])
            ->name('shop.merchant.onboarding');
        Route::post('onboarding', [MerchantPortalController::class, 'storeOnboarding'])
            ->name('shop.merchant.onboarding.store');
    });

    Route::middleware('merchant')->group(function () {
        Route::get('', [MerchantPortalController::class, 'dashboard'])
            ->name('shop.merchant.dashboard');

        // 商品中心（L1 列表筛选 -> L2 商品详情工作台 -> L3 SKU/租赁计划/参数媒体 -> L4 提审/审批/上下架）
        Route::get('products', [MerchantPortalController::class, 'products'])
            ->name('shop.merchant.products');
        Route::post('products', [MerchantPortalController::class, 'storeProduct'])
            ->name('shop.merchant.products.store');
        Route::get('products/{id}', [MerchantPortalController::class, 'showProduct'])
            ->whereNumber('id')
            ->name('shop.merchant.products.show');
        Route::post('products/{id}/update', [MerchantPortalController::class, 'updateProduct'])
            ->whereNumber('id')
            ->name('shop.merchant.products.update');
        Route::post('products/{id}/skus', [MerchantPortalController::class, 'storeSku'])
            ->whereNumber('id')
            ->name('shop.merchant.products.skus.store');
        Route::post('products/{id}/rental-plans', [MerchantPortalController::class, 'storeRentalPlan'])
            ->whereNumber('id')
            ->name('shop.merchant.products.rental-plans.store');
        Route::post('products/{id}/submit-review', [MerchantPortalController::class, 'submitReview'])
            ->whereNumber('id')
            ->name('shop.merchant.products.submit-review');
        Route::post('products/{id}/review-decision', [MerchantPortalController::class, 'reviewDecision'])
            ->whereNumber('id')
            ->name('shop.merchant.products.review-decision');
        Route::post('products/{id}/on-shelf', [MerchantPortalController::class, 'onShelf'])
            ->whereNumber('id')
            ->name('shop.merchant.products.on-shelf');
        Route::post('products/{id}/off-shelf', [MerchantPortalController::class, 'offShelf'])
            ->whereNumber('id')
            ->name('shop.merchant.products.off-shelf');

        // 库存中心（多仓库 + 双轨库存 + 维保锁定与释放 + 盘点流水）
        Route::get('inventory', [MerchantPortalController::class, 'inventory'])
            ->name('shop.merchant.inventory');
        Route::post('warehouses', [MerchantPortalController::class, 'storeWarehouse'])
            ->name('shop.merchant.warehouses.store');
        Route::post('inventory/{id}', [MerchantPortalController::class, 'adjustInventory'])
            ->whereNumber('id')
            ->name('shop.merchant.inventory.adjust');

        // 订单与租赁履约中心（下单 -> 发货绑SN -> 归还登记 -> 验机定损 -> 完结退押）
        Route::get('orders', [MerchantPortalController::class, 'orders'])
            ->name('shop.merchant.orders');
        Route::post('orders', [MerchantPortalController::class, 'storeOrder'])
            ->name('shop.merchant.orders.store');
        Route::post('orders/{id}/ship', [MerchantPortalController::class, 'shipOrder'])
            ->whereNumber('id')
            ->name('shop.merchant.orders.ship');
        Route::post('orders/{id}/return', [MerchantPortalController::class, 'returnOrder'])
            ->whereNumber('id')
            ->name('shop.merchant.orders.return');
        Route::post('orders/{id}/inspect', [MerchantPortalController::class, 'inspectOrder'])
            ->whereNumber('id')
            ->name('shop.merchant.orders.inspect');
        Route::post('orders/{id}/complete', [MerchantPortalController::class, 'completeOrder'])
            ->whereNumber('id')
            ->name('shop.merchant.orders.complete');
        Route::post('orders/{id}/cancel', [MerchantPortalController::class, 'cancelOrder'])
            ->whereNumber('id')
            ->name('shop.merchant.orders.cancel');

        // 售后与维保工单中心
        Route::get('after-sales', [MerchantPortalController::class, 'afterSales'])
            ->name('shop.merchant.after-sales');
        Route::post('after-sales', [MerchantPortalController::class, 'storeAfterSales'])
            ->name('shop.merchant.after-sales.store');
        Route::post('after-sales/{id}/resolve', [MerchantPortalController::class, 'resolveAfterSales'])
            ->whereNumber('id')
            ->name('shop.merchant.after-sales.resolve');

        // 结算与发票中心
        Route::get('settlements', [MerchantPortalController::class, 'settlements'])
            ->name('shop.merchant.settlements');
        Route::post('settlements', [MerchantPortalController::class, 'storeSettlement'])
            ->name('shop.merchant.settlements.store');
        Route::post('settlements/{id}/confirm', [MerchantPortalController::class, 'confirmSettlement'])
            ->whereNumber('id')
            ->name('shop.merchant.settlements.confirm');
        Route::post('invoices', [MerchantPortalController::class, 'storeInvoice'])
            ->name('shop.merchant.invoices.store');
        Route::post('invoices/{id}/issue', [MerchantPortalController::class, 'issueInvoice'])
            ->whereNumber('id')
            ->name('shop.merchant.invoices.issue');

        // 设置中心（店铺档案、多店铺切换、RBAC 成员管理、审计日志筛选）
        Route::get('settings', [MerchantPortalController::class, 'settings'])
            ->name('shop.merchant.settings');
        Route::post('shops/switch', [MerchantPortalController::class, 'switchShop'])
            ->name('shop.merchant.shops.switch');
        Route::post('shops', [MerchantPortalController::class, 'storeShop'])
            ->name('shop.merchant.shops.store');
        Route::post('shops/update-current', [MerchantPortalController::class, 'updateCurrentShop'])
            ->name('shop.merchant.shops.update-current');
        Route::post('members', [MerchantPortalController::class, 'upsertMember'])
            ->name('shop.merchant.members.store');
    });
});
