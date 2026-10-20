<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Catalog\SceneWorkflowController;
use Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow;
use Webkul\Shop\Http\Middleware\SceneWorkflowSafety;

Route::controller(SceneWorkflowController::class)->prefix('scene-workflow')->middleware([AuthorizeSceneWorkflow::class, SceneWorkflowSafety::class])->group(function () {
    Route::get('', 'index')->name('admin.catalog.scene-workflow.index');
    Route::get('approvals', 'index')->name('admin.catalog.scene-workflow.approvals');
    Route::get('revisions', 'index')->name('admin.catalog.scene-workflow.revisions');
    Route::get('revision-approvals', 'index')->name('admin.catalog.scene-workflow.revision-approvals');
    Route::get('publications', 'index')->name('admin.catalog.scene-workflow.publications');
    Route::get('requests/{id}', 'show')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.show');
    Route::get('requests/{id}/contact', 'contact')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.contact');
    Route::get('requests/{id}/attachments/{attachmentId}', 'attachment')->where(['id' => '[1-9][0-9]{0,14}', 'attachmentId' => '[1-9][0-9]{0,14}'])->name('admin.catalog.scene-workflow.attachment');
    Route::post('requests/{id}/attachments/upload', 'upload')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.upload');
    Route::post('requests/{id}/{action}', 'requestAction')->where('id', '[1-9][0-9]{0,14}')->whereIn('action', ['assign', 'contact', 'close'])->name('admin.catalog.scene-workflow.request');
    Route::post('requests/{id}/assessments/create', 'assess')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.assess');
    Route::post('requests/{id}/quotes/create', 'quote')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.quote');
    Route::post('quotes/{id}/{action}', 'quoteAction')->where('id', '[1-9][0-9]{0,14}')->whereIn('action', ['review', 'approve', 'return', 'send', 'void'])->name('admin.catalog.scene-workflow.quote-action');
    Route::post('scenes/{id}/{action}', 'publication')->where('id', '[1-9][0-9]{0,14}')->whereIn('action', ['draft', 'review', 'approve', 'publish', 'withdraw'])->name('admin.catalog.scene-workflow.publication');
    Route::post('supply/{kind}', 'supply')->whereIn('kind', ['prices', 'evidence', 'resources'])->name('admin.catalog.scene-workflow.supply');
    Route::post('quotes/{id}/reservations/hold', 'hold')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.hold');
    Route::post('resources/{id}/pause', 'pause')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.pause');
    Route::post('projects/{id}/tasks/{taskId}', 'task')->where(['id' => '[1-9][0-9]{0,14}', 'taskId' => '[1-9][0-9]{0,14}'])->name('admin.catalog.scene-workflow.task');
    Route::post('outbox/{id}/replay', 'replay')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.replay');
    Route::post('projects/{id}/commercial', 'commercial')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.commercial');
    Route::post('projects/{id}/commercial/{recordId}/review', 'reviewCommercial')->where(['id' => '[1-9][0-9]{0,14}', 'recordId' => '[1-9][0-9]{0,14}'])->name('admin.catalog.scene-workflow.commercial-review');
    Route::post('projects/{id}/return', 'returnResources')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.return');
    Route::post('projects/{id}/offline-acceptances', 'recordOfflineAcceptance')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.offline-record');
    Route::post('projects/{id}/offline-acceptances/{confirmationId}/review', 'reviewOfflineAcceptance')->where(['id' => '[1-9][0-9]{0,14}', 'confirmationId' => '[1-9][0-9]{0,14}'])->name('admin.catalog.scene-workflow.offline-review');
    Route::post('service-tickets/{id}/resolve', 'resolveTicket')->where('id', '[1-9][0-9]{0,14}')->name('admin.catalog.scene-workflow.ticket');
});
