<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Customers\AddressController;
use Webkul\Admin\Http\Controllers\Customers\Customer\CartController;
use Webkul\Admin\Http\Controllers\Customers\Customer\CompareController;
use Webkul\Admin\Http\Controllers\Customers\Customer\OrderController;
use Webkul\Admin\Http\Controllers\Customers\Customer\WishlistController;
use Webkul\Admin\Http\Controllers\Customers\CustomerController;
use Webkul\Admin\Http\Controllers\Customers\CustomerGroupController;
use Webkul\Admin\Http\Controllers\Customers\DeveloperController;
use Webkul\Admin\Http\Controllers\Customers\GDPRController;
use Webkul\Admin\Http\Controllers\Customers\IncidentController;
use Webkul\Admin\Http\Controllers\Customers\ReviewController;
use Webkul\Admin\Http\Controllers\Customers\SupportTicketController;

/**
 * Customers routes.
 */
Route::prefix('customers')->group(function () {
    /**
     * Customer management routes.
     */
    Route::controller(CustomerController::class)->group(function () {
        Route::get('', 'index')->name('admin.customers.customers.index');

        Route::get('view/{id}', 'show')->name('admin.customers.customers.view');

        Route::post('create', 'store')->name('admin.customers.customers.store');

        Route::get('search', 'search')->name('admin.customers.customers.search');

        Route::get('login-as-customer/{id}', 'loginAsCustomer')->name('admin.customers.customers.login_as_customer');

        Route::post('note/{id}', 'storeNotes')->name('admin.customer.note.store');

        Route::put('edit/{id}', 'update')->name('admin.customers.customers.update');

        Route::post('mass-delete', 'massDestroy')->name('admin.customers.customers.mass_delete');

        Route::post('mass-update', 'massUpdate')->name('admin.customers.customers.mass_update');

        Route::post('{id}', 'destroy')->name('admin.customers.customers.delete');

        Route::controller(WishlistController::class)->group(function () {
            Route::get('{id}/wishlist-items', 'items')->name('admin.customers.customers.wishlist.items');

            Route::delete('{id}/wishlist-items', 'destroy')->name('admin.customers.customers.wishlist.items.delete');
        });

        Route::controller(CompareController::class)->group(function () {
            Route::get('{id}/compare-items', 'items')->name('admin.customers.customers.compare.items');

            Route::delete('{id}/compare-items', 'destroy')->name('admin.customers.customers.compare.items.delete');
        });

        Route::controller(CartController::class)->prefix('{id}/cart')->group(function () {
            Route::post('create', 'store')->name('admin.customers.customers.cart.store');

            Route::get('items', 'items')->name('admin.customers.customers.cart.items');

            Route::delete('items', 'destroy')->name('admin.customers.customers.cart.items.delete');
        });

        Route::controller(OrderController::class)->group(function () {
            Route::get('{id}/recent-order-items', 'recentItems')->name('admin.customers.customers.orders.recent_items');
        });
    });

    /**
     * Customer's addresses routes.
     */
    Route::controller(AddressController::class)->group(function () {
        Route::prefix('{id}/addresses')->group(function () {
            Route::get('', 'index')->name('admin.customers.customers.addresses.index');

            Route::get('create', 'create')->name('admin.customers.customers.addresses.create');

            Route::post('create', 'store')->name('admin.customers.customers.addresses.store');
        });

        Route::prefix('addresses')->group(function () {
            Route::get('edit/{id}', 'edit')->name('admin.customers.customers.addresses.edit');

            Route::put('edit/{id}', 'update')->name('admin.customers.customers.addresses.update');

            Route::post('default/{id}', 'makeDefault')->name('admin.customers.customers.addresses.set_default');

            Route::post('delete/{id}', 'destroy')->name('admin.customers.customers.addresses.delete');
        });
    });

    /**
     * Customer's reviews routes.
     */
    Route::controller(ReviewController::class)->prefix('reviews')->group(function () {
        Route::get('', 'index')->name('admin.customers.customers.review.index');

        Route::get('edit/{id}', 'edit')->name('admin.customers.customers.review.edit');

        Route::put('edit/{id}', 'update')->name('admin.customers.customers.review.update');

        Route::delete('/{id}', 'destroy')->name('admin.customers.customers.review.delete');

        Route::post('mass-delete', 'massDestroy')->name('admin.customers.customers.review.mass_delete');

        Route::post('mass-update', 'massUpdate')->name('admin.customers.customers.review.mass_update');
    });

    /**
     * Customer groups routes.
     */
    Route::controller(CustomerGroupController::class)->prefix('groups')->group(function () {
        Route::get('', 'index')->name('admin.customers.groups.index');

        Route::post('create', 'store')->name('admin.customers.groups.store');

        Route::put('edit', 'update')->name('admin.customers.groups.update');

        Route::delete('delete/{id}', 'destroy')->name('admin.customers.groups.delete');
    });

    Route::controller(GDPRController::class)->prefix('gdpr')->group(function () {
        Route::get('', 'index')->name('admin.customers.gdpr.index');

        Route::get('edit/{id}', 'edit')->name('admin.customers.gdpr.edit');

        Route::put('edit/{id}', 'update')->name('admin.customers.gdpr.update');

        Route::delete('delete/{id}', 'delete')->name('admin.customers.gdpr.delete');
    });

    /**
     * 开发者主体管理（v3.4 步骤 9 的平台端侧）。
     *
     * 开发者 = 一行 customers + 一行 developer_profile，所以挂在客户分区下。
     * 在此之前 realname_status 永远停在 pending —— 平台端一个入口都没有。
     */
    Route::controller(DeveloperController::class)->prefix('developers')->group(function () {
        Route::get('', 'index')->name('admin.customers.developers.index');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.customers.developers.show');

        Route::post('{id}/realname/approve', 'approveRealname')->whereNumber('id')->name('admin.customers.developers.realname.approve');
        Route::post('{id}/realname/reject', 'rejectRealname')->whereNumber('id')->name('admin.customers.developers.realname.reject');
        Route::post('{id}/status', 'toggleStatus')->whereNumber('id')->name('admin.customers.developers.status');
    });

    /**
     * 客服工单受理台（v3.4 步骤 10 的平台端侧）。
     *
     * 用户侧开单接口早已存在（`POST /api/tickets`，带 24h SLA），
     * 但平台端此前没有任何入口 —— 工单进了一个没人看的池子。
     */
    Route::controller(SupportTicketController::class)->prefix('tickets')->group(function () {
        Route::get('', 'index')->name('admin.customers.tickets.index');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.customers.tickets.show');

        Route::post('{id}/reply', 'reply')->whereNumber('id')->name('admin.customers.tickets.reply');
        Route::post('{id}/status', 'updateStatus')->whereNumber('id')->name('admin.customers.tickets.status');
        // B-10:定性为事故 —— 工单 → incident 的唯一入口
        Route::post('{id}/escalate', 'escalate')->whereNumber('id')->name('admin.customers.tickets.escalate');
    });

    /**
     * 事故受理台（计划 §4.19）。
     *
     * 技能在真实机器人上物理执行 —— 出事故必须有可追溯的上报链。
     * 用户侧上报接口 POST /api/incidents（证据链在报案时冻结）。
     */
    Route::controller(IncidentController::class)->prefix('incidents')->group(function () {
        Route::get('', 'index')->name('admin.customers.incidents.index');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.customers.incidents.show');
        Route::post('{id}/handle', 'handle')->whereNumber('id')->name('admin.customers.incidents.handle');
    });
});
