<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmLeaves\Http\Controllers\LeaveApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/conges', 'crm')
    ->middleware(['auth', 'hub.module:conges,conges.view,conges.manage'])
    ->name('crm.conges');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/conges', LeaveApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:conges'])
    ->name('crm.api.conges');

Route::match(['GET', 'POST'], '/api/mobile/conges', LeaveApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:conges'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.conges');
