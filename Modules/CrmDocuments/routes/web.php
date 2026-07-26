<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmDocuments\Http\Controllers\DocumentApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::redirect('/documents', '/documents/promo')
    ->middleware(['auth', 'hub.module:documents,documents.view,documents.manage'])
    ->name('crm.documents.index');

Route::view('/documents/{category}', 'crm')
    ->middleware(['auth', 'hub.module:documents,documents.view,documents.manage'])
    ->where('category', 'promo|fiches-techniques|procedures')
    ->name('crm.documents.category');

Route::get('/documents/file/{document}', [DocumentApiController::class, 'download'])
    ->middleware(['auth', 'hub.module:documents,documents.view,documents.manage'])
    ->whereNumber('document')
    ->name('crm.documents.download');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/documents', DocumentApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:documents'])
    ->name('crm.api.documents');

Route::match(['GET', 'POST'], '/api/mobile/documents', DocumentApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:documents'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.documents');
