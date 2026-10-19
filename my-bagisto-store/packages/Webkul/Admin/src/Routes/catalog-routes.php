<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Catalog\AttributeController;
use Webkul\Admin\Http\Controllers\Catalog\AttributeFamilyController;
use Webkul\Admin\Http\Controllers\Catalog\CategoryController;
use Webkul\Admin\Http\Controllers\Catalog\ContentSecurityController;
use Webkul\Admin\Http\Controllers\Catalog\DeviceModelController;
use Webkul\Admin\Http\Controllers\Catalog\Ecosystem\PublishingController;
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
use Webkul\Admin\Http\Controllers\Catalog\SkillReviewModerationController;
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
        Route::get('edit/{id}', 'edit')->whereNumber('id')->name('admin.catalog.events.edit');
        Route::post('edit/{id}', 'update')->whereNumber('id')->name('admin.catalog.events.update');
        Route::delete('delete/{id}', 'destroy')->whereNumber('id')->name('admin.catalog.events.delete');
        // 非数字 ID 显式 404。否则会落到 Shop 的 {fallbackPlaceholder} 兜底路由,
        // DELETE 请求在那里会变成 405(该兜底只接受 GET/HEAD)。
        // 参数名用 eventId 是为了避开 Laravel 对「同 method + 同 URI」路由的覆盖。
        Route::get('edit/{eventId}', fn () => abort(404));
        Route::post('edit/{eventId}', fn () => abort(404));
        Route::delete('delete/{eventId}', fn () => abort(404));
        Route::get('registrations', 'registrations')->name('admin.catalog.events.registrations');
    });

    /**
     * 生态发布工作台（G1-08 ~ G1-11 的管理端操作面）。
     *
     * 授权登记在 `config/ecosystem_admin.php`，控制器入口显式执行；
     * `config/acl.php` 中的 `ecosystem.*` 权限 key 供角色分配与菜单使用。
     * 所有 {id}/{revision} 都加 whereNumber，避免非数字命中兜底路由。
     */
    Route::controller(PublishingController::class)->prefix('ecosystem-publishing')->group(function () {
        Route::get('', 'index')->name('admin.catalog.ecosystem.index');

        // 活动
        // 新建入口放在 {id} 之前；{id} 也全部 whereNumber，静态段不会被吞。
        Route::get('events/create', 'createEvent')->name('admin.catalog.ecosystem.events.create');
        Route::post('events', 'storeEvent')->name('admin.catalog.ecosystem.events.store');

        Route::get('events/{id}', 'showEvent')->whereNumber('id')->name('admin.catalog.ecosystem.events.show');
        Route::post('events/{id}/metadata', 'updateEventMetadata')->whereNumber('id')->name('admin.catalog.ecosystem.events.metadata');
        Route::post('events/{id}/revisions', 'storeEventRevision')->whereNumber('id')->name('admin.catalog.ecosystem.events.revisions.store');

        Route::get('events/{id}/revisions/{revision}/preview', 'previewEventRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.preview');
        Route::post('events/{id}/revisions/{revision}/update', 'updateEventRevisionDraft')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.update');
        Route::post('events/{id}/revisions/{revision}/media-refs', 'replaceEventRevisionMediaRefs')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.media_refs.store');
        Route::post('events/{id}/revisions/{revision}/media-refs/{ref}/review', 'reviewEventMediaRef')
            ->whereNumber('id')->whereNumber('revision')->whereNumber('ref')
            ->name('admin.catalog.ecosystem.events.revisions.media_refs.review');
        Route::post('events/{id}/revisions/{revision}/submit', 'submitEventRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.submit');
        Route::post('events/{id}/revisions/{revision}/approve', 'approveEventRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.approve');
        Route::post('events/{id}/revisions/{revision}/reject', 'rejectEventRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.reject');
        Route::post('events/{id}/revisions/{revision}/schedule', 'scheduleEventRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.schedule');
        Route::post('events/{id}/revisions/{revision}/publish', 'publishEventRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.events.revisions.publish');

        Route::post('events/{id}/unschedule', 'unscheduleEvent')->whereNumber('id')->name('admin.catalog.ecosystem.events.unschedule');
        Route::post('events/{id}/withhold', 'withholdEvent')->whereNumber('id')->name('admin.catalog.ecosystem.events.withhold');
        Route::post('events/{id}/restore', 'restoreEvent')->whereNumber('id')->name('admin.catalog.ecosystem.events.restore');
        Route::post('events/{id}/retract', 'retractEvent')->whereNumber('id')->name('admin.catalog.ecosystem.events.retract');

        // 资讯
        Route::get('content', 'contentIndex')->name('admin.catalog.ecosystem.content.index');
        Route::post('content', 'storeContent')->name('admin.catalog.ecosystem.content.store');
        Route::get('content/{id}', 'showContent')->whereNumber('id')->name('admin.catalog.ecosystem.content.show');
        Route::post('content/{id}/metadata', 'updateContentMetadata')->whereNumber('id')->name('admin.catalog.ecosystem.content.metadata');
        Route::post('content/{id}/revisions', 'storeContentRevision')->whereNumber('id')->name('admin.catalog.ecosystem.content.revisions.store');

        Route::get('content/{id}/revisions/{revision}/preview', 'previewContentRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.preview');
        Route::post('content/{id}/revisions/{revision}/update', 'updateContentRevisionDraft')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.update');
        Route::post('content/{id}/revisions/{revision}/media-refs', 'replaceContentRevisionMediaRefs')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.media_refs.store');
        Route::post('content/{id}/revisions/{revision}/media-refs/{ref}/review', 'reviewContentMediaRef')
            ->whereNumber('id')->whereNumber('revision')->whereNumber('ref')
            ->name('admin.catalog.ecosystem.content.revisions.media_refs.review');
        Route::post('content/{id}/revisions/{revision}/submit', 'submitContentRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.submit');
        Route::post('content/{id}/revisions/{revision}/approve', 'approveContentRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.approve');
        Route::post('content/{id}/revisions/{revision}/reject', 'rejectContentRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.reject');
        Route::post('content/{id}/revisions/{revision}/schedule', 'scheduleContentRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.schedule');
        Route::post('content/{id}/revisions/{revision}/publish', 'publishContentRevision')
            ->whereNumber('id')->whereNumber('revision')
            ->name('admin.catalog.ecosystem.content.revisions.publish');

        Route::post('content/{id}/unschedule', 'unscheduleContent')->whereNumber('id')->name('admin.catalog.ecosystem.content.unschedule');
        Route::post('content/{id}/withhold', 'withholdContent')->whereNumber('id')->name('admin.catalog.ecosystem.content.withhold');
        Route::post('content/{id}/restore', 'restoreContent')->whereNumber('id')->name('admin.catalog.ecosystem.content.restore');
        Route::post('content/{id}/retract', 'retractContent')->whereNumber('id')->name('admin.catalog.ecosystem.content.retract');

        // 主办方
        Route::get('organizers', 'organizerIndex')->name('admin.catalog.ecosystem.organizers.index');
        Route::post('organizers', 'storeOrganizer')->name('admin.catalog.ecosystem.organizers.store');
        Route::post('organizers/{id}/verify', 'verifyOrganizer')->whereNumber('id')->name('admin.catalog.ecosystem.organizers.verify');
        Route::post('organizers/{id}/reject', 'rejectOrganizer')->whereNumber('id')->name('admin.catalog.ecosystem.organizers.reject');
        Route::post('organizers/{id}/suspend', 'suspendOrganizer')->whereNumber('id')->name('admin.catalog.ecosystem.organizers.suspend');

        // 媒体工作台（上传 / 审核 / 隔离）。上传与审核权限分离；
        // 列表只输出元数据，任何页面都不出现 storage_key。
        Route::get('media', 'mediaIndex')->name('admin.catalog.ecosystem.media.index');
        Route::get('media/{id}/preview', 'previewMedia')->whereNumber('id')->name('admin.catalog.ecosystem.media.preview');
        Route::post('media', 'storeMedia')->name('admin.catalog.ecosystem.media.store');
        Route::post('media/{id}/approve', 'approveMedia')->whereNumber('id')->name('admin.catalog.ecosystem.media.approve');
        Route::post('media/{id}/reject', 'rejectMedia')->whereNumber('id')->name('admin.catalog.ecosystem.media.reject');
        Route::post('media/{id}/quarantine', 'quarantineMedia')->whereNumber('id')->name('admin.catalog.ecosystem.media.quarantine');
    });

    /**
     * 技能管理后台:技能包的版本 / 交付物 / 安装指引。
     *
     * 与 /api/skill-admin/* 写的是同一批表(Webkul\Shop\Models\SkillVersion),
     * 那条通道面向开发者/CI,这条面向运营手工维护。
     */
    Route::controller(SkillController::class)->prefix('skills')->group(function () {
        Route::get('', 'index')->name('admin.catalog.skills.index');

        // B-17 · 导入台(他平台/动捕技能包 → 中立清单)。静态段放 {productId} 之前
        Route::get('import', 'importForm')->name('admin.catalog.skills.import');
        Route::post('import', 'import')->name('admin.catalog.skills.import.store');

        Route::get('edit/{productId}', 'edit')->name('admin.catalog.skills.edit');

        Route::post('{productId}/versions', 'storeVersion')->name('admin.catalog.skills.versions.store');
        Route::put('versions/{versionId}', 'updateVersion')->name('admin.catalog.skills.versions.update');
        Route::post('versions/{versionId}/assets', 'storeAsset')->name('admin.catalog.skills.assets.store');
        Route::post('versions/{versionId}/publish', 'publish')->name('admin.catalog.skills.versions.publish');
        // S-3b · 商品级「重新上架」：下架停的是商品（status=0），版本仍是 published，
        // 所以复售不能走 publish（状态机没有 published → published）。上架权归运营。
        Route::post('versions/{versionId}/relist', 'relist')->whereNumber('versionId')->name('admin.catalog.skills.versions.relist');
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
     * 评价处置台（加固计划 §四 A1）。
     *
     * ⚠ 与审核台（skill-reviews）只差英文名：审核台审**技能版本准入**（skill_versions），
     * 本台管**用户写的技能评价**（skill_review）。
     */
    Route::controller(SkillReviewModerationController::class)->prefix('skill-review-moderation')->group(function () {
        Route::get('', 'index')->name('admin.catalog.skill-review-moderation.index');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.catalog.skill-review-moderation.show');
        Route::post('{id}/moderate', 'moderate')->whereNumber('id')->name('admin.catalog.skill-review-moderation.moderate');
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
