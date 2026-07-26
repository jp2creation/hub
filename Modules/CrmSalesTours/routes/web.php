<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmSalesTours\Http\Controllers\SalesTourApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/rapport-visite', 'crm')
    ->middleware(['auth', 'hub.module:tournees-representants,sales_tours.view,sales_tours.create,sales_tours.report,sales_tours.manage'])
    ->name('crm.visit-report');

Route::view('/tournees-representants', 'crm')
    ->middleware(['auth', 'hub.module:tournees-representants,sales_tours.view,sales_tours.create,sales_tours.report,sales_tours.manage'])
    ->name('crm.sales-tours');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/tournees-representants', SalesTourApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:tournees-representants'])
    ->name('crm.api.sales-tours');

Route::match(['GET', 'POST'], '/api/mobile/tournees-representants', SalesTourApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:tournees-representants'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.tournees-representants');
