<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmTeams\Http\Controllers\TeamApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/equipes', 'crm')
    ->middleware(['auth', 'hub.module:equipes,teams.view'])
    ->name('crm.equipes');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/equipes', TeamApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:equipes'])
    ->name('crm.api.equipes');

Route::match(['GET', 'POST'], '/api/mobile/equipes', TeamApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:equipes'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.equipes');
