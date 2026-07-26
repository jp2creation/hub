<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmAdministration\Http\Controllers\AdministrationApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/administration/{section?}', 'crm')
    ->middleware(['auth', 'hub.module:administration,platform.manage_users,platform.manage_modules,platform.manage_sites,platform.manage_roles,pages.manage'])
    ->where('section', '.*')
    ->name('crm.administration');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/administration', AdministrationApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:administration'])
    ->name('crm.api.administration');

Route::match(['GET', 'POST'], '/api/mobile/administration', AdministrationApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:administration'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.administration');
