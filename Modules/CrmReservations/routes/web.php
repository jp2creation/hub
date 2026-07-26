<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmReservations\Http\Controllers\ReservationApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/reservations', 'crm')
    ->middleware(['auth', 'hub.module:reservations,reservations.view,reservations.create,reservations.manage_vehicles'])
    ->name('crm.reservations');

Route::view('/reservations/{reservationPath}', 'crm')
    ->where('reservationPath', '.*')
    ->middleware(['auth', 'hub.module:reservations,reservations.view,reservations.create,reservations.manage_vehicles'])
    ->name('crm.reservations.deep-link');

Route::get('/api/reservations/bootstrap', ReservationApiController::class)
    ->defaults('crm_action', 'bootstrap_light')
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->name('crm.api.reservations.bootstrap');

Route::get('/api/reservations/users', ReservationApiController::class)
    ->defaults('crm_action', 'users')
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->name('crm.api.reservations.users');

Route::get('/api/reservations/vehicles', ReservationApiController::class)
    ->defaults('crm_action', 'vehicles')
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->name('crm.api.reservations.vehicles');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/reservations', ReservationApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->name('crm.api.reservations');

Route::get('/api/mobile/reservations/bootstrap', ReservationApiController::class)
    ->defaults('crm_action', 'bootstrap_light')
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.reservations.bootstrap');

Route::get('/api/mobile/reservations/users', ReservationApiController::class)
    ->defaults('crm_action', 'users')
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.reservations.users');

Route::get('/api/mobile/reservations/vehicles', ReservationApiController::class)
    ->defaults('crm_action', 'vehicles')
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.reservations.vehicles');

Route::match(['GET', 'POST'], '/api/mobile/reservations', ReservationApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:reservations'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.reservations');
