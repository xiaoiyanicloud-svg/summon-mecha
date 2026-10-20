<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Catalog\AttributeController;
use Webkul\Admin\Http\Controllers\Catalog\AttributeFamilyController;
use Webkul\Admin\Http\Controllers\Catalog\CategoryController;
use Webkul\Admin\Http\Controllers\Catalog\CompetitionAdminController;
use Webkul\Admin\Http\Controllers\Catalog\CompetitionAppealAdminController;
use Webkul\Admin\Http\Controllers\Catalog\CompetitionAwardAdminController;
use Webkul\Admin\Http\Controllers\Catalog\CompetitionResultAdminController;
use Webkul\Admin\Http\Controllers\Catalog\CompetitionShowcaseAdminController;
use Webkul\Admin\Http\Controllers\Catalog\ContentSecurityController;
use Webkul\Admin\Http\Controllers\Catalog\DeviceModelController;
use Webkul\Admin\Http\Controllers\Catalog\Ecosystem\EcosystemMetricsAdminController;
use Webkul\Admin\Http\Controllers\Catalog\Ecosystem\EcosystemOpsAdminController;
use Webkul\Admin\Http\Controllers\Catalog\Ecosystem\PublishingController;
use Webkul\Admin\Http\Controllers\Catalog\Ecosystem\RegistrationAdminController;
use Webkul\Admin\Http\Controllers\Catalog\Ecosystem\RelationAdminController;
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
    require __DIR__.'/scene-workflow-routes.php';
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
    Route::controller(SceneController::class)->prefix('scenes')->middleware(\Webkul\Admin\Http\Middleware\AuthorizeSceneAction::class)->group(function () {
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
     * G2 报名运营工作台（G2-01 / G2-14）。
     *
     * 与 G1 发布工作台同一权限模式：路由 → 权限登记在
     * `config/ecosystem_admin.php`；控制器入口显式授权；活动范围另由
     * `event_staff_assignments` 校验。
     */
    Route::controller(RegistrationAdminController::class)->prefix('ecosystem-publishing')->group(function () {
        Route::get('events/{id}/registration-scope', 'scope')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.scope');
        Route::post('events/{id}/registration-scope', 'storeScope')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.scope.store');
        Route::post('events/{id}/registration-scope/update', 'updateScope')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.scope.update');
        Route::post('events/{id}/registration-scope/activate', 'activateScope')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.scope.activate');
        Route::post('events/{id}/registration-scope/close', 'closeScope')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.scope.close');
        Route::post('events/{id}/registration-scope/reopen', 'reopenScope')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.scope.reopen');
        Route::post('events/{id}/registration-scope/capacity', 'capacity')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.scope.capacity');

        Route::get('events/{id}/staff', 'staff')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.staff');
        Route::post('events/{id}/staff', 'grantStaff')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.staff.grant');
        Route::post('events/{id}/staff/{adminId}/revoke', 'revokeStaff')
            ->whereNumber('id')->whereNumber('adminId')->name('admin.catalog.ecosystem.registrations.staff.revoke');

        // 报名工作台：列表 / 详情 / 审核 / 取消。全部按活动范围校验。
        Route::get('events/{id}/registrations', 'registrations')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.index');
        Route::get('events/{id}/registrations/{attemptId}', 'showRegistration')
            ->whereNumber('id')->whereNumber('attemptId')->name('admin.catalog.ecosystem.registrations.show');
        Route::post('events/{id}/registrations/{attemptId}/approve', 'approveRegistration')
            ->whereNumber('id')->whereNumber('attemptId')->name('admin.catalog.ecosystem.registrations.approve');
        Route::post('events/{id}/registrations/{attemptId}/reject', 'rejectRegistration')
            ->whereNumber('id')->whereNumber('attemptId')->name('admin.catalog.ecosystem.registrations.reject');
        Route::post('events/{id}/registrations/{attemptId}/cancel', 'cancelRegistration')
            ->whereNumber('id')->whereNumber('attemptId')->name('admin.catalog.ecosystem.registrations.cancel');

        Route::get('events/{id}/checkin', 'checkinForm')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.checkin');
        Route::post('events/{id}/checkin', 'checkin')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.checkin.store');

        Route::post('events/{id}/registration-changes/cancel', 'cancelEvent')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.changes.cancel');
        Route::post('events/{id}/registration-changes/reschedule', 'recordReschedule')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.changes.reschedule');

        Route::post('events/{id}/registrations/export', 'export')
            ->whereNumber('id')->name('admin.catalog.ecosystem.registrations.export');
        Route::get('registrations/exports/{token}', 'downloadExport')
            ->where('token', '[A-Za-z0-9_-]{20,100}')
            ->name('admin.catalog.ecosystem.registrations.export.download');
    });

    /**
     * G4-03 关联维护。目标只允许真实存在的活动/资讯/机型/技能/场景；
     * 公开端解析时再逐项复核，失效目标不输出。
     */
    Route::controller(RelationAdminController::class)->prefix('ecosystem-relations')->group(function () {
        Route::get('events/{id}', 'eventRelations')->whereNumber('id')
            ->name('admin.catalog.ecosystem.relations.events.index');
        Route::post('events/{id}', 'storeEventRelation')->whereNumber('id')
            ->name('admin.catalog.ecosystem.relations.events.store');
        Route::post('events/{id}/{relationId}/delete', 'destroyEventRelation')
            ->whereNumber('id')->whereNumber('relationId')
            ->name('admin.catalog.ecosystem.relations.events.destroy');

        Route::get('content/{id}', 'contentRelations')->whereNumber('id')
            ->name('admin.catalog.ecosystem.relations.content.index');
        Route::post('content/{id}', 'storeContentRelation')->whereNumber('id')
            ->name('admin.catalog.ecosystem.relations.content.store');
        Route::post('content/{id}/{relationId}/delete', 'destroyContentRelation')
            ->whereNumber('id')->whereNumber('relationId')
            ->name('admin.catalog.ecosystem.relations.content.destroy');
    });

    /**
     * G4-06 / G4-07 运营指标与运营工作台。
     */
    Route::get('ecosystem-metrics', [EcosystemMetricsAdminController::class, 'index'])
        ->name('admin.catalog.ecosystem.metrics.index');

    Route::controller(EcosystemOpsAdminController::class)->prefix('ecosystem-ops')->group(function () {
        Route::get('', 'index')->name('admin.catalog.ecosystem.ops.index');
        Route::post('outbox/{id}/retry', 'retryOutbox')
            ->whereNumber('id')
            ->name('admin.catalog.ecosystem.ops.outbox.retry');
    });

    /**
     * G3 赛务后台（G3-02 / G3-04 / G3-06）。
     *
     * 所有写入口仍受 `ecosystem_competition.write_enabled` 控制；
     * 路由 → 权限登记在 `config/ecosystem_admin.php`。
     */
    Route::controller(CompetitionAdminController::class)->prefix('ecosystem-competition')->group(function () {
        Route::get('', 'index')->name('admin.catalog.ecosystem.competition.index');
        Route::post('series', 'storeSeries')->name('admin.catalog.ecosystem.competition.series.store');
        Route::post('editions', 'storeEdition')->name('admin.catalog.ecosystem.competition.editions.store');
        Route::post('tracks', 'storeTrack')->name('admin.catalog.ecosystem.competition.tracks.store');
        Route::post('tracks/{id}/rules', 'confirmRules')->whereNumber('id')->name('admin.catalog.ecosystem.competition.tracks.rules');
        Route::post('tracks/{id}/activate', 'activateTrack')->whereNumber('id')->name('admin.catalog.ecosystem.competition.tracks.activate');
        Route::post('tracks/{id}/close', 'closeTrack')->whereNumber('id')->name('admin.catalog.ecosystem.competition.tracks.close');
        Route::post('tracks/{id}/scope/activate', 'activateScope')->whereNumber('id')->name('admin.catalog.ecosystem.competition.tracks.scope.activate');
        Route::post('tracks/{id}/scope/close', 'closeScope')->whereNumber('id')->name('admin.catalog.ecosystem.competition.tracks.scope.close');
        Route::get('tracks/{id}/schedule', 'schedule')->whereNumber('id')->name('admin.catalog.ecosystem.competition.schedule');
        Route::post('tracks/{id}/schedule/preview', 'previewImport')->whereNumber('id')->name('admin.catalog.ecosystem.competition.schedule.preview');
        Route::post('tracks/{id}/schedule/import', 'importSchedule')->whereNumber('id')->name('admin.catalog.ecosystem.competition.schedule.import');
        Route::post('stages/{id}/publish', 'publishStage')->whereNumber('id')->name('admin.catalog.ecosystem.competition.stages.publish');
    });

    /**
     * G3-08 成绩后台（录入 / 复核 / 发布分权）。
     */
    Route::controller(CompetitionResultAdminController::class)->prefix('ecosystem-competition')->group(function () {
        Route::get('tracks/{id}/results', 'index')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results');
        Route::post('tracks/{id}/results/draft', 'saveDraft')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results.draft');
        Route::post('results/{id}/submit', 'submit')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results.submit');
        Route::get('tracks/{id}/results/ranking-preview', 'previewRanking')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results.preview_ranking');
        Route::post('tracks/{id}/results/ranking', 'applyRanking')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results.ranking');
        Route::post('tracks/{id}/results/publish', 'publishProvisional')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results.publish');
        Route::post('tracks/{id}/results/finalize', 'finalize')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results.finalize');
        Route::post('results/{id}/revise', 'revise')->whereNumber('id')->name('admin.catalog.ecosystem.competition.results.revise');
    });

    /**
     * G3-09 申诉后台（受理/调查/裁决）。
     */
    Route::controller(CompetitionAppealAdminController::class)->prefix('ecosystem-competition')->group(function () {
        Route::get('tracks/{id}/appeals', 'index')->whereNumber('id')->name('admin.catalog.ecosystem.competition.appeals');
        Route::post('appeals/{id}/accept', 'accept')->whereNumber('id')->name('admin.catalog.ecosystem.competition.appeals.accept');
        Route::post('appeals/{id}/decide', 'decide')->whereNumber('id')->name('admin.catalog.ecosystem.competition.appeals.decide');
        Route::get('appeal-attachments/{id}', 'downloadAttachment')->whereNumber('id')->name('admin.catalog.ecosystem.competition.appeal_attachments.download');
    });

    /**
     * G3-10 奖项后台（版本/发布/撤销）。
     */
    Route::controller(CompetitionAwardAdminController::class)->prefix('ecosystem-competition')->group(function () {
        Route::get('tracks/{id}/awards', 'index')->whereNumber('id')->name('admin.catalog.ecosystem.competition.awards');
        Route::post('tracks/{id}/awards/versions', 'storeVersion')->whereNumber('id')->name('admin.catalog.ecosystem.competition.awards.version');
        Route::post('awards/{id}/publish', 'publish')->whereNumber('id')->name('admin.catalog.ecosystem.competition.awards.publish');
        Route::post('awards/{id}/revoke', 'revoke')->whereNumber('id')->name('admin.catalog.ecosystem.competition.awards.revoke');
    });

    /**
     * G3-11 作品展示后台（创建/审核/发布/撤回）。
     */
    Route::controller(CompetitionShowcaseAdminController::class)->prefix('ecosystem-competition')->group(function () {
        Route::get('tracks/{id}/showcases', 'index')->whereNumber('id')->name('admin.catalog.ecosystem.competition.showcases');
        Route::post('tracks/{id}/showcases', 'store')->whereNumber('id')->name('admin.catalog.ecosystem.competition.showcases.store');
        Route::post('showcases/{id}/approve', 'approve')->whereNumber('id')->name('admin.catalog.ecosystem.competition.showcases.review');
        Route::post('showcases/{id}/reject', 'reject')->whereNumber('id')->name('admin.catalog.ecosystem.competition.showcases.reject');
        Route::post('showcases/{id}/publish', 'publish')->whereNumber('id')->name('admin.catalog.ecosystem.competition.showcases.publish');
        Route::post('showcases/{id}/withdraw', 'withdraw')->whereNumber('id')->name('admin.catalog.ecosystem.competition.showcases.withdraw');
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
