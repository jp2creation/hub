<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmCheckRemittances\Http\Controllers\CheckRemittanceApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/remise-cheques', 'crm')
    ->middleware(['auth', 'hub.module:remise-cheques,check_remittances.view,check_remittances.manage'])
    ->name('crm.remise-cheques');

Route::view('/remise-cheques/{remittance}', 'crm')
    ->middleware(['auth', 'hub.module:remise-cheques,check_remittances.view,check_remittances.manage'])
    ->whereNumber('remittance')
    ->name('crm.remise-cheques.show');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/remise-cheques', CheckRemittanceApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:remise-cheques'])
    ->name('crm.api.remise-cheques');

Route::match(['GET', 'POST'], '/api/mobile/remise-cheques', CheckRemittanceApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:remise-cheques'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.remise-cheques');
