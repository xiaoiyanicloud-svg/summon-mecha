<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shop\Http\Controllers\Dev\OnboardingController;
use Webkul\Shop\Http\Controllers\Dev\PortalController;
use Webkul\Shop\Http\Controllers\Dev\SkillController;

/**
 * 开发者门户（Blade SSR）—— v3.4 步骤 9。
 *
 * 本文件经 `web.php` 加载，外层已带 `['web','shop',PreventRequestsDuringMaintenance]`
 * （与 store-front / customer / checkout 同一组）。
 *
 * ⚠ 三个刻意的选择，改动前先读：
 *   1. 路径前缀是 `dev`，**不含 `/install`** —— Bagisto 的 CanInstall 中间件
 *      （全局挂在 web 组）会把任何含 `/install` 的路径 redirect 到 /admin。
 *   2. 鉴权用 `developer` / `developer.session`，**不用 `customer`** ——
 *      AuthenticateCustomer 未登录会 redirect('/admin')，对开发者门户是错的落点。
 *   3. 表单 POST 走这里（web 组，CSRF 生效）；`/api/dev/*` 只认 Bearer token。
 *      详见 ResolveDeveloper 的注释。
 */

/**
 * 前缀取自配置（`DEV_PORTAL_PREFIX`）——将来接独立域名时靠改它 + 路由加载方式切换，
 * 业务代码不感知（它只认路由名 `shop.dev.*`）。
 *
 * ⚠ 兜底成 'dev' 而不是空串：空前缀会让门户路由直接落在网站根路径上，
 *   与店面路由（首页、商品页）抢匹配 —— 那是把门户挂到店面门口。
 */
$devPrefix = trim((string) config('developer_portal.prefix'), '/') ?: 'dev';

Route::controller(PortalController::class)->prefix($devPrefix)->group(function () {
    Route::get('login', 'showLogin')->name('shop.dev.login');
    Route::post('login', 'login')->name('shop.dev.login.attempt');
});

/**
 * 只要登录（还没有 developer_profile 也要能进）—— 入驻与总览。
 * 若这里用 `developer`，未入驻用户会在入驻页与鉴权之间互相弹跳成死循环。
 */
Route::controller(PortalController::class)->prefix($devPrefix)->middleware('developer.session')->group(function () {
    Route::post('logout', 'logout')->name('shop.dev.logout');
    Route::get('', 'dashboard')->name('shop.dev.dashboard');
});

Route::controller(OnboardingController::class)->prefix($devPrefix)->middleware('developer.session')->group(function () {
    Route::get('onboarding', 'form')->name('shop.dev.onboarding');
    Route::post('onboarding', 'store')->name('shop.dev.onboarding.store');
});

/**
 * 以下都要求"已入驻且状态正常"（developer 中间件会放 developer_profile 进 request attributes）。
 */
Route::controller(OnboardingController::class)->prefix($devPrefix)->middleware('developer')->group(function () {
    Route::get('onboarding/agreements', 'agreements')->name('shop.dev.agreements');
    Route::post('onboarding/agreements', 'signAgreements')->name('shop.dev.agreements.sign');
});

Route::controller(SkillController::class)->prefix($devPrefix)->middleware('developer')->group(function () {
    Route::get('skills', 'index')->name('shop.dev.skills.index');

    // 注意顺序：静态段必须在 {id} 之前，否则 create 会被当成 id
    Route::get('skills/create', 'create')->name('shop.dev.skills.create');
    Route::post('skills', 'store')->name('shop.dev.skills.store');
    Route::get('skills/{id}', 'show')->whereNumber('id')->name('shop.dev.skills.show');

    // 改信息走独立的两条：edit 渲染表单（GET），update 落库（PUT）。
    // update 与 show 共用 `skills/{id}` 路径、靠 HTTP 方法区分；whereNumber 保证
    // 静态段（create / 数字外的 id）不会被当作 id 抢匹配。
    Route::get('skills/{id}/edit', 'edit')->whereNumber('id')->name('shop.dev.skills.edit');
    Route::put('skills/{id}', 'update')->whereNumber('id')->name('shop.dev.skills.update');

    // 下架：影响买家可见性的动作，理由必填、留痕。**刻意没有 online 入口**（见控制器注释）。
    Route::post('skills/{id}/offline', 'offline')->whereNumber('id')->name('shop.dev.skills.offline');

    Route::post('skills/{id}/versions', 'storeVersion')->whereNumber('id')->name('shop.dev.skills.versions.store');

    Route::post('versions/{versionId}/upload', 'upload')->whereNumber('versionId')->name('shop.dev.versions.upload');
    Route::post('versions/{versionId}/submit', 'submit')->whereNumber('versionId')->name('shop.dev.versions.submit');
    Route::post('versions/{versionId}/withdraw', 'withdraw')->whereNumber('versionId')->name('shop.dev.versions.withdraw');

    Route::get('audits', 'audits')->name('shop.dev.audits.index');
});
