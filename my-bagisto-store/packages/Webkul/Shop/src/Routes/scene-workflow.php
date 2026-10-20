<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shop\Http\Controllers\API\SceneWorkflowController;
use Webkul\Shop\Http\Middleware\SceneWorkflowSafety;

// 由 api.php 引入，继承 web/shop/api.customer；能力凭证不进入 URL。
Route::controller(SceneWorkflowController::class)->middleware([SceneWorkflowSafety::class])->group(function () {
    Route::get('scene-workflow/capabilities', 'capabilities')->name('shop.api.scene-workflow.capabilities');
    Route::post('scene-workflow/access', 'access')->middleware('throttle:write')->name('shop.api.scene-workflow.access');
    Route::get('scene-workflow/schemas/{category}', 'schema')->name('shop.api.scene-workflow.schema');
    Route::get('scene-requests', 'index')->name('shop.api.scene-requests.index');
    Route::post('scene-requests', 'store')->middleware('throttle:write')->name('shop.api.scene-requests.store');
    Route::get('scene-requests/{id}', 'show')->where('id', '[1-9][0-9]{0,14}')->name('shop.api.scene-requests.show');
    Route::get('scene-requests/{id}/quote-comparison', 'compare')->where('id', '[1-9][0-9]{0,14}')->name('shop.api.scene-requests.compare');
    Route::post('scene-requests/{id}/supplements', 'supplement')->where('id', '[1-9][0-9]{0,14}')->middleware('throttle:write')->name('shop.api.scene-requests.supplement');
    Route::post('scene-requests/{id}/claim', 'claim')->where('id', '[1-9][0-9]{0,14}')->middleware('throttle:write')->name('shop.api.scene-requests.claim');
    Route::post('scene-requests/{id}/attachments', 'upload')->where('id', '[1-9][0-9]{0,14}')->middleware('throttle:write')->name('shop.api.scene-requests.upload');
    Route::get('scene-requests/{id}/attachments/{attachmentId}', 'attachment')->where(['id' => '[1-9][0-9]{0,14}', 'attachmentId' => '[1-9][0-9]{0,14}'])->name('shop.api.scene-requests.attachment');
    Route::post('scene-quotes/{id}/accept', 'accept')->where('id', '[1-9][0-9]{0,14}')->middleware('throttle:write')->name('shop.api.scene-quotes.accept');
    Route::post('scene-quotes/{id}/reject', 'rejectQuote')->where('id', '[1-9][0-9]{0,14}')->middleware('throttle:write')->name('shop.api.scene-quotes.reject');
    Route::post('scene-quotes/{id}/convert', 'convert')->where('id', '[1-9][0-9]{0,14}')->middleware('throttle:write')->name('shop.api.scene-quotes.convert');
    Route::get('scene-projects/{id}', 'project')->where('id', '[1-9][0-9]{0,14}')->name('shop.api.scene-projects.show');
    Route::post('scene-projects/{id}/{action}', 'projectAction')->where('id', '[1-9][0-9]{0,14}')->whereIn('action', ['acceptance', 'change', 'cancel', 'after_sale'])->middleware('throttle:write')->name('shop.api.scene-projects.action');
});
