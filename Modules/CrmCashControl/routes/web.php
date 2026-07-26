<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmCashControl\Http\Controllers\CashControlApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/controle-caisse', 'crm')
    ->middleware(['auth', 'hub.module:controle-caisse,controle_caisse.view,controle_caisse.manage'])
    ->name('crm.controle-caisse');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/controle-caisse', CashControlApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:controle-caisse'])
    ->name('crm.api.controle-caisse');

Route::match(['GET', 'POST'], '/api/mobile/controle-caisse', CashControlApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:controle-caisse'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.controle-caisse');
