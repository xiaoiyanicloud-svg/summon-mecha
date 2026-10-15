<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\DashboardController;
use Webkul\Admin\Http\Controllers\DataGrid\DataGridController;
use Webkul\Admin\Http\Controllers\DataGrid\SavedFilterController;
use Webkul\Admin\Http\Controllers\MagicAIController;
use Webkul\Admin\Http\Controllers\Ops\AfterSaleController;
use Webkul\Admin\Http\Controllers\Ops\AlertController;
use Webkul\Admin\Http\Controllers\Ops\InvoiceController;
use Webkul\Admin\Http\Controllers\Ops\OverviewController;
use Webkul\Admin\Http\Controllers\TinyMCEController;
use Webkul\Admin\Http\Controllers\User\AccountController;
use Webkul\Admin\Http\Controllers\User\SessionController;

/**
 * Dashboard routes.
 */
Route::controller(DashboardController::class)->prefix('dashboard')->group(function () {
    Route::get('', 'index')->name('admin.dashboard.index');

    Route::get('stats', 'stats')->name('admin.dashboard.stats');
});

/**
 * 平台告警收件箱（定时任务产出的待办）。
 *
 * 挂在 dashboard 下的原因：它是运营每天开工第一眼要看的东西，
 * 与仪表盘是同一类"总览"界面。Bagisto 的通知铃铛用不了 ——
 * 那张 `notifications` 表是订单专用的（核心列是 order_id）。
 */
Route::controller(AlertController::class)->prefix('alerts')->group(function () {
    Route::get('', 'index')->name('admin.alerts.index');
    // ⚠ 只有「标记已读」，没有「删除」—— 告警是系统产出的事实记录
    Route::post('{id}/read', 'read')->whereNumber('id')->name('admin.alerts.read');
    Route::post('read-all', 'readAll')->name('admin.alerts.read-all');
});

/**
 * 运营总览（技能平台口径的只读统计）。
 * 与原生仪表盘（电商口径）并存，不替代它。
 */
Route::controller(OverviewController::class)->prefix('dashboard')->group(function () {
    Route::get('ops', 'index')->name('admin.dashboard.ops');
});

/**
 * 开票登记台账（M9）。
 *
 * ⚠ 与原生 `admin.sales.invoices.*`（Bagisto 的订单发货单，表 `invoices`）**不是一回事**：
 *   那张表是电商口径的"订单已开票"，本页是中国的税务发票/开票登记（表 `invoice`）。
 *   路由名刻意不挂在 `sales.` 下，免得两个"发票"在同一个菜单里撞车。
 */
Route::controller(InvoiceController::class)->prefix('invoices')->group(function () {
    // 只读：没有"标记为已开具"的动作（未接入开票服务商，见控制器注释）
    Route::get('', 'index')->name('admin.invoices.index');
});

/**
 * 售后/退款审批台（B-1）。
 *
 * 用户侧申请端早就通了（`POST /api/after-sale`），此前缺的是平台端审批入口 ——
 * `AfterSaleService::approve()` 全仓零调用方，用户点完退款就永远停在"已受理"。
 *
 * ⚠ `approve` 是**资金动作**（会调退款网关），走 POST + 详情页二次确认；
 *   服务端的状态守卫/行锁/金额校验全在 `AfterSaleService` 内。
 */
Route::controller(AfterSaleController::class)->prefix('after-sales')->group(function () {
    Route::get('', 'index')->name('admin.after-sales.index');
    Route::get('{id}', 'show')->whereNumber('id')->name('admin.after-sales.show');
    Route::post('{id}/approve', 'approve')->whereNumber('id')->name('admin.after-sales.approve');
    Route::post('{id}/reject', 'reject')->whereNumber('id')->name('admin.after-sales.reject');
});

/**
 * Datagrid routes.
 */
Route::controller(DataGridController::class)->prefix('datagrid')->group(function () {
    Route::get('look-up', 'lookUp')->name('admin.datagrid.look_up');

    Route::controller(SavedFilterController::class)->prefix('saved-filters')->group(function () {
        Route::post('', 'store')->name('admin.datagrid.saved_filters.store');

        Route::get('', 'get')->name('admin.datagrid.saved_filters.index');

        Route::put('{id}', 'update')->name('admin.datagrid.saved_filters.update');

        Route::delete('{id}', 'destroy')->name('admin.datagrid.saved_filters.destroy');
    });
});

/**
 * Tinymce file upload handler.
 */
Route::post('tinymce/upload', [TinyMCEController::class, 'upload'])->name('admin.tinymce.upload');

/**
 * AI Routes
 */
Route::controller(MagicAIController::class)->prefix('magic-ai')->group(function () {
    Route::post('content', 'content')->name('admin.magic_ai.content');

    Route::post('image', 'image')->name('admin.magic_ai.image');
});

/**
 * Admin profile routes.
 */
Route::controller(AccountController::class)->prefix('account')->group(function () {
    Route::get('', 'edit')->name('admin.account.edit');

    Route::put('', 'update')->name('admin.account.update');
});

Route::delete('logout', [SessionController::class, 'destroy'])->name('admin.session.destroy');
