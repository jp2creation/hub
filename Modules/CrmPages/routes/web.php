<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmPages\Http\Controllers\PageApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/pages-crm', 'crm')
    ->middleware(['auth', 'hub.module:pages-crm,pages.view,pages.manage'])
    ->name('crm.pages');

Route::view('/pages-crm/{slug}', 'crm')
    ->middleware(['auth', 'hub.module:pages-crm,pages.view,pages.manage'])
    ->where('slug', '[A-Za-z0-9_-]+')
    ->name('crm.pages.show');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/pages', PageApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:pages-crm'])
    ->name('crm.api.pages');

Route::match(['GET', 'POST'], '/api/mobile/pages', PageApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:pages-crm'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.pages');
