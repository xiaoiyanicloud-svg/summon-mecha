<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shop\Http\Controllers\AgentSetupController;
use Webkul\Shop\Http\Controllers\BookingProductController;
use Webkul\Shop\Http\Controllers\CompareController;
use Webkul\Shop\Http\Controllers\EcosystemSsrController;
use Webkul\Shop\Http\Controllers\HomeController;
use Webkul\Shop\Http\Controllers\PageController;
use Webkul\Shop\Http\Controllers\ProductController;
use Webkul\Shop\Http\Controllers\ProductsCategoriesProxyController;
use Webkul\Shop\Http\Controllers\SearchController;
use Webkul\Shop\Http\Controllers\SitemapController;
use Webkul\Shop\Http\Controllers\SubscriptionController;

/**
 * v3.4 步骤 8 · Agent 安装脚本(纯文本 GET,不走 /api 前缀)。
 * 显式路由优先于下方的 fallback,不冲突。
 *
 * ⚠ 路径**故意叫 setup.sh 而不是 install.sh**:Bagisto 的 Installer 包有个全局
 *   `CanInstall` 中间件,对任何含 `/install` 的路径都 `redirect('/admin')`。
 *   叫 install.sh 会被它拦掉。改名不是为了好看,是绕开那个中间件。
 */
Route::controller(AgentSetupController::class)->prefix('agent')->group(function () {
    Route::get('setup.sh', 'installScript')->name('shop.agent.install_script');

    Route::get('platform_public.pem', 'publicKey')->name('shop.agent.public_key');

    Route::get('agent.py', 'agentPy')->name('shop.agent.py');
});

/**
 * CMS pages.
 */
Route::get('page/{slug}', [PageController::class, 'view'])
    ->name('shop.cms.page')
    ->middleware('cache.response');

/**
 * G4-04 公开 SSR：与 uni-app 同 Laravel 服务。
 *
 * 开关在 config/ecosystem_g4.php，默认关闭；关闭时控制器返回 404。
 * 这些路由必须放在 fallback 之前，否则会被商品/分类兜底吞掉。
 */
Route::controller(EcosystemSsrController::class)->group(function () {
    Route::get('events', 'events')->name('shop.ecosystem.events.index');
    Route::get('events/{id}', 'event')->whereNumber('id')->name('shop.ecosystem.events.show');
    Route::get('content', 'contents')->name('shop.ecosystem.content.index');
    Route::get('content/{slug}', 'content')
        ->where('slug', '[A-Za-z0-9_-]{1,191}')
        ->name('shop.ecosystem.content.show');
});

Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('shop.ecosystem.sitemap');

/**
 * Fallback route.
 */
Route::fallback(ProductsCategoriesProxyController::class.'@index')
    ->name('shop.product_or_category.index')
    ->middleware('cache.response');

/**
 * Store front home.
 */
Route::get('/', [HomeController::class, 'index'])
    ->name('shop.home.index')
    ->middleware('cache.response');

Route::get('contact-us', [HomeController::class, 'contactUs'])
    ->name('shop.home.contact_us')
    ->middleware('cache.response');

Route::post('contact-us/send-mail', [HomeController::class, 'sendContactUsMail'])
    ->name('shop.home.contact_us.send_mail')
    ->middleware('cache.response');

/**
 * Store front search.
 */
Route::get('search', [SearchController::class, 'index'])
    ->name('shop.search.index')
    ->middleware('cache.response');

Route::post('search/upload', [SearchController::class, 'upload'])->name('shop.search.upload');

/**
 * Subscription routes.
 */
Route::controller(SubscriptionController::class)->group(function () {
    Route::post('subscription', 'store')->name('shop.subscription.store');

    Route::get('subscription/{token}', 'destroy')->name('shop.subscription.destroy');
});

/**
 * Compare products
 */
Route::get('compare', [CompareController::class, 'index'])
    ->name('shop.compare.index')
    ->middleware('cache.response');

/**
 * Downloadable products
 */
Route::controller(ProductController::class)->group(function () {
    Route::get('downloadable/download-sample/{type}/{id}', 'downloadSample')->name('shop.downloadable.download_sample');

    Route::get('product/{id}/{attribute_id}', 'download')->name('shop.product.file.download');
});

/**
 * Booking products
 */
Route::get('booking-slots/{id}', [BookingProductController::class, 'index'])
    ->name('shop.booking-product.slots.index');
