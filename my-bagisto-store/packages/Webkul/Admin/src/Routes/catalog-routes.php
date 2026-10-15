<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Catalog\AttributeController;
use Webkul\Admin\Http\Controllers\Catalog\AttributeFamilyController;
use Webkul\Admin\Http\Controllers\Catalog\CategoryController;
use Webkul\Admin\Http\Controllers\Catalog\ContentSecurityController;
use Webkul\Admin\Http\Controllers\Catalog\DeviceModelController;
use Webkul\Admin\Http\Controllers\Catalog\EventController;
use Webkul\Admin\Http\Controllers\Catalog\Product\BundleController;
use Webkul\Admin\Http\Controllers\Catalog\Product\ConfigurableController;
use Webkul\Admin\Http\Controllers\Catalog\Product\DownloadableController;
use Webkul\Admin\Http\Controllers\Catalog\Product\GroupedController;
use Webkul\Admin\Http\Controllers\Catalog\Product\SimpleController;
use Webkul\Admin\Http\Controllers\Catalog\Product\VirtualController;
use Webkul\Admin\Http\Controllers\Catalog\ProductController;
use Webkul\Admin\Http\Controllers\Catalog\SceneController;
use Webkul\Admin\Http\Controllers\Catalog\SkillCategoryController;
use Webkul\Admin\Http\Controllers\Catalog\SkillController;
use Webkul\Admin\Http\Controllers\Catalog\SkillReportController;
use Webkul\Admin\Http\Controllers\Catalog\SkillReviewController;
use Webkul\Admin\Http\Controllers\Catalog\SkillTagController;

/**
 * Catalog routes.
 */
