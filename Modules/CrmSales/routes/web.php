<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmSales\Http\Controllers\SalesDashboardApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/pilotage-commercial', 'crm')
    ->middleware(['auth', 'hub.module:pilotage-commercial,sales.view,sales.sync,sales.manage,sales.commissions'])
    ->name('crm.sales-dashboard');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/pilotage-commercial', SalesDashboardApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:pilotage-commercial'])
    ->name('crm.api.sales-dashboard');

Route::match(['GET', 'POST'], '/api/mobile/pilotage-commercial', SalesDashboardApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:pilotage-commercial'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.sales-dashboard');
