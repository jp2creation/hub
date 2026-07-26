<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmEquipmentRentals\Http\Controllers\EquipmentRentalApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/locations-materiel', 'crm')
    ->middleware(['auth', 'hub.module:locations-materiel,equipment_rentals.view,equipment_rentals.create,equipment_rentals.manage_items'])
    ->name('crm.locations-materiel');

Route::view('/locations-materiel/{rentalPath}', 'crm')
    ->where('rentalPath', '.*')
    ->middleware(['auth', 'hub.module:locations-materiel,equipment_rentals.view,equipment_rentals.create,equipment_rentals.manage_items'])
    ->name('crm.locations-materiel.deep-link');

Route::get('/api/equipment-rentals/bootstrap', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'bootstrap_light')
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->name('crm.api.equipment-rentals.bootstrap');

Route::get('/api/equipment-rentals/users', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'users')
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->name('crm.api.equipment-rentals.users');

Route::get('/api/equipment-rentals/items', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'equipment_items')
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->name('crm.api.equipment-rentals.items');

Route::get('/api/equipment-rentals/categories', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'equipment_categories')
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->name('crm.api.equipment-rentals.categories');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/equipment-rentals', EquipmentRentalApiController::class)
    ->middleware([...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->name('crm.api.equipment-rentals');

Route::get('/api/mobile/equipment-rentals/bootstrap', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'bootstrap_light')
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.equipment-rentals.bootstrap');

Route::get('/api/mobile/equipment-rentals/users', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'users')
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.equipment-rentals.users');

Route::get('/api/mobile/equipment-rentals/items', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'equipment_items')
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.equipment-rentals.items');

Route::get('/api/mobile/equipment-rentals/categories', EquipmentRentalApiController::class)
    ->defaults('crm_action', 'equipment_categories')
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.equipment-rentals.categories');

Route::match(['GET', 'POST'], '/api/mobile/equipment-rentals', EquipmentRentalApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'hub.mobile_scope:hub:module:locations-materiel'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.equipment-rentals');
