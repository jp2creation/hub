<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmDepositRequests\Http\Controllers\DepositRequestApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/demandes-acompte', 'crm')
    ->middleware(['auth', 'hub.module:demandes-acompte,deposit_requests.view,deposit_requests.create,deposit_requests.manage,deposit_requests.validate'])
    ->name('crm.deposit-requests');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/demandes-acompte', DepositRequestApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:demandes-acompte'])
    ->name('crm.api.deposit-requests');

Route::match(['GET', 'POST'], '/api/mobile/demandes-acompte', DepositRequestApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:demandes-acompte'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.demandes-acompte');