Route::prefix('catalog')->group(function () {
    /**
     * Attributes routes.
     */
    Route::controller(AttributeController::class)->prefix('attributes')->group(function () {
        Route::get('', 'index')->name('admin.catalog.attributes.index');

        Route::get('{id}/options', 'getAttributeOptions')->name('admin.catalog.attributes.options');

        Route::get('create', 'create')->name('admin.catalog.attributes.create');

        Route::post('create', 'store')->name('admin.catalog.attributes.store');

        Route::get('edit/{id}', 'edit')->name('admin.catalog.attributes.edit');

        Route::put('edit/{id}', 'update')->name('admin.catalog.attributes.update');

        Route::delete('edit/{id}', 'destroy')->name('admin.catalog.attributes.delete');

        Route::post('mass-delete', 'massDestroy')->name('admin.catalog.attributes.mass_delete');
    });

    /**
     * Attribute families routes.
     */
    Route::controller(AttributeFamilyController::class)->prefix('families')->group(function () {
        Route::get('', 'index')->name('admin.catalog.families.index');

        Route::get('create', 'create')->name('admin.catalog.families.create');

        Route::post('create', 'store')->name('admin.catalog.families.store');

        Route::get('edit/{id}', 'edit')->name('admin.catalog.families.edit');

        Route::put('edit/{id}', 'update')->name('admin.catalog.families.update');

        Route::delete('edit/{id}', 'destroy')->name('admin.catalog.families.delete');
    });

    /**
     * Categories routes.
     */
    Route::controller(CategoryController::class)->prefix('categories')->group(function () {
        Route::get('', 'index')->name('admin.catalog.categories.index');

        Route::get('create', 'create')->name('admin.catalog.categories.create');

        Route::post('create', 'store')->name('admin.catalog.categories.store');

        Route::get('edit/{id}', 'edit')->name('admin.catalog.categories.edit');

        Route::put('edit/{id}', 'update')->name('admin.catalog.categories.update');

        Route::delete('edit/{id}', 'destroy')->name('admin.catalog.categories.delete');

        Route::post('mass-delete', 'massDestroy')->name('admin.catalog.categories.mass_delete');

        Route::post('mass-update', 'massUpdate')->name('admin.catalog.categories.mass_update');

        Route::get('search', 'search')->name('admin.catalog.categories.search');

        Route::get('tree', 'tree')->name('admin.catalog.categories.tree');
    });

    /**
     * Scenes routes.
     */
    Route::controller(SceneController::class)->prefix('scenes')->group(function () {
        Route::get('', 'index')->name('admin.catalog.scenes.index');

        Route::get('create', 'create')->name('admin.catalog.scenes.create');

        Route::post('create', 'store')->name('admin.catalog.scenes.store');

        Route::get('edit/{id}', 'edit')->name('admin.catalog.scenes.edit');

        Route::put('edit/{id}', 'update')->name('admin.catalog.scenes.update');

        Route::delete('edit/{id}', 'destroy')->name('admin.catalog.scenes.delete');

        Route::post('status/{id}', 'updateStatus')->name('admin.catalog.scenes.status');

        Route::post('hot/{id}', 'toggleHot')->name('admin.catalog.scenes.hot');

        Route::post('consult-count/{id}', 'updateConsultCount')->name('admin.catalog.scenes.consult-count');

        Route::post('mass-update', 'massUpdate')->name('admin.catalog.scenes.mass_update');

        Route::post('mass-delete', 'massDestroy')->name('admin.catalog.scenes.mass_delete');
    });

    // 赛事(Event)后台管理:紧凑 CRUD + 报名列表(平台级闭环)
    Route::controller(EventController::class)->prefix('events')->group(function () {
        Route::get('', 'index')->name('admin.catalog.events.index');
        Route::get('create', 'create')->name('admin.catalog.events.create');
        Route::post('', 'store')->name('admin.catalog.events.store');
        Route::get('edit/{id}', 'edit')->name('admin.catalog.events.edit');
        Route::post('edit/{id}', 'update')->name('admin.catalog.events.update');
        Route::delete('delete/{id}', 'destroy')->name('admin.catalog.events.delete');
        Route::get('registrations', 'registrations')->name('admin.catalog.events.registrations');
    });

    /**
     * 技能管理后台:技能包的版本 / 交付物 / 安装指引。
     *
     * 与 /api/skill-admin/* 写的是同一批表(Webkul\Shop\Models\SkillVersion),
     * 那条通道面向开发者/CI,这条面向运营手工维护。
     */
    Route::controller(SkillController::class)->prefix('skills')->group(function () {
        Route::get('', 'index')->name('admin.catalog.skills.index');
        Route::get('edit/{productId}', 'edit')->name('admin.catalog.skills.edit');

        Route::post('{productId}/versions', 'storeVersion')->name('admin.catalog.skills.versions.store');
        Route::put('versions/{versionId}', 'updateVersion')->name('admin.catalog.skills.versions.update');
        Route::post('versions/{versionId}/assets', 'storeAsset')->name('admin.catalog.skills.assets.store');
        Route::post('versions/{versionId}/publish', 'publish')->name('admin.catalog.skills.versions.publish');
        Route::delete('versions/{versionId}', 'deleteVersion')->name('admin.catalog.skills.versions.delete');
    });

    /**
     * 审核台：技能准入的人工端。
     *
     * 与 /api/skill-supply/*（internal.key，CI/合作方通道）判的是同一批审核记录，
     * 差别在于这里的 auditor 取自**登录的 Admin 身份**，不是请求体里填的。
     */
    Route::controller(SkillReviewController::class)->prefix('skill-reviews')->group(function () {
        Route::get('', 'index')->name('admin.catalog.skill-reviews.index');
        Route::get('{versionId}', 'show')->whereNumber('versionId')->name('admin.catalog.skill-reviews.show');

        Route::post('{versionId}/judge', 'judge')->whereNumber('versionId')->name('admin.catalog.skill-reviews.judge');
        Route::post('{versionId}/layer0', 'runLayer0')->whereNumber('versionId')->name('admin.catalog.skill-reviews.layer0');
    });

    /**
     * 机型库（v3.4 步骤 5）—— 兼容性校验的输入。
     * 在这之前只能靠 DeviceModelSeeder / 手写 SQL 维护。
     */
    Route::controller(DeviceModelController::class)->prefix('device-models')->group(function () {
        Route::get('', 'index')->name('admin.catalog.device-models.index');
        Route::get('create', 'create')->name('admin.catalog.device-models.create');
        Route::post('', 'store')->name('admin.catalog.device-models.store');

        // 静态段必须在 {id} 之前，否则 create 会被当成 id
        Route::get('edit/{id}', 'edit')->whereNumber('id')->name('admin.catalog.device-models.edit');
        Route::put('{id}', 'update')->whereNumber('id')->name('admin.catalog.device-models.update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('admin.catalog.device-models.delete');
    });

    /**
     * 技能类目（技能市场的品类，**不是** Bagisto 商品分类）。
     * 在这之前只能靠 SkillCatalogSeeder 维护。
     */
    Route::controller(SkillCategoryController::class)->prefix('skill-categories')->group(function () {
        Route::get('', 'index')->name('admin.catalog.skill-categories.index');
        Route::get('create', 'create')->name('admin.catalog.skill-categories.create');
        Route::post('', 'store')->name('admin.catalog.skill-categories.store');

        Route::get('edit/{id}', 'edit')->whereNumber('id')->name('admin.catalog.skill-categories.edit');
        Route::put('{id}', 'update')->whereNumber('id')->name('admin.catalog.skill-categories.update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('admin.catalog.skill-categories.delete');
    });

    /**
     * 技能标签（市场筛选维度：动作形态 / 场景 / 难度）。
     */
    Route::controller(SkillTagController::class)->prefix('skill-tags')->group(function () {
        Route::get('', 'index')->name('admin.catalog.skill-tags.index');
        Route::get('create', 'create')->name('admin.catalog.skill-tags.create');
        Route::post('', 'store')->name('admin.catalog.skill-tags.store');

        Route::get('edit/{id}', 'edit')->whereNumber('id')->name('admin.catalog.skill-tags.edit');
        Route::put('{id}', 'update')->whereNumber('id')->name('admin.catalog.skill-tags.update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('admin.catalog.skill-tags.delete');
    });

    /**
     * 内容安全处置台（计划 §4.16）。
     * 扫描明细由 ContentScanRecorder 自动落库；这里是人来判断的那半截。
     */
    Route::controller(ContentSecurityController::class)->prefix('content-security')->group(function () {
        Route::get('', 'index')->name('admin.catalog.content-security.index');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.catalog.content-security.show');
        Route::post('{id}/dispose', 'dispose')->whereNumber('id')->name('admin.catalog.content-security.dispose');
    });

    /**
     * 举报处置台（加固计划 §四 A1）。
     *
     * 与审核台（skill-reviews）不是一回事：审核台审的是**技能版本准入**（skill_versions），
     * 本台处置的是**用户举报**（skill_report）—— 技能上线之后唯一的用户侧违规通道。
     */
    Route::controller(SkillReportController::class)->prefix('skill-reports')->group(function () {
        Route::get('', 'index')->name('admin.catalog.skill-reports.index');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.catalog.skill-reports.show');
        Route::post('{id}/dispose', 'dispose')->whereNumber('id')->name('admin.catalog.skill-reports.dispose');
    });

    /**
     * Sync route.
     */
    Route::get('/sync', [ProductController::class, 'sync']);

    /**
     * Products routes.
     */
    Route::controller(ProductController::class)->prefix('products')->group(function () {
        Route::get('', 'index')->name('admin.catalog.products.index');

        Route::post('create', 'store')->name('admin.catalog.products.store');

        Route::post('copy/{id}', 'copy')->name('admin.catalog.products.copy');

        Route::get('edit/{id}', 'edit')->name('admin.catalog.products.edit');

        Route::put('edit/{id}', 'update')->name('admin.catalog.products.update');

        Route::delete('edit/{id}', 'destroy')->name('admin.catalog.products.delete');

        Route::put('edit/{id}/inventories', 'updateInventories')->name('admin.catalog.products.update_inventories');

        Route::post('upload-file/{id}', 'uploadLink')->name('admin.catalog.products.upload_link');

        Route::post('upload-sample/{id}', 'uploadSample')->name('admin.catalog.products.upload_sample');

        Route::post('mass-update', 'massUpdate')->name('admin.catalog.products.mass_update');

        Route::post('mass-delete', 'massDestroy')->name('admin.catalog.products.mass_delete');

        Route::controller(SimpleController::class)->group(function () {
            Route::get('{id}/simple-customizable-options', 'customizableOptions')->name('admin.catalog.products.simple.customizable-options');
        });

        Route::controller(ConfigurableController::class)->group(function () {
            Route::get('{id}/configurable-options', 'options')->name('admin.catalog.products.configurable.options');
        });

        Route::controller(BundleController::class)->group(function () {
            Route::get('{id}/bundle-options', 'options')->name('admin.catalog.products.bundle.options');
        });

        Route::controller(GroupedController::class)->group(function () {
            Route::get('{id}/grouped-options', 'options')->name('admin.catalog.products.grouped.options');
        });

        Route::controller(DownloadableController::class)->group(function () {
            Route::get('{id}/downloadable-options', 'options')->name('admin.catalog.products.downloadable.options');
        });

        Route::controller(VirtualController::class)->group(function () {
            Route::get('{id}/virtual-customizable-options', 'customizableOptions')->name('admin.catalog.products.virtual.customizable-options');
        });

        Route::get('search', 'search')->name('admin.catalog.products.search');

        Route::get('{id}/{attribute_id}', 'download')->name('admin.catalog.products.file.download');
    });
});
